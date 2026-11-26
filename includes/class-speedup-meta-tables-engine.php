<?php
/**
 * The horizontal table engine: builds the tables, answers reads from them and keeps them fresh.
 *
 * @package SpeedUp_Meta_Tables
 */

defined( 'ABSPATH' ) || exit;

/**
 * One table per object kind and type list (speedup_meta_table_N), with one column per meta key and cast that the
 * observed queries use. Keys with several rows per object go to a value table next to it.
 *
 * Every method that writes SQL uses identifiers from the registry the engine built itself and escapes every value
 * with the connection's real_escape_string.
 */
class SpeedUp_Meta_Tables_Engine {

	const TYPES = array(
		''         => 'varchar',
		'SIGNED'   => 'bigint',
		'+0'       => 'double',
		'DATE'     => 'date',
		'DATETIME' => 'datetime',
		'UNSIGNED' => 'bigint unsigned',
	);

	/**
	 * Date column per object kind.
	 */
	const DATE_COL = array(
		'post'  => 'post_date',
		'user'  => 'user_login',
		'order' => 'date_created_gmt',
	);

	/**
	 * Status column per object kind.
	 */
	const STATUS_COL = array(
		'post'  => 'post_status',
		'user'  => null,
		'order' => 'status',
	);

	/**
	 * The definitions of the tables in use, keyed by table number.
	 *
	 * @var array
	 */
	public $reg = array();

	/**
	 * Registry state: "on", "building" or "off".
	 *
	 * @var string
	 */
	public $state = 'off';

	/**
	 * Structure version; it changes whenever the registry does.
	 *
	 * @var int
	 */
	public $ver = 0;

	/**
	 * The statement that answers the next SELECT FOUND_ROWS(), or null.
	 *
	 * @var string|null
	 */
	public $found = null;

	/**
	 * Number of statements answered in this request.
	 *
	 * @var int
	 */
	public $served = 0;

	/**
	 * Why the last statement was not answered.
	 *
	 * @var string
	 */
	public $why = '';

	/**
	 * Table names answered from in this request.
	 *
	 * @var array
	 */
	public $used = array();

	/**
	 * Object kinds read in the current statement (for the pattern cache).
	 *
	 * @var array
	 */
	public $touched = array();

	/**
	 * Set while compiling when the result must not be cached as a pattern.
	 *
	 * @var bool
	 */
	public $nocache = false;

	/**
	 * Objects refreshed in this request.
	 *
	 * @var int
	 */
	public $refreshed = 0;

	/**
	 * Refreshes given up because another connection held the rows.
	 *
	 * @var int
	 */
	public $busy = 0;

	/**
	 * The connection statements run on (the site's own, or the side connection inside side()).
	 *
	 * @var mysqli
	 */
	public $m;

	/**
	 * WordPress table prefix.
	 *
	 * @var string
	 */
	public $wp;

	/**
	 * Prefix of the plugin's tables.
	 *
	 * @var string
	 */
	public $pre;

	/**
	 * Options: "connect" opens the side connection.
	 *
	 * @var array
	 */
	private $opt;

	/**
	 * Object kinds checked for freshness in this request.
	 *
	 * @var array
	 */
	private $fr = array();

	/**
	 * Whether the main query needs the main table joined.
	 *
	 * @var bool
	 */
	private $needp = false;

	/**
	 * Page statement whose FOUND_ROWS() is kept for the count that follows.
	 *
	 * @var string|null
	 */
	private $pend = null;

	/**
	 * Counts kept from FOUND_ROWS() in this request.
	 *
	 * @var array
	 */
	private $cnt = array();

	/**
	 * Compiled patterns: md5 of the SQL with its literals replaced => [statement, count statement, object kinds].
	 *
	 * @var array|null
	 */
	private $tpl = null;

	/**
	 * Whether the pattern cache got new entries.
	 *
	 * @var bool
	 */
	private $tnew = false;

	/**
	 * Literals that never change in the observed queries.
	 *
	 * @var array|null
	 */
	private $consts = null;

	/**
	 * Set when a refresh changed the registry.
	 *
	 * @var bool
	 */
	private $changed = false;

	/**
	 * The side connection.
	 *
	 * @var mysqli|null
	 */
	private $m2 = null;

	/**
	 * Creates an engine on a connection.
	 *
	 * @param mysqli $m      The site's connection.
	 * @param string $prefix WordPress table prefix.
	 * @param array  $opt    Options.
	 */
	public function __construct( $m, string $prefix, array $opt = array() ) {
		$this->m   = $m;
		$this->wp  = $prefix;
		$this->pre = $prefix . 'speedup_meta_';
		$this->opt = $opt;
		if ( ! SpeedUp_Meta_Tables_Sql::$ent ) {
			SpeedUp_Meta_Tables_Sql::init( $prefix );
		}
	}

	/* ---- registry ---- */

	/**
	 * Loads the registry from the option, or straight from the database when $direct is set.
	 *
	 * @param bool $direct Bypass the option cache.
	 * @return self
	 */
	public function load( bool $direct = false ): self {
		$r = null;
		if ( $direct ) {
			$x = $this->m->query( 'SELECT option_value FROM ' . $this->wp . "options WHERE option_name='" . SpeedUp_Meta_Tables::REGISTRY . "'" );
			$x = $x ? $x->fetch_row() : null;
			$r = $x ? maybe_unserialize( $x[0] ) : null;
		} else {
			$r = get_option( SpeedUp_Meta_Tables::REGISTRY );
		}
		$r            = is_array( $r ) ? $r : array();
		$this->reg    = $r['reg'] ?? array();
		$this->ver    = (int) ( $r['v'] ?? 0 );
		$this->state  = $r['state'] ?? 'off';
		$this->consts = null;
		$this->tpl    = null;
		if ( 'on' !== $this->state ) {
			$this->reg = array();
		}
		return $this;
	}

	/**
	 * Writes the registry with a new version, on the current connection, and returns the version.
	 *
	 * @param array  $reg   Definitions.
	 * @param string $state Registry state.
	 * @return int
	 */
	public function publish( array $reg, string $state ): int {
		$v = 1 + (int) ( $this->rows( 'SELECT seq FROM ' . $this->pre . "queue WHERE ent='_v' AND object_id=1" )[0][0] ?? 0 );
		$s = maybe_serialize(
			array(
				'v'     => $v,
				'state' => $state,
				'reg'   => $reg,
			)
		);
		$this->rows( 'INSERT INTO ' . $this->wp . "options (option_name, option_value, autoload) VALUES ('" . SpeedUp_Meta_Tables::REGISTRY . "', " . $this->e( $s ) . ", 'yes') ON DUPLICATE KEY UPDATE option_value=VALUES(option_value)" );
		$this->rows( 'INSERT INTO ' . $this->pre . "queue VALUES ('_v', 1, $v) ON DUPLICATE KEY UPDATE seq=$v" );
		wp_cache_delete( 'alloptions', 'options' );
		wp_cache_delete( SpeedUp_Meta_Tables::REGISTRY, 'options' );
		$none = wp_cache_get( 'notoptions', 'options' );
		if ( is_array( $none ) && isset( $none[ SpeedUp_Meta_Tables::REGISTRY ] ) ) {
			unset( $none[ SpeedUp_Meta_Tables::REGISTRY ] );
			wp_cache_set( 'notoptions', $none, 'options' );
		}
		$this->reg   = 'on' === $state ? $reg : array();
		$this->ver   = $v;
		$this->state = $state;
		$this->tpl   = null;
		return $v;
	}

	/**
	 * Escapes a value as a quoted SQL string.
	 *
	 * @param mixed $s Value.
	 * @return string
	 */
	public function e( $s ) {
		return "'" . $this->m->real_escape_string( (string) $s ) . "'";
	}

	/**
	 * Runs a statement and returns its rows as lists.
	 *
	 * @param string $q SQL.
	 * @return array
	 * @throws RuntimeException When the statement fails.
	 */
	public function rows( string $q ): array {
		$r = $this->m->query( $q );
		if ( ! $r ) {
			throw new RuntimeException( esc_html( $this->m->error . ': ' . substr( $q, 0, 300 ) ) );
		}
		return true === $r ? array() : $r->fetch_all();
	}

	/**
	 * The object kind with its main table name.
	 *
	 * @param string $ent Object kind.
	 * @return array
	 */
	public function def( string $ent ) {
		foreach ( SpeedUp_Meta_Tables_Sql::$ent as $p => $d ) {
			if ( $d['ent'] === $ent ) {
				return array( 'prim' => $p ) + $d;
			}
		}
		return array();
	}

	/**
	 * Whether a table exists.
	 *
	 * @param string $t Table name.
	 * @return bool
	 */
	public function has_table( string $t ): bool {
		return (bool) $this->rows( 'SHOW TABLES LIKE ' . $this->e( addcslashes( $t, '_%\\' ) ) );
	}

	/* ---- building ---- */

	/**
	 * Builds the tables for a list of observed query patterns and returns their definitions.
	 *
	 * @param array $shapes Parsed queries.
	 * @return array
	 */
	public function build( array $shapes ): array {
		$this->fill_keys();
		$this->reg = array();
		$g         = array();
		foreach ( $shapes as $q ) {
			$t = SpeedUp_Meta_Tables_Sql::types( $q );
			if ( null === $t ) {
				continue;
			}
			sort( $t );
			$g[ $q['ent'] . '|' . implode( ',', $t ) ][] = $q;
		}
		foreach ( $g as $name => $qs ) {
			list( $ent, $t ) = explode( '|', $name );
			if ( ! $this->has_table( $this->def( $ent )['meta'] ) ) {
				continue;
			}
			$this->make( $ent, '' === $t ? array() : explode( ',', $t ), $qs );
		}
		return $this->reg;
	}

	/**
	 * Fills the list of distinct meta keys (it answers the custom field box).
	 */
	public function fill_keys() {
		foreach ( array( $this->wp . 'postmeta', $this->wp . 'wc_orders_meta' ) as $t ) {
			if ( $this->has_table( $t ) ) {
				$this->rows( 'INSERT IGNORE INTO ' . $this->pre . "keys SELECT '$t', meta_key FROM $t WHERE meta_key IS NOT NULL GROUP BY meta_key" );
			}
		}
	}

	/**
	 * SQL of a cast.
	 *
	 * @param string $c Cast.
	 * @param string $x Expression.
	 * @return string
	 */
	public function castsql( string $c, string $x ) {
		if ( '' === $c ) {
			return $x;
		}
		return '+0' === $c ? "$x+0" : "CAST($x AS $c)";
	}

	/**
	 * Column type of a cast.
	 *
	 * @param string $c   Cast.
	 * @param int    $len Longest value.
	 * @return string
	 */
	public function coltype( string $c, int $len ) {
		if ( '' === $c ) {
			return $len > 700 ? 'text' : 'varchar(' . max( 8, min( 700, $len + 16 ) ) . ')';
		}
		return self::TYPES[ $c ] ?? strtolower( $c );
	}

	/**
	 * Column name suffix of a cast.
	 *
	 * @param string $c Cast.
	 * @return string
	 */
	public static function suffix( string $c ) {
		if ( '' === $c ) {
			return '';
		}
		return '_' . ( '+0' === $c ? 'num' : strtolower( preg_replace( '/\W+/', '', $c ) ) );
	}

	/**
	 * A quoted list.
	 *
	 * @param array $v Values.
	 * @return string
	 */
	public function inlist( array $v ) {
		return '(' . implode( ',', array_map( array( $this, 'e' ), $v ) ) . ')';
	}

	/**
	 * Whether a post table with these types and keys can carry WooCommerce's stock and sale columns.
	 *
	 * @param string $ent   Object kind.
	 * @param array  $types Types.
	 * @param array  $keys  Key => casts.
	 * @return bool
	 */
	private function stock_columns( string $ent, array $types, array $keys ): bool {
		if ( 'post' !== $ent || array( 'product', 'product_variation' ) !== $types ) {
			return false;
		}
		foreach ( array( '_low_stock_amount', '_price', '_sale_price', '_regular_price' ) as $k ) {
			if ( ! isset( $keys[ $k ][''] ) ) {
				return false;
			}
		}
		foreach ( array( '_sale_price_dates_from', '_sale_price_dates_to' ) as $k ) {
			if ( ! isset( $keys[ $k ][''], $keys[ $k ]['+0'] ) ) {
				return false;
			}
		}
		return $this->has_table( $this->wp . 'wc_product_meta_lookup' );
	}

	/**
	 * Builds one table.
	 *
	 * @param string $ent   Object kind.
	 * @param array  $types Types (empty for users).
	 * @param array  $qs    Parsed queries.
	 */
	public function make( string $ent, array $types, array $qs ) {
		$t0   = microtime( true );
		$d    = $this->def( $ent );
		$keys = array();
		foreach ( $qs as $q ) {
			foreach ( SpeedUp_Meta_Tables_Sql::needs( $q ) as $k => $c ) {
				$keys[ $k ] = ( $keys[ $k ] ?? array() ) + $c;
			}
		}
		if ( ! $keys ) {
			return;
		}
		$tc    = 'post' === $ent ? 'post_type' : 'type';
		$in    = $types ? 'p.' . $tc . ' IN ' . $this->inlist( $types ) : '1';
		$klist = $this->inlist( array_keys( $keys ) );
		$st    = array();
		foreach ( $this->rows( "SELECT m.meta_key, COUNT(*), COUNT(DISTINCT m.{$d['fk']}), MAX(CHAR_LENGTH(m.meta_value)), SUM(m.meta_value IS NULL) FROM {$d['meta']} m JOIN {$d['prim']} p ON p.{$d['id']}=m.{$d['fk']} WHERE m.meta_key IN $klist AND $in GROUP BY m.meta_key" ) as $r ) {
			$st[ $r[0] ] = $r;
		}
		$likes = array();
		$text  = array();
		foreach ( $qs as $q ) {
			SpeedUp_Meta_Tables_Sql::walk(
				$q['where'],
				function ( $n ) use ( &$likes, &$text, $d ) {
					if ( 'meta' === $n[0] && str_contains( $n[3], 'LIKE' ) ) {
						$likes[ $n[1] ] = 1;
					}
					if ( 'raw' === $n[0] && preg_match( '/^' . preg_quote( $d['prim'], '/' ) . "\.(post_title|post_excerpt|post_content) LIKE '/", $n[1], $x ) ) {
						$text[ $x[1] ] = 1;
					}
				}
			);
		}
		$sets = array();
		foreach ( $qs as $q ) {
			if ( ! empty( $q['raw'] ) ) {
				foreach ( $q['sets'] ?? array() as $one ) {
					sort( $one );
					$sets[ implode( ',', $one ) ] = $one;
				}
				continue;
			}
			SpeedUp_Meta_Tables_Sql::walk_all(
				$q['where'],
				function ( $n ) use ( &$sets, $d ) {
					$c = self::likeset( $n, $d['prim'] );
					if ( $c ) {
						$sets[ implode( ',', $c[0] ) ] = $c[0];
					}
				}
			);
		}
		foreach ( $sets as $x ) {
			foreach ( $x as $k ) {
				$likes[ $k ] = 2;
			}
		}
		$out = array();
		foreach ( $this->rows( "SELECT m.meta_key, COUNT(*) FROM {$d['meta']} m JOIN {$d['prim']} p ON p.{$d['id']}=m.{$d['fk']} WHERE m.meta_key IN $klist AND NOT ($in) GROUP BY m.meta_key" ) as $r ) {
			$out[ $r[0] ] = 1;
		}
		$this->rows( 'INSERT INTO ' . $this->pre . "tables (state, definition) VALUES ('building', '')" );
		$id   = (int) $this->m->insert_id;
		$tab  = $this->pre . 'table_' . $id;
		$rel  = $this->stock_columns( $ent, $types, $keys );
		$core = array_values( array_diff( $d['core'], array( $d['id'] ) ) );
		if ( count( $types ) < 2 ) {
			$core = array_values( array_diff( $core, array( $tc ) ) );
		}
		$def  = array(
			'ent'   => $ent,
			'types' => $types,
			'table' => $tab,
			'core'  => $core,
			'keys'  => array(),
			'idx'   => array(),
		);
		$i    = 0;
		$cols = array();
		$sel  = array();
		$vals = array();
		foreach ( $keys as $k => $casts ) {
			$s = $st[ $k ] ?? array( $k, 0, 0, 0, 0 );
			++$i;
			$row = (int) $s[1] === (int) $s[2];
			$nd  = 0;
			if ( isset( $casts[''] ) ) {
				$nd = (int) $this->rows( "SELECT COUNT(DISTINCT m.meta_value) FROM {$d['meta']} m WHERE m.meta_key=" . $this->e( $k ) )[0][0];
			}
			$ki = array(
				'dict'  => $nd > 0 && $nd <= 300 ? array_column( $this->rows( "SELECT DISTINCT m.meta_value FROM {$d['meta']} m WHERE m.meta_key=" . $this->e( $k ) ), 0 ) : null,
				'nd'    => $nd,
				'row'   => $row,
				'n'     => $i,
				'casts' => array(),
				'nulls' => ! $casts || (int) $s[4] > 0,
				'only'  => empty( $out[ $k ] ),
				'len'   => (int) $s[3],
				'cap'   => max( 8, min( 700, (int) $s[3] + 16 ) ),
			);
			if ( ! $ki['nulls'] && ! isset( $casts[''] ) && array_intersect( array_keys( $casts ), array( 'DATE', 'DATETIME' ) ) ) {
				$ki['nulls'] = true;
			}
			foreach ( array_keys( $casts ) as $c ) {
				$ki['casts'][ $c ] = "c$i" . self::suffix( $c );
				if ( $row ) {
					$cols[] = "c$i" . self::suffix( $c ) . ' ' . $this->coltype( $c, (int) $s[3] );
					$sel[]  = 'MAX(CASE WHEN m.meta_key=' . $this->e( $k ) . ' THEN ' . $this->castsql( $c, 'm.meta_value' ) . ' END)';
				}
			}
			if ( $row && $ki['nulls'] ) {
				$cols[]   = "c{$i}_ex tinyint not null default 0";
				$sel[]    = 'COALESCE(MAX(m.meta_key=' . $this->e( $k ) . '),0)';
				$ki['ex'] = "c{$i}_ex";
			}
			if ( $row && isset( $likes[ $k ] ) && ( $nd > 300 || 2 === $likes[ $k ] ) ) {
				$cols[]     = "c{$i}_f varbinary(" . max( 16, min( 1500, 4 * (int) $s[3] + 64 ) ) . ')';
				$sel[]      = 'LOWER(CONVERT(MAX(CASE WHEN m.meta_key=' . $this->e( $k ) . ' THEN m.meta_value END) USING utf8mb4))';
				$ki['fold'] = "c{$i}_f";
			}
			if ( ! $row && isset( $casts[''] ) && (int) $s[3] < 60 ) {
				foreach ( array( 'min', 'max' ) as $mm ) {
					$cols[]    = "c{$i}_$mm varchar(80)";
					$sel[]     = "$mm(CASE WHEN m.meta_key=" . $this->e( $k ) . ' THEN m.meta_value END)';
					$ki[ $mm ] = "c{$i}_$mm";
				}
			}
			if ( ! $row ) {
				$vals[ $k ] = $ki;
			}
			$def['keys'][ $k ] = $ki;
		}
		$def['flags'] = $this->flags( $qs, $def );
		$qs           = array_map( fn( $q ) => $this->flagged( $q, $def ), $qs );
		$ct           = array(
			'post_type'        => 'varchar(20) not null',
			'post_status'      => 'varchar(20) not null',
			'post_date'        => 'datetime not null',
			'user_login'       => 'varchar(60) not null',
			'type'             => 'varchar(20)',
			'status'           => 'varchar(20)',
			'date_created_gmt' => 'datetime',
		);
		$cc           = preg_replace( '/\W/', '', (string) $this->rows( "SELECT collation_name FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='{$d['meta']}' AND column_name='meta_value'" )[0][0] );
		$derived      = array();
		$dreg         = false;
		if ( $rel ) {
			$low       = $def['keys']['_low_stock_amount']['casts'][''];
			$derived[] = "d_low tinyint as (CASE WHEN $low>'' THEN l_qty<=CAST($low AS SIGNED) END) stored";
			$pm        = $def['keys']['_price'];
			if ( isset( $pm['min'] ) ) {
				$rp        = $def['keys']['_regular_price']['casts'][''];
				$sp        = $def['keys']['_sale_price']['casts'][''];
				$derived[] = "d_reg tinyint as (COALESCE({$pm['min']}<>$rp OR {$pm['max']}<>$rp,0)) stored";
				$derived[] = "d_sal tinyint as (COALESCE({$pm['min']}<>$sp OR {$pm['max']}<>$sp,0)) stored";
				$dreg      = true;
			}
			$derived[] = 'd_q tinyint as (COALESCE(l_qty<=2,0)) stored';
		}
		$def['dreg'] = $dreg;
		$this->rows(
			"CREATE TABLE $tab (object_id bigint unsigned primary key," . implode(
				',',
				array_merge(
					array_map( fn( $c ) => "$c {$ct[$c]}", $core ),
					$cols,
					array_map( fn( $c ) => "t_$c varbinary(1500)", array_keys( $text ) ),
					$rel ? array( 'l_qty double', 'l_st varchar(20)' ) : array(),
					$derived,
					array_map( fn( $f ) => "{$f[0]} tinyint as ({$f[1]}) stored", array_values( $def['flags'] ) )
				)
			) . ") ENGINE=InnoDB COLLATE=$cc"
		);
		$names         = array_merge( array( 'object_id' ), $core, array_map( fn( $x ) => explode( ' ', $x )[0], $cols ), array_map( fn( $c ) => "t_$c", array_keys( $text ) ), $rel ? array( 'l_qty', 'l_st' ) : array() );
		$def['insert'] = "INTO $tab (" . implode( ',', $names ) . ") SELECT p.{$d['id']}," . implode(
			',',
			array_merge(
				array_map( fn( $c ) => "p.$c", $core ),
				$sel,
				array_map( fn( $c ) => "LOWER(LEFT(p.$c,350))", array_keys( $text ) ),
				$rel ? array( 'MAX(l.stock_quantity)', 'MAX(l.stock_status)' ) : array()
			)
		) . " FROM {$d['prim']} p " . ( $rel ? 'LEFT JOIN ' . $this->wp . 'wc_product_meta_lookup l ON l.product_id=p.ID ' : '' ) . "LEFT JOIN {$d['meta']} m ON m.{$d['fk']}=p.{$d['id']} AND m.meta_key IN " . $this->inlist( array_keys( $def['keys'] ) ) . " WHERE $in %s GROUP BY p.{$d['id']}";
		$def['text']   = array_keys( $text );
		$def['rel']    = $rel;
		$def['cc']     = $cc;
		$this->rows( 'INSERT ' . sprintf( $def['insert'], '' ) );
		$this->refold( $def, '' );
		$this->make_sets( $def, $sets, $ent );
		$def['sums'] = array();
		foreach ( $qs as $q ) {
			foreach ( $q['sums'] ?? array() as $pair ) {
				list( $by, $of ) = $pair;
				if ( ! empty( $def['keys'][ $by ]['row'] ) && ! empty( $def['keys'][ $of ]['row'] ) && isset( $def['keys'][ $by ]['casts'][''], $def['keys'][ $of ]['casts']['+0'] ) ) {
					$gt = "{$tab}_g" . ( count( $def['sums'] ) + 1 );
					$this->rows( "CREATE TABLE $gt (v varchar(191) not null, st varchar(20) not null, n bigint not null, s decimal(30,6) not null, primary key(v,st)) ENGINE=InnoDB COLLATE=$cc" );
					$def['sums'][ "$by|$of" ] = array( $gt, $def['keys'][ $by ]['casts'][''], $def['keys'][ $of ]['casts']['+0'] );
				}
			}
		}
		$def['multi'] = array();
		foreach ( $qs as $q ) {
			$x = empty( $q['raw'] ) ? $this->discrete( $q, $def ) : null;
			if ( $x ) {
				$def['multi'][ implode( ',', array_keys( $x ) ) ] = array(
					'k'    => 1000 + count( $def['multi'] ),
					'cols' => array_keys( $x ),
				);
			}
		}
		if ( $rel ) {
			$def['multi']['raw:low'] = array(
				'k'    => 2000,
				'cols' => array( 'd_low', 'l_st', 'd_q' ),
			);
		}
		$this->tally( $def, '', 1 );
		if ( $vals ) {
			$this->make_values( $def, $vals, $ent, $in, $cc );
		}
		$this->make_indexes( $def, $qs, $rel, $text );
		$this->reg[ $id ] = $def;
		$this->texts( $def, $qs );
		$this->rows( "ANALYZE TABLE $tab" . ( $vals ? ", {$tab}_v" : '' ) );
		$sz = $this->rows( 'SELECT SUM(data_length+index_length) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name LIKE ' . $this->e( addcslashes( $tab, '_%\\' ) . '%' ) )[0][0];
		$c  = $this->rows( "SELECT COUNT(*) FROM $tab" )[0][0];
		$this->rows( 'UPDATE ' . $this->pre . "tables SET state='ready', definition=" . $this->e( wp_json_encode( $def ) ) . ', row_count=' . (int) $c . ', bytes=' . (int) $sz . ', build_ms=' . (int) ( 1000 * ( microtime( true ) - $t0 ) ) . " WHERE id=$id" );
		$this->reg[ $id ] = $def;
	}

	/**
	 * Builds the narrow search tables of OR-of-LIKE patterns over several keys.
	 *
	 * @param array  $def  Table definition.
	 * @param array  $sets Key lists.
	 * @param string $ent  Object kind.
	 */
	private function make_sets( array &$def, array $sets, string $ent ) {
		$tab = $def['table'];
		$n   = 0;
		foreach ( $sets as $g => $list ) {
			$ok = true;
			foreach ( $list as $k ) {
				$ok = $ok && ( '@' === $k[0] ? in_array( substr( $k, 1 ), $def['text'], true ) : isset( $def['keys'][ $k ]['fold'] ) );
			}
			if ( ! $ok ) {
				continue;
			}
			++$n;
			$sd = array(
				'post'  => array( 'post_date', 'datetime not null', 'post_status' ),
				'user'  => array( 'user_login', 'varchar(60) not null', "''" ),
				'order' => array( 'date_created_gmt', 'datetime', 'status' ),
			)[ $ent ];
			$t  = "{$tab}_s$n";
			$this->rows( "CREATE TABLE $t (object_id bigint unsigned primary key, dt {$sd[1]}, st varchar(20) not null, s mediumblob) ENGINE=InnoDB COLLATE={$def['cc']}" );
			$parts             = array_map( fn( $k ) => '@' === $k[0] ? 'h.t_' . substr( $k, 1 ) : "COALESCE(h.{$def['keys'][$k]['fold']},'')", $list );
			$def['sets'][ $g ] = array(
				't'   => $t,
				'ins' => "INTO $t SELECT h.object_id, h.{$sd[0]}, " . ( "''" === $sd[2] ? "''" : "h.{$sd[2]}" ) . ', CONCAT(' . implode( ",'\n',", $parts ) . ") FROM $tab h WHERE 1 %s",
			);
			$this->rows( 'INSERT ' . sprintf( $def['sets'][ $g ]['ins'], '' ) );
			if ( (float) $this->rows( "SELECT AVG(LENGTH(s)) FROM $t" )[0][0] < 120 ) {
				$this->rows( "DROP TABLE $t" );
				unset( $def['sets'][ $g ] );
			}
		}
	}

	/**
	 * Builds the value table of keys that have several rows per object.
	 *
	 * @param array  $def  Table definition.
	 * @param array  $vals Key => key info.
	 * @param string $ent  Object kind.
	 * @param string $in   Type condition.
	 * @param string $cc   Collation.
	 */
	private function make_values( array &$def, array $vals, string $ent, string $in, string $cc ) {
		$d  = $this->def( $ent );
		$v  = "{$def['table']}_v";
		$cs = array();
		foreach ( $vals as $ki ) {
			foreach ( array_keys( $ki['casts'] ) as $c ) {
				$cs[ $c ] = max( $cs[ $c ] ?? 0, $ki['len'] );
			}
		}
		$this->rows( "CREATE TABLE $v (key_id smallint not null, object_id bigint unsigned not null, meta_id bigint unsigned not null" . implode( '', array_map( fn( $c ) => ', v' . self::suffix( $c ) . ' ' . $this->coltype( $c, $cs[ $c ] ), array_keys( $cs ) ) ) . ', primary key(key_id,object_id,meta_id)' . implode( '', array_map( fn( $c ) => ', key k' . self::suffix( $c ) . '(key_id,v' . self::suffix( $c ) . ( '' === $c && $cs[ $c ] > 190 ? '(190)' : '' ) . ',object_id)', array_keys( $cs ) ) ) . ") ENGINE=InnoDB COLLATE=$cc" );
		$mid            = array(
			'user'  => 'umeta_id',
			'order' => 'id',
			'post'  => 'meta_id',
		)[ $ent ];
		$def['vinsert'] = array();
		foreach ( $vals as $k => $ki ) {
			$def['vinsert'][ $k ] = "INTO $v(key_id,object_id,meta_id" . implode( '', array_map( fn( $c ) => ',v' . self::suffix( $c ), array_keys( $cs ) ) ) . ") SELECT {$ki['n']},m.{$d['fk']},m.$mid" . implode( '', array_map( fn( $c ) => ',' . $this->castsql( $c, 'm.meta_value' ), array_keys( $cs ) ) ) . " FROM {$d['meta']} m JOIN {$d['prim']} p ON p.{$d['id']}=m.{$d['fk']} WHERE $in AND m.meta_key=" . $this->e( $k ) . ' %s';
			$this->rows( 'INSERT ' . sprintf( $def['vinsert'][ $k ], '' ) );
			$def['keys'][ $k ]['vt'] = $v;
		}
	}

	/**
	 * Adds the indexes the patterns need.
	 *
	 * @param array $def  Table definition.
	 * @param array $qs   Parsed queries.
	 * @param bool  $rel  Whether the stock columns exist.
	 * @param array $text Text columns.
	 */
	private function make_indexes( array &$def, array $qs, bool $rel, array $text ) {
		$idx = array();
		$own = array();
		foreach ( $qs as $q ) {
			foreach ( $this->indexes( $q, $def ) as $x ) {
				$own[ $this->sig( $q ) ] = $x;
			}
			if ( ! empty( $q['raw'] ) ) {
				foreach ( SpeedUp_Meta_Tables_Sql::needs( $q ) as $k => $c ) {
					if ( $def['keys'][ $k ]['row'] ) {
						foreach ( $def['keys'][ $k ]['casts'] as $cs => $cn ) {
							if ( '' !== $cs || $def['keys'][ $k ]['len'] < 60 ) {
								$idx[ $cn ] = array( $cn );
							}
						}
					}
				}
				if ( 'post' !== $q['ent'] || count( SpeedUp_Meta_Tables_Sql::needs( $q ) ) > 5 ) {
					continue;
				}
			}
			foreach ( $this->indexes( $q, $def ) as $x ) {
				$idx[ implode( ',', $x ) ] = $x;
			}
		}
		if ( $rel ) {
			$k   = $def['keys'];
			$low = $k['_low_stock_amount']['casts'][''];
			if ( $def['dreg'] ) {
				$idx['low']    = array( 'd_low', 'post_status', 'l_st', 'l_qty', $low );
				$idx['dsale']  = array( 'd_reg', $k['_sale_price_dates_to']['casts']['+0'], $k['_sale_price_dates_to']['casts'][''] );
				$idx['dsale2'] = array( 'd_sal', $k['_sale_price_dates_from']['casts']['+0'], $k['_sale_price_dates_from']['casts'][''] );
				$idx['sales']  = array( $k['_sale_price_dates_to']['casts']['+0'], $k['_sale_price_dates_to']['casts'][''], $k['_regular_price']['casts'][''], $k['_price']['min'], $k['_price']['max'] );
			} else {
				$idx['low'] = array( 'd_low', 'post_status', 'l_st', 'l_qty', $low );
			}
			$idx['rel']  = array( 'l_qty', 'l_st', $low, 'post_status' );
			$idx['rel2'] = array( $low, 'l_qty', 'l_st', 'post_status' );
		}
		foreach ( $qs as $q ) {
			$o0 = $q['order'][0] ?? null;
			$x  = $own[ $this->sig( $q ) ] ?? null;
			if ( empty( $q['raw'] ) && $o0 && 'core' === $o0[0] && $x && in_array( $o0[1], $x, true ) && $o0[1] !== $x[0] ) {
				$di                            = array_values( array_unique( array_merge( array( $o0[1] ), $x ) ) );
				$idx[ implode( ',', $di ) ]    = $di;
				$own[ 'd' . $this->sig( $q ) ] = $di;
			}
		}
		$list = array_keys( $idx );
		foreach ( $list as $a ) {
			foreach ( $list as $b ) {
				if ( $a !== $b && str_starts_with( "$b,", "$a," ) ) {
					unset( $idx[ $a ] );
				}
			}
		}
		$n = 0;
		foreach ( $idx as $x ) {
			if ( ++$n > 40 ) {
				break;
			}
			$def['idx'][ "i$n" ] = $x;
		}
		foreach ( $own as $g => $x ) {
			foreach ( $def['idx'] as $name => $y ) {
				if ( str_starts_with( implode( ',', $y ) . ',', implode( ',', $x ) . ',' ) ) {
					$def['own'][ $g ] = $name;
					break;
				}
			}
		}
		if ( $def['idx'] ) {
			$add = array();
			foreach ( $def['idx'] as $name => $x ) {
				$add[] = "ADD KEY $name (" . implode( ',', array_map( fn( $c ) => $this->ixcol( $c, $def ), $x ) ) . ')';
			}
			$this->rows( "ALTER TABLE {$def['table']} " . implode( ',', $add ) );
		}
		if ( $text ) {
			$def['tx'] = 1;
		}
	}

	/**
	 * The key of an "or" node of "missing" and "equals empty" on one key, or null.
	 *
	 * @param array $n Node.
	 * @return string|null
	 */
	public static function blankkey( array $n ): ?string {
		if ( 'or' !== $n[0] || 2 !== count( $n[1] ) ) {
			return null;
		}
		$a = $n[1][0];
		$b = $n[1][1];
		if ( 'noex' !== $a[0] ) {
			list( $a, $b ) = array( $b, $a );
		}
		return 'noex' === $a[0] && 'meta' === $b[0] && $a[1] === $b[1] && '' === $b[2] && '=' === $b[3] && array( '' ) === $b[4] && empty( $b[5] ) ? $a[1] : null;
	}

	/**
	 * The "key|cast|operator|value" text of a single comparison, or null.
	 *
	 * @param array $n Node.
	 * @return string|null
	 */
	public static function predkey( array $n ): ?string {
		return 'meta' === $n[0] && empty( $n[5] ) && in_array( $n[3], array( '<', '>', '<=', '>=', '!=' ), true ) ? "$n[1]|$n[2]|$n[3]|{$n[4][0]}" : null;
	}

	/**
	 * Flag columns a set of patterns needs: name => [column, expression].
	 *
	 * @param array $qs  Parsed queries.
	 * @param array $def Table definition.
	 * @return array
	 */
	public function flags( array $qs, array $def ): array {
		$f = array();
		$i = 0;
		foreach ( $qs as $q ) {
			foreach ( SpeedUp_Meta_Tables_Sql::conj( $q['where'] ) as $n ) {
				$b = self::blankkey( $n );
				if ( null !== $b && ! empty( $def['keys'][ $b ]['row'] ) ) {
					$k               = $def['keys'][ $b ];
					$c               = $k['casts'][''];
					$f[ "blank|$b" ] = array( "c{$k['n']}_b", isset( $k['ex'] ) ? "IF({$k['ex']}=0,1,COALESCE($c='',0))" : "COALESCE($c='',1)" );
				}
				$p = self::predkey( $n );
				if ( null !== $p && in_array( $p, $q['const'] ?? array(), true ) && ! empty( $def['keys'][ $n[1] ]['row'] ) && ! isset( $f[ "pred|$p" ] ) ) {
					$k = $def['keys'][ $n[1] ];
					++$i;
					$f[ "pred|$p" ] = array( "c{$k['n']}_p" . $i, 'COALESCE(' . $this->cond( $k['casts'][ $n[2] ], $n[3], $n[4] ) . ',0)' );
				}
			}
		}
		return $f;
	}

	/**
	 * Replaces conditions that have a flag column.
	 *
	 * @param array $q Parsed query.
	 * @param array $d Table definition.
	 * @return array
	 */
	public function flagged( array $q, array $d ): array {
		if ( empty( $d['flags'] ) ) {
			return $q;
		}
		$l = SpeedUp_Meta_Tables_Sql::conj( $q['where'] );
		foreach ( $l as $i => $n ) {
			$b = self::blankkey( $n );
			if ( null !== $b && isset( $d['flags'][ "blank|$b" ] ) ) {
				$l[ $i ] = array( 'flag', $d['flags'][ "blank|$b" ][0], $b );
				continue;
			}
			$p = self::predkey( $n );
			if ( null !== $p && isset( $d['flags'][ "pred|$p" ] ) ) {
				$l[ $i ] = array( 'flag', $d['flags'][ "pred|$p" ][0], $n[1] );
			}
		}
		$q['where'] = 1 === count( $l ) ? $l[0] : array( 'and', $l );
		return $q;
	}

	/**
	 * A signature of a pattern: its condition tree and order without literals.
	 *
	 * @param array $q Parsed query.
	 * @return string
	 */
	public function sig( array $q ) {
		$f = function ( $n ) use ( &$f ) {
			if ( 'and' === $n[0] || 'or' === $n[0] ) {
				return $n[0] . '(' . implode( ';', array_map( $f, $n[1] ) ) . ')';
			}
			if ( 'meta' === $n[0] ) {
				return "m:$n[1]:$n[2]:$n[3]";
			}
			return $n[0] . ':' . ( 'raw' === $n[0] ? '' : $n[1] ?? '' );
		};
		return md5( $f( $q['where'] ) . '|' . implode( ',', array_map( fn( $o ) => "$o[0]:$o[1]:$o[2]:$o[3]", $q['order'] ) ) );
	}

	/**
	 * Values with characters outside ASCII are folded in PHP.
	 *
	 * @param array  $d   Table definition.
	 * @param string $cond Extra condition.
	 */
	public function refold( array $d, string $cond ) {
		foreach ( $d['keys'] as $k ) {
			if ( ! isset( $k['fold'] ) ) {
				continue;
			}
			foreach ( $this->rows( "SELECT object_id, {$k['casts']['']} FROM {$d['table']} WHERE NOT REGEXP_LIKE({$k['casts']['']},'^[ -~]*$','c') $cond" ) as $r ) {
				$this->rows( "UPDATE {$d['table']} SET {$k['fold']}=" . $this->e( SpeedUp_Meta_Tables_Fold::fold( $this->m, $d['cc'], (string) $r[1] ) ) . ' WHERE object_id=' . (int) $r[0] );
			}
		}
		if ( empty( $d['text'] ) ) {
			return;
		}
		$x = $this->def( $d['ent'] );
		foreach ( $d['text'] as $c ) {
			$this->rows( "UPDATE {$d['table']} h JOIN {$x['prim']} p ON p.{$x['id']}=h.object_id SET h.t_$c=NULL WHERE CHAR_LENGTH(p.$c)>350 $cond" );
			foreach ( $this->rows( "SELECT h.object_id, p.$c FROM {$d['table']} h JOIN {$x['prim']} p ON p.{$x['id']}=h.object_id WHERE CHAR_LENGTH(p.$c)<=350 AND NOT REGEXP_LIKE(p.$c,'^[ -~]*$','c') $cond" ) as $r ) {
				$this->rows( "UPDATE {$d['table']} SET t_$c=" . $this->e( SpeedUp_Meta_Tables_Fold::fold( $this->m, $d['cc'], (string) $r[1] ) ) . ' WHERE object_id=' . (int) $r[0] );
			}
		}
	}

	/**
	 * A folded LIKE pattern, or null when the pattern has inner wildcards.
	 *
	 * @param array  $d Table definition.
	 * @param string $p Pattern.
	 * @return string|null
	 */
	public function pat( array $d, string $p ): ?string {
		$a = str_starts_with( $p, '%' );
		$x = $a ? substr( $p, 1 ) : $p;
		$z = str_ends_with( $x, '%' ) && ! str_ends_with( $x, '\\%' );
		if ( $z ) {
			$x = substr( $x, 0, -1 );
		}
		if ( preg_match( '/(?<!\\\\)[%_]/', $x ) ) {
			return null;
		}
		$x = SpeedUp_Meta_Tables_Fold::fold( $this->m, $d['cc'], preg_replace( '/\\\\([%_\\\\])/', '$1', $x ) );
		return ( $a ? '%' : '' ) . addcslashes( $x, '%_\\' ) . ( $z ? '%' : '' );
	}

	/**
	 * Keys that get counters: key => value column for few-valued keys, null for presence counts.
	 *
	 * @param array $d Table definition.
	 * @return array
	 */
	public function counted( array $d ): array {
		$o = array();
		foreach ( $d['keys'] as $k => $x ) {
			if ( $x['row'] ) {
				$o[ $k ] = ! empty( $x['dict'] ) && isset( $x['casts'][''] ) && $x['len'] < 180 ? $x['casts'][''] : null;
			}
		}
		return $o;
	}

	/**
	 * Adds ($sign 1) or removes ($sign -1) the rows matching $cond from the counters and totals.
	 *
	 * @param array  $d    Table definition.
	 * @param string $cond  Extra condition.
	 * @param int    $sign 1 or -1.
	 */
	public function tally( array $d, string $cond, int $sign ) {
		$st = array(
			'post'  => 'post_status',
			'user'  => "''",
			'order' => 'status',
		)[ $d['ent'] ];
		$ty = in_array( 'post_type', $d['core'], true ) ? 'post_type' : "''";
		$u  = array();
		foreach ( $this->counted( $d ) as $k => $c ) {
			$x    = $d['keys'][ $k ];
			$miss = isset( $x['ex'] ) ? "{$x['ex']}=0" : reset( $x['casts'] ) . ' IS NULL';
			$u[]  = "SELECT '{$d['table']}', {$x['n']}, IF($miss,1,0) m, " . ( $c ? "COALESCE($c,'')" : "''" ) . " v, $st s, $ty t, $sign*COUNT(*) FROM {$d['table']} WHERE 1 $cond GROUP BY m, v, s, t";
		}
		foreach ( $d['multi'] ?? array() as $x ) {
			$u[] = "SELECT '{$d['table']}', {$x['k']}, 0 m, CONCAT_WS('|'," . implode( ',', array_map( fn( $c ) => str_ends_with( $c, '_ex' ) ? $c : "COALESCE($c,'~')", $x['cols'] ) ) . ") v, $st s, $ty t, $sign*COUNT(*) FROM {$d['table']} WHERE 1 $cond GROUP BY m, v, s, t";
		}
		foreach ( $d['sums'] ?? array() as $g ) {
			list( $gt, $by, $of ) = $g;
			$this->rows( "INSERT INTO $gt(v,st,n,s) SELECT * FROM (SELECT $by v, $st s, $sign*COUNT(*) n, $sign*SUM(CAST($of AS DECIMAL(24,6))) t FROM {$d['table']} WHERE $by IS NOT NULL AND $of IS NOT NULL $cond GROUP BY 1,2) z ON DUPLICATE KEY UPDATE n=$gt.n+VALUES(n), s=$gt.s+VALUES(s)" );
		}
		if ( $u ) {
			$this->rows( 'INSERT INTO ' . $this->pre . 'counts(tab,k,miss,val,st,ty,cnt) SELECT * FROM (' . implode( ' UNION ALL ', $u ) . ') z ON DUPLICATE KEY UPDATE cnt=cnt+VALUES(cnt)' );
		}
	}

	/**
	 * Discrete columns of a pattern, when every meta condition is on a few-valued key or on presence.
	 *
	 * @param array $q Parsed query.
	 * @param array $d Table definition.
	 * @return array|null
	 */
	public function discrete( array $q, array $d ): ?array {
		$o    = array();
		$flag = false;
		foreach ( SpeedUp_Meta_Tables_Sql::conj( $q['where'] ) as $n ) {
			if ( 'meta' === $n[0] ) {
				$k = $d['keys'][ $n[1] ] ?? null;
				if ( ! $k || ! $k['row'] || '' !== $n[2] || empty( $k['dict'] ) || ! empty( $n[5] ) || ! in_array( $n[3], array( '=', 'IN' ), true ) ) {
					return null;
				}
				$o[ $k['casts'][''] ] = $n[4];
			} elseif ( 'flag' === $n[0] ) {
				$o[ $n[1] ] = array( '1' );
				$flag       = true;
			} elseif ( 'ex' === $n[0] || 'noex' === $n[0] ) {
				$k = $d['keys'][ $n[1] ] ?? null;
				if ( ! isset( $k['ex'] ) ) {
					return null;
				}
				$o[ $k['ex'] ] = array( 'ex' === $n[0] ? '1' : '0' );
			} elseif ( ( 'or' === $n[0] && SpeedUp_Meta_Tables_Sql::has_meta( $n ) ) || 'raw' === $n[0] ) {
				return null;
			}
		}
		return count( $o ) > 1 || ( $o && $flag ) ? $o : null;
	}

	/**
	 * [status list or null, types or null, excluded statuses] of the core conditions, or null.
	 *
	 * @param array $q Parsed query.
	 * @param array $d Table definition.
	 * @return array|null
	 */
	public function coreparts( array $q, array $d ): ?array {
		$st = null;
		$ty = null;
		$no = array();
		$sc = self::STATUS_COL[ $q['ent'] ];
		foreach ( SpeedUp_Meta_Tables_Sql::conj( $q['where'] ) as $n ) {
			if ( 'or' === $n[0] ) {
				if ( SpeedUp_Meta_Tables_Sql::has_meta( $n ) ) {
					continue;
				}
				$v = array();
				$c = null;
				foreach ( $n[1] as $x ) {
					if ( 'core' !== $x[0] || ! in_array( $x[2], array( '=', 'IN' ), true ) || ( $c && $c !== $x[1] ) ) {
						return null;
					}
					$c = $x[1];
					array_push( $v, ...$x[3] );
				}
				$n = array( 'core', $c, 'IN', $v );
			}
			if ( 'raw' === $n[0] ) {
				return null;
			}
			if ( 'core' !== $n[0] ) {
				continue;
			}
			if ( in_array( $n[1], array( 'post_type', 'type' ), true ) ) {
				if ( ! in_array( $n[2], array( '=', 'IN' ), true ) ) {
					return null;
				}
				$ty = in_array( 'post_type', $d['core'], true ) ? $n[3] : null;
			} elseif ( $n[1] === $sc && in_array( $n[2], array( '=', 'IN' ), true ) && ! $st ) {
				$st = $n[3];
			} elseif ( $n[1] === $sc && in_array( $n[2], array( '!=', 'NOT IN' ), true ) ) {
				array_push( $no, ...$n[3] );
			} else {
				return null;
			}
		}
		return array( $st, $ty, $no );
	}

	/**
	 * Counter conditions on status and type.
	 *
	 * @param array $cp Core parts.
	 * @return string
	 */
	public function stw( array $cp ): string {
		return ( $cp[0] ? ' AND st IN ' . $this->inlist( $cp[0] ) : '' ) . ( $cp[2] ? ' AND st NOT IN ' . $this->inlist( $cp[2] ) : '' ) . ( $cp[1] ? ' AND ty IN ' . $this->inlist( $cp[1] ) : '' );
	}

	/**
	 * A count that the counters already hold, or null.
	 *
	 * @param array $q Parsed query.
	 * @param array $d Table definition.
	 * @return string|null
	 */
	public function counter( array $q, array $d ): ?string {
		if ( $q['other'] ) {
			return null;
		}
		$x  = $this->discrete( $q, $d );
		$g  = $x ? implode( ',', array_keys( $x ) ) : '';
		$cp = $x && isset( $d['multi'][ $g ] ) ? $this->coreparts( $q, $d ) : null;
		if ( $cp ) {
			$v     = array( '' );
			$first = true;
			foreach ( $x as $vals ) {
				$n = array();
				foreach ( $v as $a ) {
					foreach ( $vals as $b ) {
						$n[] = $first ? $b : "$a|$b";
					}
				}
				$v     = $n;
				$first = false;
			}
			return 'SELECT COALESCE(SUM(cnt),0) FROM ' . $this->pre . "counts WHERE tab='{$d['table']}' AND k={$d['multi'][$g]['k']} AND miss=0 AND val IN " . $this->inlist( $v ) . $this->stw( $cp );
		}
		$leaf = null;
		$cp   = $this->coreparts( $q, $d );
		if ( ! $cp ) {
			return null;
		}
		foreach ( SpeedUp_Meta_Tables_Sql::conj( $q['where'] ) as $n ) {
			if ( 'core' === $n[0] || 'true' === $n[0] || ( 'or' === $n[0] && ! SpeedUp_Meta_Tables_Sql::has_meta( $n ) ) ) {
				continue;
			}
			if ( 'or' === $n[0] ) {
				if ( 2 !== count( $n[1] ) ) {
					return null;
				}
				$z = array( $n[1][0][0], $n[1][1][0] );
				sort( $z );
				if ( array( 'ex', 'noex' ) !== $z || $n[1][0][1] !== $n[1][1][1] ) {
					return null;
				}
				$n = array( 'all', $n[1][0][1] );
			}
			if ( $leaf || ! in_array( $n[0], array( 'ex', 'noex', 'meta', 'all' ), true ) ) {
				return null;
			}
			$leaf = $n;
		}
		if ( ! $leaf || empty( $d['keys'][ $leaf[1] ]['row'] ) ) {
			return null;
		}
		$k = $d['keys'][ $leaf[1] ];
		$w = "tab='{$d['table']}' AND k={$k['n']}";
		if ( 'ex' === $leaf[0] ) {
			$w .= ' AND miss=0';
		} elseif ( 'noex' === $leaf[0] ) {
			$w .= ' AND miss=1';
		} elseif ( 'meta' === $leaf[0] ) {
			if ( ! empty( $leaf[5] ) || '' !== $leaf[2] || empty( $k['dict'] ) || ! in_array( $leaf[3], array( '=', 'IN' ), true ) || ! $this->counted( $d )[ $leaf[1] ] ) {
				return null;
			}
			$w .= ' AND miss=0 AND val IN ' . $this->inlist( $leaf[4] );
		}
		return 'SELECT COALESCE(SUM(cnt),0) FROM ' . $this->pre . "counts WHERE $w" . $this->stw( $cp );
	}

	/**
	 * An index column, with a prefix length for long text.
	 *
	 * @param string $c   Column.
	 * @param array  $def Table definition.
	 * @return string
	 */
	public function ixcol( string $c, array $def ) {
		foreach ( $def['keys'] as $k ) {
			if ( in_array( $c, $k['casts'], true ) && '' === array_search( $c, $k['casts'], true ) && $k['len'] > 175 ) {
				return "$c(100)";
			}
		}
		return $c;
	}

	/**
	 * The column that says whether a key is present. Without a presence column any cast column will do (it is
	 * NULL exactly when the key is missing); the one the query sorts by is taken, so its index can walk in order.
	 *
	 * @param array  $ki  Key info.
	 * @param string $key Meta key.
	 * @param array  $q   Parsed query.
	 * @return string
	 */
	private function excol( array $ki, string $key = '', array $q = array() ) {
		if ( isset( $ki['ex'] ) ) {
			return $ki['ex'];
		}
		foreach ( $q['order'] ?? array() as $o ) {
			// A date cast is NULL for text that is not a date, so it cannot say "present".
			if ( 'meta' === $o[0] && $o[1] === $key && isset( $ki['casts'][ $o[2] ] ) && ! str_starts_with( $o[2], 'DATE' ) ) {
				return $ki['casts'][ $o[2] ];
			}
		}
		return reset( $ki['casts'] );
	}

	/**
	 * The index a pattern needs: equality columns, the sort column, ranges, other filters, then date and status.
	 *
	 * @param array $q   Parsed query.
	 * @param array $def Table definition.
	 * @return array
	 */
	public function indexes( array $q, array $def ): array {
		$dt     = self::DATE_COL[ $q['ent'] ];
		$stc    = self::STATUS_COL[ $q['ent'] ];
		$conj   = SpeedUp_Meta_Tables_Sql::conj( $q['where'] );
		$eq     = array();
		$range  = array();
		$filter = array();
		foreach ( $conj as $n ) {
			if ( 'meta' === $n[0] && ! empty( $def['keys'][ $n[1] ]['row'] ) ) {
				$k = $def['keys'][ $n[1] ];
				$c = $k['casts'][ $n[2] ];
				if ( in_array( $n[3], array( '=', 'IN' ), true ) || ( 'LIKE' === $n[3] && $k['nd'] > 0 && $k['nd'] <= 300 ) ) {
					$eq[] = $c;
				} elseif ( in_array( $n[3], array( '<', '>', '<=', '>=', 'BETWEEN' ), true ) ) {
					$range[] = $c;
				} else {
					$filter[] = $k['fold'] ?? $c;
				}
			} elseif ( 'flag' === $n[0] ) {
				$eq[] = $n[1];
			} elseif ( 'noex' === $n[0] && ! empty( $def['keys'][ $n[1] ]['row'] ) ) {
				$eq[] = $this->excol( $def['keys'][ $n[1] ], $n[1], $q );
			} elseif ( 'ex' === $n[0] && ! empty( $def['keys'][ $n[1] ]['row'] ) ) {
				$filter[] = $this->excol( $def['keys'][ $n[1] ], $n[1], $q );
			} elseif ( 'or' === $n[0] ) {
				SpeedUp_Meta_Tables_Sql::walk(
					$n,
					function ( $x ) use ( &$filter, $def ) {
						if ( ! in_array( $x[0], array( 'meta', 'ex', 'noex' ), true ) || empty( $def['keys'][ $x[1] ]['row'] ) ) {
							return;
						}
						$k = $def['keys'][ $x[1] ];
						if ( 'meta' !== $x[0] ) {
							$filter[] = $this->excol( $k );
						} else {
							$filter[] = str_contains( $x[3], 'LIKE' ) && isset( $k['fold'] ) ? $k['fold'] : $k['casts'][ $x[2] ];
						}
					}
				);
			}
		}
		$sort = null;
		foreach ( $q['order'] as $o ) {
			if ( 'meta' === $o[0] && ! empty( $def['keys'][ $o[1] ]['row'] ) ) {
				$sort = $def['keys'][ $o[1] ]['casts'][ $o[2] ];
			}
		}
		$typecol = in_array( 'post_type', $def['core'], true ) ? 'post_type' : null;
		$tail    = array_filter( array( $dt, $stc, $typecol ) );
		$o       = $q['order'];
		$idcol   = $q['id'] ?? '';
		if ( 2 === count( $o ) && 'core' === $o[0][0] && $o[0][1] === $dt && 'core' === $o[1][0] && $o[1][1] === $idcol && $o[0][3] === $o[1][3] ) {
			$tail = array_filter( array( $dt, 'object_id', $stc, $typecol ) );
		}
		$short = function ( $c ) use ( $def ) {
			foreach ( $def['keys'] as $k ) {
				if ( ( $k['fold'] ?? '' ) === $c && $k['len'] > 175 ) {
					return false;
				}
				if ( in_array( $c, $k['casts'], true ) && $k['len'] > 175 && '' === array_search( $c, $k['casts'], true ) ) {
					return false;
				}
			}
			return true;
		};
		$same  = false;
		if ( $sort && $range ) {
			foreach ( $q['order'] as $o ) {
				foreach ( $conj as $n ) {
					if ( 'meta' === $o[0] && 'meta' === $n[0] && $n[1] === $o[1] && $n[2] !== $o[2] ) {
						$same = true;
					}
				}
			}
		}
		$list = $same ? array_merge( $eq, $range, array( $sort ), $filter, $tail ) : array_merge( $eq, $sort ? array( $sort ) : array(), $range, $filter, $tail );
		$list = array_filter( array_values( array_unique( array_filter( $list ) ) ), $short );
		return $eq || $range || $sort || $filter ? array( array_slice( array_values( $list ), 0, 10 ) ) : array();
	}

	/* ---- answering ---- */

	/**
	 * The table that has every key and cast a query needs, or null.
	 *
	 * @param array $q Parsed query.
	 * @return array|null
	 */
	public function find( array $q ) {
		$t = SpeedUp_Meta_Tables_Sql::types( $q );
		if ( null === $t ) {
			return null;
		}
		sort( $t );
		$need = SpeedUp_Meta_Tables_Sql::needs( $q );
		foreach ( $this->reg as $d ) {
			if ( $d['ent'] !== $q['ent'] || $d['types'] !== $t ) {
				continue;
			}
			foreach ( $need as $k => $c ) {
				if ( ! isset( $d['keys'][ $k ] ) || array_diff_key( $c, $d['keys'][ $k ]['casts'] ) || ! empty( $d['keys'][ $k ]['off'] ) ) {
					continue 2;
				}
			}
			return $d;
		}
		return null;
	}

	/**
	 * The statement to run instead of $sql, or null to run $sql itself.
	 *
	 * @param string $sql SQL.
	 * @return string|null
	 */
	public function handle( string $sql ): ?string {
		try {
			$r = $this->handle_one( $sql );
		} catch ( DomainException $e ) {
			$this->found = null;
			$this->why   = $e->getMessage();
			return null;
		}
		// A single-value SELECT keeps the column name WordPress expects.
		if ( null !== $r && preg_match( '/^\s*SELECT\s+(COUNT\(.*?\)|SUM\([^()]*\)|FOUND_ROWS\(\))\s*(?:FROM\b|;?\s*$)/is', $sql, $m ) ) {
			$name = str_replace( '`', '``', $m[1] );
			$r    = preg_replace( '/^SELECT (COUNT\(\*\)|COUNT\(DISTINCT h\.object_id\)|COALESCE\(SUM\(cnt\),0\)|SUM\(s\)\+0e0|\d+)(?=\s|$)(?! AS)/', "SELECT \$1 AS `$name`", $r );
		}
		return $r;
	}

	/**
	 * Answers one statement.
	 *
	 * @param string $sql SQL.
	 * @return string|null
	 */
	private function handle_one( string $sql ): ?string {
		if ( null !== $this->pend ) {
			$this->cnt[ $this->pend ] = (int) $this->rows( 'SELECT FOUND_ROWS()' )[0][0];
			$this->pend               = null;
		}
		// Nothing may run on the site's connection between a page and its FOUND_ROWS().
		if ( preg_match( '/^\s*SELECT FOUND_ROWS\(\)/i', $sql ) ) {
			$f           = $this->found;
			$this->found = null;
			return $f;
		}
		if ( ! $this->reg ) {
			return null;
		}
		return $this->cached( $sql );
	}

	/**
	 * Loads the pattern cache.
	 */
	private function load_patterns() {
		$t         = get_option( SpeedUp_Meta_Tables::PATTERNS );
		$this->tpl = is_array( $t ) && ( $t['_v'] ?? 0 ) === $this->ver ? $t : array( '_v' => $this->ver );
	}

	/**
	 * Saves new patterns, merged with what other requests saved meanwhile.
	 */
	public function save_patterns() {
		if ( ! $this->tnew || ! is_array( $this->tpl ) ) {
			return;
		}
		$this->tnew = false;
		wp_cache_delete( SpeedUp_Meta_Tables::PATTERNS, 'options' );
		$old = get_option( SpeedUp_Meta_Tables::PATTERNS );
		$t   = $this->tpl;
		if ( is_array( $old ) && ( $old['_v'] ?? 0 ) === ( $t['_v'] ?? 0 ) ) {
			$t += $old;
		}
		update_option( SpeedUp_Meta_Tables::PATTERNS, $t, false );
	}

	/**
	 * Answers from the pattern cache: the SQL with its literals replaced by markers is compiled once.
	 *
	 * @param string $sql SQL.
	 * @return string|null
	 */
	private function cached( string $sql ): ?string {
		if ( str_contains( $sql, ' LIKE ' ) ) {
			return $this->serve( $sql );
		}
		if ( null === $this->tpl ) {
			$this->load_patterns();
		}
		$lit  = array();
		$mark = function ( $v ) use ( &$lit ) {
			$lit[] = $v;
			return "'zq" . ( count( $lit ) - 1 ) . "qz'";
		};
		$norm = preg_replace_callback(
			"/(meta_value(?:\s+AS\s+[A-Z]+(?:\(\d+,\s*\d+\))?\))?\s*(?:<=|>=|=|<|>)\s*)'((?:[^'\\\\%]|\\\\.)*)'/",
			function ( $m ) use ( $mark ) {
				if ( '' === $m[2] || ( ! preg_match( '/=\s*$/', $m[1] ) && in_array( $m[2], $this->constants(), true ) ) ) {
					return $m[0];
				}
				return $m[1] . $mark( $m[2] );
			},
			$sql
		);
		$norm = preg_replace_callback(
			"/(meta_value(?:\s+AS\s+[A-Z]+(?:\(\d+,\s*\d+\))?\))?\s+IN\s*\()((?:\s*'(?:[^'\\\\%]|\\\\.)*'\s*,?)+)\)/",
			function ( $m ) use ( $mark ) {
				return $m[1] . preg_replace_callback( "/'((?:[^'\\\\]|\\\\.)*)'/", fn( $x ) => $mark( $x[1] ), $m[2] ) . ')';
			},
			$norm
		);
		$norm = preg_replace_callback(
			"/(meta_value(?:\s+AS\s+[A-Z]+(?:\(\d+,\s*\d+\))?\))?\s+BETWEEN\s+)'([^'\\\\%]*)'(\s+AND\s+)'([^'\\\\%]*)'/",
			function ( $m ) use ( $mark ) {
				return $m[1] . $mark( $m[2] ) . $m[3] . $mark( $m[4] );
			},
			$norm
		);
		$k    = md5( $norm );
		if ( isset( $this->tpl[ $k ] ) && false === $this->tpl[ $k ] ) {
			return $this->serve( $sql );
		}
		if ( ! isset( $this->tpl[ $k ] ) ) {
			$this->nocache = false;
			$this->touched = array();
			$r             = $this->serve( $norm );
			if ( null === $r ) {
				return $norm === $sql ? null : $this->serve( $sql );
			}
			if ( $this->nocache || count( $this->tpl ) > 500 ) {
				$this->found = null;
				if ( count( $this->tpl ) <= 500 ) {
					$this->tpl[ $k ] = false;
					$this->tnew      = true;
				}
				return $this->serve( $sql );
			}
			$this->tpl[ $k ] = array( $r, $this->found, array_keys( $this->touched ), $this->used_now );
			$this->tnew      = true;
		}
		list( $r, $f, $t ) = $this->tpl[ $k ];
		$map               = array();
		foreach ( $lit as $i => $v ) {
			$map[ "zq{$i}qz" ] = $v;
		}
		foreach ( $t as $en ) {
			$this->fresh( array( 'ent' => $en ) );
		}
		foreach ( (array) ( $this->tpl[ $k ][3] ?? array() ) as $tab ) {
			$this->used[ $tab ] = 1;
		}
		$this->found = $f ? strtr( $f, $map ) : null;
		++$this->served;
		return strtr( $r, $map );
	}

	/**
	 * Tables used by the statement being compiled.
	 *
	 * @var array
	 */
	private $used_now = array();

	/**
	 * Literals of the flag columns.
	 *
	 * @return array
	 */
	private function constants(): array {
		if ( null === $this->consts ) {
			$this->consts = array();
			foreach ( $this->reg as $d ) {
				foreach ( array_keys( $d['flags'] ?? array() ) as $k ) {
					if ( str_starts_with( $k, 'pred|' ) ) {
						$this->consts[] = substr( $k, strrpos( $k, '|' ) + 1 );
					}
				}
			}
		}
		return $this->consts;
	}

	/**
	 * Compiles one statement.
	 *
	 * @param string $sql SQL.
	 * @return string|null
	 */
	private function serve( string $sql ): ?string {
		$this->used_now = array();
		$q              = SpeedUp_Meta_Tables_Sql::parse( $sql );
		if ( ! $q ) {
			return $this->raw( $sql );
		}
		$d = $this->find( $q );
		if ( ! $d ) {
			return null;
		}
		$r = $this->compile( $q, $d );
		if ( ! $r ) {
			return null;
		}
		$this->fresh( $d );
		++$this->served;
		return $r;
	}

	/**
	 * A comparison.
	 *
	 * @param string $c  Column.
	 * @param string $op Operator.
	 * @param array  $v  Values.
	 * @return string
	 */
	public function cond( string $c, string $op, array $v ) {
		$l = array_map( array( $this, 'e' ), $v );
		if ( 'IN' === $op || 'NOT IN' === $op ) {
			return "$c $op (" . implode( ',', $l ) . ')';
		}
		if ( str_contains( $op, 'BETWEEN' ) ) {
			return "$c $op {$l[0]} AND {$l[1]}";
		}
		return "$c $op {$l[0]}";
	}

	/**
	 * The WHERE clause of a node over the table.
	 *
	 * @param array $n Node.
	 * @param array $d Table definition.
	 * @param array $q Parsed query.
	 * @return string
	 * @throws RuntimeException On a node kind it does not know.
	 */
	public function where( array $n, array $d, array $q ): string {
		if ( 'and' === $n[0] || 'or' === $n[0] ) {
			if ( 'or' === $n[0] ) {
				// "status = a OR status = b" becomes one IN list.
				$c = null;
				$v = array();
				foreach ( $n[1] as $x ) {
					if ( 'core' === $x[0] && in_array( $x[2], array( '=', 'IN' ), true ) && ( null === $c || $c === $x[1] ) ) {
						$c = $x[1];
						array_push( $v, ...$x[3] );
					} else {
						$v = null;
						break;
					}
				}
				if ( $v ) {
					$op = 1 === count( $v ) ? '=' : 'IN';
					return $this->where( array( 'core', $c, $op, $v ), $d, $q );
				}
			}
			return '(' . implode( 'and' === $n[0] ? ' AND ' : ' OR ', array_map( fn( $x ) => $this->where( $x, $d, $q ), $n[1] ) ) . ')';
		}
		switch ( $n[0] ) {
			case 'true':
				return '1';
			case 'core':
				if ( in_array( $n[1], array( 'post_type', 'type' ), true ) && ! in_array( $n[1], $d['core'], true ) ) {
					return '1';
				}
				return $this->cond( 'h.' . ( $n[1] === $q['id'] ? 'object_id' : $n[1] ), $n[2], $n[3] );
			case 'raw':
				if ( ! empty( $d['text'] ) && preg_match( '/^' . preg_quote( $q['prim'], '/' ) . "\.(\w+) LIKE ('(?:[^'\\\\]|\\\\.)*')$/", $n[1], $x ) && in_array( $x[1], $d['text'], true ) ) {
					$pt = $this->pat( $d, stripslashes( substr( $x[2], 1, -1 ) ) );
					if ( null !== $pt ) {
						return "(h.t_$x[1] LIKE " . $this->e( $pt ) . " OR (h.t_$x[1] IS NULL AND EXISTS(SELECT 1 FROM {$q['prim']} z WHERE z.{$q['id']}=h.object_id AND z.$x[1] LIKE $x[2])))";
					}
				}
				$this->needp = true;
				return '(' . $n[1] . ')';
			case 'flag':
				return "h.{$n[1]}=1";
			case 'ex':
				$k = $d['keys'][ $n[1] ];
				if ( ! $k['row'] ) {
					return "EXISTS(SELECT 1 FROM {$k['vt']} x WHERE x.key_id={$k['n']} AND x.object_id=h.object_id)";
				}
				return isset( $k['ex'] ) ? "h.{$k['ex']}=1" : 'h.' . $this->excol( $k, $n[1], $q ) . ' IS NOT NULL';
			case 'noex':
				$k = $d['keys'][ $n[1] ];
				if ( ! $k['row'] ) {
					return "NOT EXISTS(SELECT 1 FROM {$k['vt']} x WHERE x.key_id={$k['n']} AND x.object_id=h.object_id)";
				}
				return isset( $k['ex'] ) ? "h.{$k['ex']}=0" : 'h.' . $this->excol( $k, $n[1], $q ) . ' IS NULL';
			case 'meta':
				return $this->where_meta( $n, $d );
		}
		throw new RuntimeException( 'node ' . esc_html( $n[0] ) );
	}

	/**
	 * The WHERE clause of a meta condition.
	 *
	 * @param array $n Node.
	 * @param array $d Table definition.
	 * @return string
	 */
	private function where_meta( array $n, array $d ): string {
		$k   = $d['keys'][ $n[1] ];
		$all = array_merge( array( array( $n[2], $n[3], $n[4] ) ), $n[5] ?? array() );
		// LIKE on a few-valued key: the matching values are found first.
		if ( $k['row'] && 1 === count( $all ) && 'LIKE' === $n[3] && '' === $n[2] && $k['nd'] > 0 && $k['nd'] <= 300 ) {
			$c  = $k['casts'][''];
			$pt = ! empty( $k['dict'] ) ? $this->pat( $d, $n[4][0] ) : null;
			if ( null !== $pt ) {
				$re = '/' . ( '%' === $pt[0] ? '' : '^' ) . preg_quote( stripslashes( preg_replace( '/^%|%$/', '', $pt ) ), '/' ) . ( '%' === substr( $pt, -1 ) ? '' : '$' ) . '/sD';
				$v  = array_values( array_filter( $k['dict'], fn( $x ) => preg_match( $re, SpeedUp_Meta_Tables_Fold::fold( $this->m, $d['cc'], (string) $x ) ) ) );
			} else {
				$v = array_column( $this->rows( "SELECT DISTINCT h.$c FROM {$d['table']} h WHERE h.$c LIKE " . $this->e( $n[4][0] ) ), 0 );
			}
			return $v ? $this->cond( "h.$c", 'IN', $v ) : '0';
		}
		if ( isset( $k['fold'] ) && 1 === count( $all ) && 'LIKE' === $n[3] && '' === $n[2] ) {
			$pt = $this->pat( $d, $n[4][0] );
			if ( null !== $pt ) {
				return "h.{$k['fold']} LIKE " . $this->e( $pt );
			}
		}
		if ( $k['row'] ) {
			return implode( ' AND ', array_map( fn( $a ) => $this->cond( 'h.' . $k['casts'][ $a[0] ], $a[1], $a[2] ), $all ) );
		}
		return "h.object_id IN (SELECT x.object_id FROM {$k['vt']} x WHERE x.key_id={$k['n']} AND " . implode( ' AND ', array_map( fn( $a ) => $this->cond( 'x.v' . self::suffix( $a[0] ), $a[1], $a[2] ), $all ) ) . ')';
	}

	/**
	 * An OR of "contains" on several keys (or post text) with one pattern: [sorted columns, pattern], or null.
	 *
	 * @param array  $n    Node.
	 * @param string $prim Main table.
	 * @return array|null
	 */
	public static function likeset( array $n, string $prim ): ?array {
		if ( 'or' !== $n[0] ) {
			return null;
		}
		$c = array();
		$p = null;
		foreach ( $n[1] as $x ) {
			if ( 'meta' === $x[0] && 'LIKE' === $x[3] && '' === $x[2] && empty( $x[5] ) ) {
				$k = $x[1];
				$v = $x[4][0];
			} elseif ( 'raw' === $x[0] && preg_match( '/^' . preg_quote( $prim, '/' ) . "\.(post_title|post_excerpt|post_content) LIKE '((?:[^'\\\\]|\\\\.)*)'$/", $x[1], $m ) ) {
				$k = '@' . $m[1];
				$v = stripslashes( $m[2] );
			} else {
				return null;
			}
			if ( ( null !== $p && $p !== $v ) || ! preg_match( '/^%.+%$/s', $v ) ) {
				return null;
			}
			$p   = $v;
			$c[] = $k;
		}
		sort( $c );
		return array( $c, $p );
	}

	/**
	 * Page and count from the narrow search table, when the rest of the condition is only status and type.
	 *
	 * @param array $q Parsed query.
	 * @param array $d Table definition.
	 * @return string|null
	 */
	public function search( array $q, array $d ): ?string {
		$set = null;
		$st  = null;
		foreach ( SpeedUp_Meta_Tables_Sql::conj( $q['where'] ) as $n ) {
			$c = self::likeset( $n, $q['prim'] );
			if ( $c ) {
				if ( $set ) {
					return null;
				}
				$set = $c;
				continue;
			}
			if ( 'true' === $n[0] ) {
				continue;
			}
			if ( 'or' !== $n[0] && 'core' !== $n[0] ) {
				return null;
			}
			$cp = $this->coreparts(
				array(
					'ent'   => $q['ent'],
					'where' => $n,
				),
				$d
			);
			if ( ! $cp || $cp[1] || $cp[2] ) {
				return null;
			}
			if ( $cp[0] ) {
				if ( $st ) {
					return null;
				}
				$st = $cp[0];
			}
		}
		if ( ! $set || 'all' === $q['sel'] || array_diff( $q['other'], array( "LEFT JOIN {$q['meta']} AS sq1" ) ) ) {
			return null;
		}
		$g = implode( ',', $set[0] );
		$p = isset( $d['sets'][ $g ] ) ? $this->pat( $d, $set[1] ) : null;
		if ( null === $p ) {
			return null;
		}
		$t  = $d['sets'][ $g ]['t'];
		$w  = 's.s LIKE ' . $this->e( $p ) . ( $st ? ' AND s.st IN ' . $this->inlist( $st ) : '' );
		$j  = '';
		$o  = array();
		$dt = self::DATE_COL[ $q['ent'] ];
		foreach ( $q['order'] as $x ) {
			if ( 'core' === $x[0] && $x[1] === $dt ) {
				$o[] = "s.dt {$x[3]}";
			} elseif ( 'raw' === $x[0] && preg_match( '/^' . preg_quote( $q['prim'], '/' ) . "\.(\w+) LIKE ('(?:[^'\\\\]|\\\\.)*')$/", $x[1], $m ) ) {
				$j   = " JOIN {$q['prim']} z ON z.{$q['id']}=s.object_id";
				$o[] = "z.{$m[1]} LIKE {$m[2]} {$x[3]}";
			} else {
				return null;
			}
		}
		if ( (int) $this->rows( "SELECT EXISTS(SELECT 1 FROM $t WHERE s IS NULL)" )[0][0] ) {
			return null;
		}
		$this->used_now[] = $d['table'];
		if ( 'count' === $q['sel'] ) {
			return "SELECT COUNT(*) FROM $t s WHERE $w";
		}
		return 'SELECT ' . ( $q['calc'] ? 'SQL_CALC_FOUND_ROWS ' : '' ) . "s.object_id AS `{$q['id']}` FROM $t s$j WHERE $w" . ( $o ? ' ORDER BY ' . implode( ', ', $o ) : '' ) . $this->limit( $q );
	}

	/**
	 * The LIMIT clause of a query.
	 *
	 * @param array $q Parsed query.
	 * @return string
	 */
	private function limit( array $q ): string {
		return $q['limit'] ? " LIMIT {$q['limit'][0]}, {$q['limit'][1]}" : '';
	}

	/**
	 * Whether the pattern's own index returns rows in the requested order without a residual filter.
	 *
	 * @param array $q Parsed query.
	 * @param array $d Table definition.
	 * @return bool
	 */
	public function inorder( array $q, array $d ): bool {
		$o = $q['order'][0] ?? null;
		if ( ! $o || 'raw' === $o[0] ) {
			return false;
		}
		$k = 'meta' === $o[0] ? $o[1] : null;
		foreach ( SpeedUp_Meta_Tables_Sql::conj( $q['where'] ) as $n ) {
			if ( 'meta' === $n[0] ) {
				$ki = $d['keys'][ $n[1] ];
				if ( '=' === $n[3] && ( '' !== $n[2] || $ki['nd'] > 5 || $ki['nd'] < 1 ) ) {
					return false;
				}
				if ( $n[1] === $k && $n[2] !== $o[2] ) {
					return false;
				}
				if ( ! $ki['row'] || 'IN' === $n[3] || 'LIKE' === $n[3] || ( '=' !== $n[3] && $n[1] !== $k ) ) {
					return false;
				}
			} elseif ( ( 'or' === $n[0] && SpeedUp_Meta_Tables_Sql::has_meta( $n ) ) || 'raw' === $n[0] ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Compiles a parsed query against a table.
	 *
	 * @param array $q Parsed query.
	 * @param array $d Table definition.
	 * @return string|null
	 */
	public function compile( array $q, array $d ): ?string {
		foreach ( array_keys( SpeedUp_Meta_Tables_Sql::needs( $q ) ) as $k ) {
			if ( ! $d['keys'][ $k ]['row'] && ! $q['dedupe'] ) {
				return null;
			}
		}
		$this->used_now[] = $d['table'];
		$q                = $this->flagged( $q, $d );
		$tp               = $this->textpage( $q, $d );
		if ( $tp ) {
			return $tp;
		}
		if ( ! empty( $d['sets'] ) ) {
			$sr = $this->search( $q, $d );
			if ( $sr ) {
				return $sr;
			}
		}
		$this->needp = (bool) $q['other'];
		$w           = $this->where( $q['where'], $d, $q );
		$ord         = $this->order( $q, $d );
		$sg          = $this->sig( $q );
		if ( 'id' === $q['sel'] && ! $this->needp && $q['limit'] && $q['limit'][0] + $q['limit'][1] <= 100 && isset( $d['own'][ $sg ] ) ) {
			$mg = $this->merged( $q, $d, $w );
			if ( $mg ) {
				return $mg;
			}
		}
		$hint = isset( $d['own'][ $sg ] ) ? " FORCE INDEX ({$d['own'][$sg]})" : '';
		$ctr  = $q['calc'] || 'count' === $q['sel'] ? $this->counter( $q, $d ) : null;
		$one  = $q['calc'] && ! $ctr && ! $this->inorder( $q, $d );
		if ( $ctr && 'count' === $q['sel'] ) {
			return $ctr;
		}
		if ( $ctr && $q['limit'] && isset( $d['own'][ "d$sg" ] ) && str_contains( $w, ' IN (' ) && preg_match( '/h\.c\d+ IN \(/', $w ) ) {
			// Many matches: walk the date index instead.
			$this->nocache = true;
			$total         = (int) $this->rows( $ctr )[0][0];
			if ( $total > 2000 ) {
				$hint = " FORCE INDEX ({$d['own']["d$sg"]})";
				$ctr  = 'SELECT ' . $total;
			}
		}
		$from = "{$d['table']} h$hint";
		if ( $this->needp ) {
			$from .= " JOIN {$q['prim']} ON {$q['prim']}.{$q['id']}=h.object_id " . implode( ' ', $q['other'] );
		}
		$cnt = $q['other'] ? 'COUNT(DISTINCT h.object_id)' : 'COUNT(*)';
		$ck  = "$from|$w";
		if ( 'order' === $q['ent'] ) {
			if ( 'count' === $q['sel'] && isset( $this->cnt[ $ck ] ) ) {
				return 'SELECT ' . $this->cnt[ $ck ];
			}
			if ( 'id' === $q['sel'] && $q['limit'] && str_contains( $w, ' LIKE ' ) ) {
				$one        = true;
				$this->pend = $ck;
			}
		}
		if ( 'count' === $q['sel'] ) {
			return "SELECT $cnt FROM $from WHERE $w";
		}
		$grp  = $q['other'] && $q['dedupe'] ? ' GROUP BY h.object_id' : '';
		$page = 'SELECT ' . ( $one ? 'SQL_CALC_FOUND_ROWS ' : '' ) . "h.object_id AS `{$q['id']}` FROM $from WHERE $w$grp" . ( $ord ? ' ORDER BY ' . implode( ', ', $ord ) : '' ) . $this->limit( $q );
		if ( $q['calc'] && ! $one ) {
			$this->found = $ctr ?? "SELECT $cnt FROM $from WHERE $w";
		}
		if ( 'all' === $q['sel'] ) {
			$page = "SELECT {$q['prim']}.* FROM ($page) pg JOIN {$q['prim']} ON {$q['prim']}.{$q['id']}=pg.`{$q['id']}`";
		}
		return $page;
	}

	/**
	 * The ORDER BY terms of a query.
	 *
	 * @param array $q Parsed query.
	 * @param array $d Table definition.
	 * @return array
	 */
	private function order( array $q, array $d ): array {
		$ord = array();
		foreach ( $q['order'] as $o ) {
			if ( 'meta' === $o[0] ) {
				$k     = $d['keys'][ $o[1] ];
				$ord[] = $k['row'] ? 'h.' . $k['casts'][ $o[2] ] . " {$o[3]}" : '(SELECT MIN(x.v' . self::suffix( $o[2] ) . ") FROM {$k['vt']} x WHERE x.key_id={$k['n']} AND x.object_id=h.object_id) {$o[3]}";
			} elseif ( 'core' === $o[0] ) {
				$ord[] = 'h.' . ( $o[1] === $q['id'] ? 'object_id' : $o[1] ) . " {$o[3]}";
			} else {
				$ord[] = $this->where( array( 'raw', $o[1] ), $d, $q ) . " {$o[3]}";
			}
		}
		return $ord;
	}

	/**
	 * One IN list, everything else equality: each value is read in order from its own index range.
	 *
	 * @param array  $q Parsed query.
	 * @param array  $d Table definition.
	 * @param string $w WHERE clause.
	 * @return string|null
	 */
	private function merged( array $q, array $d, string $w ): ?string {
		$dt = self::DATE_COL[ $q['ent'] ];
		$o  = $q['order'];
		if ( ! $o || 'core' !== $o[0][0] || $o[0][1] !== $dt || count( $o ) > 1 ) {
			return null;
		}
		$in = null;
		$l  = SpeedUp_Meta_Tables_Sql::conj( $q['where'] );
		foreach ( $l as $i => $n ) {
			if ( 'meta' === $n[0] ) {
				$k = $d['keys'][ $n[1] ];
				if ( ! $k['row'] || ! empty( $n[5] ) || ! in_array( $n[3], array( '=', 'IN' ), true ) ) {
					return null;
				}
				if ( 'IN' === $n[3] ) {
					if ( null !== $in || count( $n[4] ) > 20 ) {
						return null;
					}
					$in = $i;
				}
			} elseif ( in_array( $n[0], array( 'raw', 'ex' ), true ) || ( 'or' === $n[0] && SpeedUp_Meta_Tables_Sql::has_meta( $n ) ) ) {
				return null;
			}
		}
		if ( null === $in ) {
			return null;
		}
		$h   = " FORCE INDEX ({$d['own'][$this->sig($q)]})";
		$max = $q['limit'][0] + $q['limit'][1];
		$u   = array();
		foreach ( $l[ $in ][4] as $v ) {
			$x           = $l;
			$x[ $in ][3] = '=';
			$x[ $in ][4] = array( $v );
			$u[]         = "(SELECT h.object_id, h.$dt dt FROM {$d['table']} h$h WHERE " . $this->where( array( 'and', $x ), $d, $q ) . " ORDER BY h.$dt {$o[0][3]} LIMIT $max)";
		}
		if ( $q['calc'] ) {
			$this->found = $this->counter( $q, $d ) ?? "SELECT COUNT(*) FROM {$d['table']} h$h WHERE $w";
		}
		return 'SELECT object_id AS `' . $q['id'] . '` FROM (' . implode( ' UNION ', $u ) . ") z ORDER BY dt {$o[0][3]} LIMIT {$q['limit'][0]}, {$q['limit'][1]}";
	}

	/* ---- text search: the method is measured on an observed search before it is used ---- */

	/**
	 * The text of several folded columns.
	 *
	 * @param array $cols Keys, or "@column" for post text.
	 * @param array $d    Table definition.
	 * @return string
	 */
	private function textexpr( array $cols, array $d ): string {
		return 'CONCAT_WS(CHAR(2),' . implode( ',', array_map( fn( $k ) => '@' === $k[0] ? 'h.t_' . substr( $k, 1 ) : "COALESCE(h.{$d['keys'][$k]['fold']},'')", $cols ) ) . ')';
	}

	/**
	 * Measures scan, dictionary, chunk and trigram search for each text pattern and keeps the fastest.
	 *
	 * @param array $def Table definition.
	 * @param array $qs  Parsed queries.
	 */
	public function texts( array &$def, array $qs ) {
		$def['search'] = array();
		$cand          = array();
		$x             = $this->def( $def['ent'] );
		foreach ( $qs as $q ) {
			if ( ! empty( $q['raw'] ) ) {
				foreach ( $q['sets'] ?? array() as $s ) {
					sort( $s );
					$cand[ implode( ',', $s ) ] = array( $s, '%ann%', null );
				}
				continue;
			}
			foreach ( SpeedUp_Meta_Tables_Sql::conj( $q['where'] ) as $n ) {
				$c = self::likeset( $n, $x['prim'] );
				if ( $c ) {
					$cand[ implode( ',', $c[0] ) ] = array( $c[0], $c[1], $q );
				} elseif ( 'meta' === $n[0] && 'LIKE' === $n[3] && '' === $n[2] && preg_match( '/^%.+%$/s', $n[4][0] ) && isset( $def['keys'][ $n[1] ]['fold'] ) && $def['keys'][ $n[1] ]['nd'] > 300 ) {
					$cand[ $n[1] ] = array( array( $n[1] ), $n[4][0], $q );
				}
			}
		}
		$no = 0;
		foreach ( $cand as $g => $one ) {
			list( $cols, $pat, $q ) = $one;
			foreach ( $cols as $k ) {
				if ( '@' === $k[0] ? ! in_array( substr( $k, 1 ), $def['text'], true ) : ! isset( $def['keys'][ $k ]['fold'] ) ) {
					continue 2;
				}
			}
			++$no;
			$t     = $def['table'];
			$p     = trim( stripslashes( (string) $this->pat( $def, $pat ) ), '%' );
			$texts = array_filter( $cols, fn( $k ) => '@' === $k[0] );
			if ( strlen( $p ) < 3 || ( $texts && (int) $this->rows( "SELECT COUNT(*) FROM $t h WHERE " . implode( ' OR ', array_map( fn( $k ) => 'h.t_' . substr( $k, 1 ) . ' IS NULL', $texts ) ) )[0][0] ) ) {
				continue;
			}
			$time = function () use ( $q, $g, &$def, $pat, $cols ) {
				$a = array();
				for ( $i = 0; $i < 7; $i++ ) {
					$t0 = hrtime( true );
					if ( $q ) {
						$this->rows( (string) $this->compile( $q, $def ) );
						if ( $this->found ) {
							$this->rows( $this->found );
							$this->found = null;
						}
						$this->pend = null;
					} else {
						$this->rows( $this->textids( $def, $g, $pat ) ?? SpeedUp_Meta_Tables_Raw::scan( $this, $def, $cols, $pat ) );
					}
					$a[] = ( hrtime( true ) - $t0 ) / 1e6;
				}
				sort( $a );
				return $a[3];
			};
			$best = array( 'scan', $time() );
			$res  = array( 'scan' => round( $best[1], 2 ) );
			foreach ( array( 'dict', 'chunk', 'gram' ) as $mth ) {
				$def['search'][ $g ] = array(
					'm'    => $mth,
					't'    => "{$t}_x{$no}" . $mth[0],
					'cols' => $cols,
				);
				if ( ! $this->textbuild( $def, $g ) ) {
					unset( $def['search'][ $g ] );
					continue;
				}
				$ms          = $time();
				$res[ $mth ] = round( $ms, 2 );
				if ( $ms < $best[1] * .7 && $best[1] - $ms > .5 ) {
					if ( 'scan' !== $best[0] ) {
						$this->textdrop( "{$t}_x{$no}" . $best[0][0] );
					}
					$best = array( $mth, $ms );
				} else {
					$this->textdrop( "{$t}_x{$no}" . $mth[0] );
				}
				unset( $def['search'][ $g ] );
			}
			if ( 'scan' !== $best[0] ) {
				$def['search'][ $g ] = array(
					'm'    => $best[0],
					't'    => "{$t}_x{$no}" . $best[0][0],
					'cols' => $cols,
				);
			}
			$def['measured'][ $g ] = $res + array( 'use' => $best[0] );
		}
	}

	/**
	 * Drops a text search table.
	 *
	 * @param string $t Table.
	 */
	private function textdrop( string $t ) {
		$this->rows( "DROP TABLE IF EXISTS $t, {$t}v" );
	}

	/**
	 * Distinct trigrams of a string.
	 *
	 * @param string $s Text.
	 * @return array
	 */
	public static function grams( string $s ): array {
		$g = array();
		for ( $i = 0, $n = strlen( $s ) - 2; $i < $n; $i++ ) {
			$g[ 'g' . substr( $s, $i, 3 ) ] = 1;
		}
		return array_map( fn( $x ) => substr( $x, 1 ), array_map( 'strval', array_keys( $g ) ) );
	}

	/**
	 * Builds one text search table; false when the method does not fit.
	 *
	 * @param array  $d Table definition.
	 * @param string $g Pattern key.
	 * @return bool
	 */
	private function textbuild( array $d, string $g ): bool {
		$x  = $d['search'][ $g ];
		$t  = $x['t'];
		$ex = $this->textexpr( $x['cols'], $d );
		$this->textdrop( $t );
		if ( 'gram' === $x['m'] ) {
			$this->rows( "CREATE TABLE $t (g binary(3) not null, id bigint unsigned not null, primary key(g,id)) ENGINE=InnoDB" );
			$at = 0;
			while ( true ) {
				$r = $this->rows( "SELECT h.object_id, $ex FROM {$d['table']} h WHERE h.object_id>$at ORDER BY h.object_id LIMIT 3000" );
				if ( ! $r ) {
					break;
				}
				$at = end( $r )[0];
				$this->gramadd( $t, $r );
			}
			return true;
		}
		if ( 'chunk' === $x['m'] ) {
			$this->rows( "CREATE TABLE $t (a bigint unsigned primary key, d mediumblob not null) ENGINE=InnoDB" );
			$at = 0;
			while ( true ) {
				$r = $this->rows( "SELECT h.object_id, $ex FROM {$d['table']} h WHERE h.object_id>$at ORDER BY h.object_id LIMIT 500" );
				if ( ! $r ) {
					break;
				}
				$first = 0 === $at ? 0 : $r[0][0];
				$at    = end( $r )[0];
				$this->rows( "INSERT INTO $t VALUES($first," . $this->e( $this->chunk( $r ) ) . ')' );
			}
			return true;
		}
		$n  = (int) $this->rows( "SELECT COUNT(*) FROM {$d['table']}" )[0][0];
		$dn = 0;
		foreach ( $x['cols'] as $k ) {
			if ( '@' === $k[0] ) {
				return false;
			}
			$dn += (int) $d['keys'][ $k ]['nd'];
		}
		if ( $dn > $n / 4 ) {
			return false;
		}
		$this->rows( "CREATE TABLE $t (id int primary key auto_increment, h binary(16) not null, f varbinary(1500) not null, unique key(h)) ENGINE=InnoDB" );
		$this->rows( "CREATE TABLE {$t}v (vid int not null, object_id bigint unsigned not null, primary key(vid,object_id), key(object_id)) ENGINE=InnoDB" );
		$this->dictadd( $d, $x, '' );
		return true;
	}

	/**
	 * Adds the values of matching rows to a dictionary search table.
	 *
	 * @param array  $d   Table definition.
	 * @param array  $x   Search definition.
	 * @param string $cond Extra condition.
	 */
	private function dictadd( array $d, array $x, string $cond ) {
		foreach ( $x['cols'] as $k ) {
			$f = $d['keys'][ $k ]['fold'];
			$this->rows( "INSERT IGNORE INTO {$x['t']}(h,f) SELECT DISTINCT LEFT(UNHEX(SHA2($f,256)),16), $f FROM {$d['table']} WHERE $f IS NOT NULL $cond" );
			$this->rows( "INSERT IGNORE INTO {$x['t']}v SELECT w.id, h.object_id FROM {$d['table']} h JOIN {$x['t']} w ON w.h=LEFT(UNHEX(SHA2(h.$f,256)),16) WHERE h.$f IS NOT NULL " . str_replace( 'object_id', 'h.object_id', $cond ) );
		}
	}

	/**
	 * Adds the trigrams of rows.
	 *
	 * @param string $t    Table.
	 * @param array  $rows [object id, text] rows.
	 */
	private function gramadd( string $t, array $rows ) {
		$b = array();
		foreach ( $rows as $r ) {
			foreach ( self::grams( (string) $r[1] ) as $g ) {
				$b[] = "(x'" . bin2hex( $g ) . "'," . (int) $r[0] . ')';
				if ( count( $b ) >= 4000 ) {
					$this->rows( "INSERT IGNORE INTO $t VALUES" . implode( ',', $b ) );
					$b = array();
				}
			}
		}
		if ( $b ) {
			$this->rows( "INSERT IGNORE INTO $t VALUES" . implode( ',', $b ) );
		}
	}

	/**
	 * One chunk: each row is a newline, its id in 21 bytes of 3 bits, and its text.
	 *
	 * @param array $rows [object id, text] rows.
	 * @return string
	 */
	private function chunk( array $rows ): string {
		$o = '';
		foreach ( $rows as $r ) {
			$h = '';
			for ( $z = (int) $r[0], $b = 0; $b < 21; $b++, $z >>= 3 ) {
				$h .= chr( 0xf8 | ( $z & 7 ) );
			}
			$o .= "\n$h" . str_replace( "\n", "\x01", (string) $r[1] );
		}
		return $o . "\n";
	}

	/**
	 * Refreshes a text search table for some objects.
	 *
	 * @param array  $d   Table definition.
	 * @param array  $x   Search definition.
	 * @param string $l   Object id list.
	 * @param array  $old Text of the objects before the refresh (trigrams only).
	 */
	private function textrefresh( array $d, array $x, string $l, array $old ) {
		$ex = $this->textexpr( $x['cols'], $d );
		if ( 'gram' === $x['m'] ) {
			foreach ( $old as $r ) {
				$g = self::grams( (string) $r[1] );
				if ( $g ) {
					$this->rows( "DELETE FROM {$x['t']} WHERE id=" . (int) $r[0] . ' AND g IN (' . implode( ',', array_map( fn( $z ) => "x'" . bin2hex( $z ) . "'", $g ) ) . ')' );
				}
			}
			$this->gramadd( $x['t'], $this->rows( "SELECT h.object_id, $ex FROM {$d['table']} h WHERE h.object_id IN ($l)" ) );
			return;
		}
		if ( 'dict' === $x['m'] ) {
			$this->rows( "DELETE FROM {$x['t']}v WHERE object_id IN ($l)" );
			$this->dictadd( $d, $x, "AND object_id IN ($l)" );
			return;
		}
		$c = array();
		foreach ( explode( ',', $l ) as $id ) {
			$c[ $this->rows( "SELECT MAX(a) FROM {$x['t']} WHERE a<=" . (int) $id )[0][0] ] = 1;
		}
		foreach ( array_keys( $c ) as $a ) {
			$a     = (int) $a;
			$to    = $this->rows( "SELECT MIN(a) FROM {$x['t']} WHERE a>$a" )[0][0];
			$r     = $this->rows( "SELECT h.object_id, $ex FROM {$d['table']} h WHERE h.object_id>=$a" . ( null === $to ? '' : ' AND h.object_id<' . (int) $to ) . ' ORDER BY h.object_id' );
			$first = true;
			$parts = $r ? array_chunk( $r, 600 ) : array( array() );
			foreach ( $parts as $p ) {
				$this->rows( "REPLACE INTO {$x['t']} VALUES(" . ( $first ? $a : (int) $p[0][0] ) . ',' . $this->e( $this->chunk( $p ) ) . ')' );
				$first = false;
			}
		}
	}

	/**
	 * SELECT of the object ids whose text contains the pattern, or null.
	 *
	 * @param array  $d   Table definition.
	 * @param string $g   Pattern key.
	 * @param string $pat LIKE pattern.
	 * @return string|null
	 */
	public function textids( array $d, string $g, string $pat ): ?string {
		$x = $d['search'][ $g ] ?? null;
		if ( ! $x ) {
			return null;
		}
		$f = $this->pat( $d, $pat );
		if ( null === $f || ! preg_match( '/^%.+%$/s', $f ) ) {
			return null;
		}
		$p = stripslashes( substr( $f, 1, -1 ) );
		if ( 'dict' === $x['m'] ) {
			return "SELECT DISTINCT v.object_id FROM {$x['t']} w JOIN {$x['t']}v v ON v.vid=w.id WHERE w.f LIKE " . $this->e( $f );
		}
		if ( 'gram' === $x['m'] ) {
			if ( strlen( $p ) < 3 ) {
				return null;
			}
			$j = '';
			$w = '';
			foreach ( self::grams( $p ) as $i => $gr ) {
				$v = "x'" . bin2hex( $gr ) . "'";
				if ( $i ) {
					$j .= " JOIN {$x['t']} g$i ON g$i.g=$v AND g$i.id=g0.id";
				} else {
					$w = "g0.g=$v";
				}
				if ( $i > 5 ) {
					break;
				}
			}
			return "SELECT h.object_id FROM {$x['t']} g0$j JOIN {$d['table']} h ON h.object_id=g0.id WHERE $w AND " . $this->textexpr( $x['cols'], $d ) . ' LIKE ' . $this->e( $f );
		}
		$ids = array();
		foreach ( $this->rows( "SELECT d FROM {$x['t']}" ) as $row ) {
			$t   = $row[0];
			$s   = 0;
			$len = strlen( $t );
			while ( true ) {
				$s = strpos( $t, $p, $s );
				if ( false === $s ) {
					break;
				}
				$a = strrpos( $t, "\n", $s - $len );
				$r = 0;
				for ( $b = 20; $b >= 0; $b-- ) {
					$r = ( $r << 3 ) | ( ord( $t[ $a + 1 + $b ] ) & 7 );
				}
				$ids[] = $r;
				$s     = strpos( $t, "\n", $s );
				if ( count( $ids ) > 1500 || false === $s ) {
					break;
				}
			}
			if ( count( $ids ) > 1500 ) {
				return null;
			}
		}
		return 'SELECT object_id FROM ' . $d['table'] . ' WHERE object_id IN (' . ( $ids ? implode( ',', $ids ) : '0' ) . ')';
	}

	/**
	 * Page for a pattern whose text condition has a measured method.
	 *
	 * @param array $q Parsed query.
	 * @param array $d Table definition.
	 * @return string|null
	 */
	private function textpage( array $q, array $d ): ?string {
		if ( empty( $d['search'] ) || 'all' === $q['sel'] || $q['other'] ) {
			return null;
		}
		$l   = SpeedUp_Meta_Tables_Sql::conj( $q['where'] );
		$hit = null;
		foreach ( $l as $i => $n ) {
			$c = self::likeset( $n, $q['prim'] );
			if ( $c && isset( $d['search'][ implode( ',', $c[0] ) ] ) ) {
				$hit = array( $i, implode( ',', $c[0] ), $c[1] );
			} elseif ( 'meta' === $n[0] && 'LIKE' === $n[3] && '' === $n[2] && empty( $n[5] ) && isset( $d['search'][ $n[1] ] ) ) {
				$hit = array( $i, $n[1], $n[4][0] );
			}
		}
		if ( ! $hit ) {
			return null;
		}
		$ids = $this->textids( $d, $hit[1], $hit[2] );
		if ( ! $ids ) {
			return null;
		}
		unset( $l[ $hit[0] ] );
		$this->needp = false;
		$w           = $l ? $this->where( array( 'and', array_values( $l ) ), $d, $q ) : '1';
		foreach ( $q['order'] as $o ) {
			if ( 'meta' === $o[0] && ! $d['keys'][ $o[1] ]['row'] ) {
				return null;
			}
		}
		$ord = $this->order( $q, $d );
		if ( $this->needp ) {
			return null;
		}
		if ( 'chunk' === $d['search'][ $hit[1] ]['m'] ) {
			$from = "{$d['table']} h FORCE INDEX (PRIMARY)";
			$w   .= ' AND h.object_id' . substr( $ids, strpos( $ids, ' IN (' ) );
		} else {
			$from = "($ids) ti JOIN {$d['table']} h ON h.object_id=ti.object_id";
		}
		if ( 'count' === $q['sel'] ) {
			$ck = "$from|$w";
			return isset( $this->cnt[ $ck ] ) ? 'SELECT ' . $this->cnt[ $ck ] : "SELECT COUNT(*) FROM $from WHERE $w";
		}
		if ( 'order' === $q['ent'] && $q['limit'] ) {
			$this->pend = "$from|$w";
			$q['calc']  = true;
		}
		return 'SELECT ' . ( $q['calc'] ? 'SQL_CALC_FOUND_ROWS ' : '' ) . "h.object_id AS `{$q['id']}` FROM $from WHERE $w" . ( $ord ? ' ORDER BY ' . implode( ', ', $ord ) : '' ) . $this->limit( $q );
	}

	/* ---- hand-written SQL: each alias bound to one key is replaced by its column ---- */

	/**
	 * The table that holds a key for every object of a meta table: [definition, object kind], or null.
	 *
	 * @param string $meta   Meta table.
	 * @param string $key    Meta key.
	 * @param string $anchor Table to prefer.
	 * @return array|null
	 */
	public function keytab( string $meta, string $key, string $anchor = '' ) {
		foreach ( $this->reg as $d ) {
			if ( $anchor && $d['table'] === $anchor && isset( $d['keys'][ $key ]['casts'][''] ) ) {
				return array( $d, $this->def( $d['ent'] ) );
			}
			$x = $this->def( $d['ent'] );
			if ( $x['meta'] === $meta && isset( $d['keys'][ $key ]['casts'][''] ) && $d['keys'][ $key ]['only'] && empty( $d['keys'][ $key ]['off'] ) ) {
				return array( $d, $x );
			}
		}
		return null;
	}

	/**
	 * A derived table that looks like the meta rows of one key, or null.
	 *
	 * @param string $meta   Meta table.
	 * @param string $key    Meta key.
	 * @param string $anchor Table to prefer.
	 * @return string|null
	 */
	public function view( string $meta, string $key, string $anchor = '' ) {
		$r = $this->keytab( $meta, $key, $anchor );
		if ( ! $r ) {
			return null;
		}
		list( $d, $x ) = $r;
		$k             = $d['keys'][ $key ];
		$this->fresh( $d );
		if ( $k['row'] ) {
			return "SELECT object_id AS {$x['fk']}, {$k['casts']['']} AS meta_value FROM {$d['table']} WHERE {$k['casts']['']} IS NOT NULL";
		}
		return "SELECT object_id AS {$x['fk']}, v AS meta_value FROM {$k['vt']} WHERE key_id={$k['n']}";
	}

	/**
	 * Answers hand-written SQL.
	 *
	 * @param string $sql SQL.
	 * @return string|null
	 */
	public function raw( string $sql ): ?string {
		return SpeedUp_Meta_Tables_Raw::answer( $this, $sql );
	}

	/* ---- freshness ---- */

	/**
	 * Brings marked objects up to date before reading.
	 *
	 * @param array $d Table definition (or just its object kind).
	 * @throws DomainException When the query must go to the meta table instead.
	 */
	public function fresh( array $d ) {
		$ent                   = $d['ent'];
		$this->touched[ $ent ] = 1;
		if ( isset( $d['table'] ) ) {
			$this->used[ $d['table'] ] = 1;
			$this->used_now[]          = $d['table'];
		}
		if ( isset( $this->fr[ $ent ] ) ) {
			return;
		}
		$q   = $this->pre . 'queue';
		$r   = $this->rows( "SELECT object_id, ent, seq FROM $q WHERE ent=" . $this->e( $ent ) . " OR (ent='_v' AND object_id=1) LIMIT 53" );
		$ids = array();
		$ver = 0;
		foreach ( $r as $x ) {
			if ( '_v' === $x[1] ) {
				$ver = (int) $x[2];
			} else {
				$ids[] = $x[0];
			}
		}
		if ( $ver !== $this->ver ) {
			$this->load( true );
			throw new DomainException( 'version' );
		}
		if ( $ids ) {
			if ( count( $ids ) > 50 || in_array( '0', $ids, true ) ) {
				throw new DomainException( 'stale' );
			}
			$this->side(
				function () use ( $ent, $q ) {
					$c = array_column( $this->rows( "SELECT object_id FROM $q WHERE ent=" . $this->e( $ent ) . ' AND object_id>0 LIMIT 51 FOR UPDATE SKIP LOCKED' ), 0 );
					if ( $c ) {
						$this->refresh( $ent, $c );
						$this->rows( "DELETE FROM $q WHERE ent=" . $this->e( $ent ) . ' AND object_id IN (' . implode( ',', array_map( 'intval', $c ) ) . ')' );
						$this->refreshed += count( $c );
					}
				}
			);
			// What this connection still sees was written by itself in an open transaction, or is being refreshed elsewhere.
			if ( $this->rows( "SELECT 1 FROM $q WHERE ent=" . $this->e( $ent ) . ' LIMIT 1' ) ) {
				throw new DomainException( 'stale' );
			}
			if ( $this->changed ) {
				$this->changed = false;
				$this->load( true );
				throw new DomainException( 'changed' );
			}
		}
		$this->fr[ $ent ] = 1;
	}

	/**
	 * A write happened in this request: check again before the next read.
	 *
	 * @param string $ent Object kind.
	 */
	public function touch( string $ent ) {
		unset( $this->fr[ $ent ] );
		$this->cnt = array();
	}

	/**
	 * The side connection, opened on first use.
	 *
	 * @return mysqli
	 * @throws DomainException When it cannot be opened; the query then goes to the meta table.
	 */
	public function side_connection() {
		if ( ! $this->m2 ) {
			$m = ( $this->opt['connect'] )();
			if ( ! $m instanceof mysqli ) {
				throw new DomainException( 'no side connection' );
			}
			$this->m2 = $m;
			$this->m2->query( 'SET SESSION innodb_lock_wait_timeout=2' );
			$this->m2->query( 'SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED' );
		}
		return $this->m2;
	}

	/**
	 * Closes the side connection.
	 */
	public function close() {
		if ( $this->m2 ) {
			$this->m2->close();
			$this->m2 = null;
		}
	}

	/**
	 * Runs $f in a transaction on the side connection, so a transaction of the site is never ended or joined.
	 *
	 * @param callable $f Work.
	 * @return mixed
	 * @throws DomainException When the rows are busy.
	 */
	public function side( callable $f ) {
		$main    = $this->m;
		$this->m = $this->side_connection();
		try {
			for ( $try = 0; ; $try++ ) {
				$this->m->begin_transaction();
				try {
					$r = $f();
					$this->m->commit();
					return $r;
				} catch ( Throwable $e ) {
					$this->m->rollback();
					$this->changed = false;
					if ( $try > 0 || $e instanceof DomainException ) {
						++$this->busy;
						throw new DomainException( 'busy' );
					}
				}
			}
		} finally {
			$this->m = $main;
		}
	}

	/**
	 * Empties the queue of one object kind; object 0 means "unknown objects": everything of that kind is compared
	 * and repaired. Returns the number of objects refreshed.
	 *
	 * @param string $ent Object kind, or "k" for the meta key list.
	 * @return int
	 */
	public function drain( string $ent ): int {
		if ( ! $this->reg ) {
			return 0;
		}
		$q = $this->pre . 'queue';
		if ( 'k' === $ent ) {
			$z = $this->rows( "SELECT seq FROM $q WHERE ent='k' AND object_id=0" );
			if ( $z ) {
				$this->fill_keys();
				$this->rows( "DELETE FROM $q WHERE ent='k' AND object_id=0 AND seq=" . (int) $z[0][0] );
			}
			return count( $z );
		}
		$x = $this->def( $ent );
		if ( ! $x ) {
			return 0;
		}
		$n = 0;
		while ( true ) {
			$c = $this->side(
				function () use ( $ent, $q ) {
					$c = array_column( $this->rows( "SELECT object_id FROM $q WHERE ent=" . $this->e( $ent ) . ' AND object_id>0 LIMIT 100 FOR UPDATE SKIP LOCKED' ), 0 );
					if ( $c ) {
						$this->refresh( $ent, $c );
						$this->rows( "DELETE FROM $q WHERE ent=" . $this->e( $ent ) . ' AND object_id IN (' . implode( ',', array_map( 'intval', $c ) ) . ')' );
					}
					return $c;
				}
			);
			if ( ! $c ) {
				break;
			}
			$n += count( $c );
		}
		$z = $this->rows( "SELECT seq FROM $q WHERE ent=" . $this->e( $ent ) . ' AND object_id=0' );
		if ( ! $z ) {
			return $n;
		}
		$at = 0;
		while ( true ) {
			$l = array_column( $this->rows( "SELECT {$x['id']} FROM {$x['prim']} WHERE {$x['id']}>$at ORDER BY 1 LIMIT 500" ), 0 );
			if ( ! $l ) {
				break;
			}
			$to = (int) end( $l );
			foreach ( $this->reg as $d ) {
				if ( $d['ent'] === $ent ) {
					$l = array_merge( $l, array_column( $this->rows( "SELECT object_id FROM {$d['table']} WHERE object_id>$at AND object_id<=$to" ), 0 ) );
				}
			}
			$at   = $to;
			$list = array_values( array_unique( $l ) );
			$this->side( fn() => $this->refresh( $ent, $list ) );
			$n += count( $list );
		}
		foreach ( $this->reg as $d ) {
			if ( $d['ent'] !== $ent ) {
				continue;
			}
			while ( true ) {
				$l = array_column( $this->rows( "SELECT object_id FROM {$d['table']} WHERE object_id>$at LIMIT 500" ), 0 );
				if ( ! $l ) {
					break;
				}
				$this->side( fn() => $this->refresh( $ent, $l ) );
			}
		}
		$this->rows( "DELETE FROM $q WHERE ent=" . $this->e( $ent ) . ' AND object_id=0 AND seq=' . (int) $z[0][0] );
		return $n;
	}

	/**
	 * The structure changed (a key is no longer usable, a new value of a few-valued key): a new version is written
	 * before the notes are removed.
	 */
	private function save_reg() {
		$this->publish( $this->reg, 'on' );
		$this->changed = true;
	}

	/**
	 * Copies the current meta rows of some objects into every table of their kind.
	 *
	 * @param string $ent Object kind.
	 * @param array  $ids Object ids.
	 */
	public function refresh( string $ent, array $ids ) {
		$this->touched[ $ent ] = 1;
		$dirty                 = false;
		$l                     = implode( ',', array_map( 'intval', $ids ) );
		$x                     = $this->def( $ent );
		$dk                    = array();
		foreach ( $this->reg as $d ) {
			if ( $d['ent'] === $ent ) {
				foreach ( $d['keys'] as $k => $ki ) {
					if ( ! empty( $ki['dict'] ) ) {
						$dk[ $k ] = 1;
					}
				}
			}
		}
		if ( $dk ) {
			$seen = array();
			foreach ( $this->rows( "SELECT DISTINCT meta_key, meta_value FROM {$x['meta']} WHERE {$x['fk']} IN ($l) AND meta_key IN " . $this->inlist( array_keys( $dk ) ) ) as $r ) {
				$seen[ $r[0] ][] = $r[1];
			}
			foreach ( $this->reg as $i => $d ) {
				if ( $d['ent'] !== $ent ) {
					continue;
				}
				foreach ( $d['keys'] as $k => $ki ) {
					if ( empty( $ki['dict'] ) || ! isset( $seen[ $k ] ) ) {
						continue;
					}
					foreach ( $seen[ $k ] as $v ) {
						if ( ! in_array( $v, $ki['dict'], true ) ) {
							$this->reg[ $i ]['keys'][ $k ]['dict'][] = $v;
							$dirty                                   = true;
						}
					}
				}
			}
		}
		$ty   = array();
		$gone = false;
		$tcol = array(
			'post'  => 'post_type',
			'order' => 'type',
		)[ $ent ] ?? null;
		if ( $tcol ) {
			$ty   = array_column( $this->rows( "SELECT DISTINCT $tcol FROM {$x['prim']} WHERE {$x['id']} IN ($l)" ), 0 );
			$gone = count( $ids ) > (int) $this->rows( "SELECT COUNT(*) FROM {$x['prim']} WHERE {$x['id']} IN ($l)" )[0][0];
		}
		// A second row, a longer value, or a key appearing on another type makes a key unusable until it is rebuilt.
		$wk = array();
		foreach ( $this->reg as $i => $d ) {
			if ( $d['ent'] === $ent ) {
				foreach ( $d['keys'] as $k => $ki ) {
					$wk[ $k ][ $i ] = $ki;
				}
			}
		}
		if ( $wk ) {
			foreach ( $this->rows( 'SELECT m.meta_key, ' . ( $tcol ? "p.$tcol" : "''" ) . ", MAX(c), MAX(l), MAX(z) FROM (SELECT meta_key, {$x['fk']} o, COUNT(*) c, MAX(CHAR_LENGTH(meta_value)) l, MAX(meta_value IS NULL) z FROM {$x['meta']} WHERE {$x['fk']} IN ($l) AND meta_key IN " . $this->inlist( array_keys( $wk ) ) . " GROUP BY meta_key, {$x['fk']}) m JOIN {$x['prim']} p ON p.{$x['id']}=m.o GROUP BY 1,2" ) as $r ) {
				foreach ( $wk[ $r[0] ] ?? array() as $i => $ki ) {
					if ( ! empty( $ki['off'] ) ) {
						continue;
					}
					$mine = ! $this->reg[ $i ]['types'] || in_array( $r[1], $this->reg[ $i ]['types'], true );
					if ( $mine && $ki['row'] && ( $r[2] > 1 || ( isset( $ki['casts'][''] ) && $r[3] > $ki['cap'] ) || ( $r[4] && empty( $ki['nulls'] ) ) ) ) {
						$this->reg[ $i ]['keys'][ $r[0] ]['off'] = true;
						$dirty                                   = true;
					}
					if ( ! $mine && $ki['only'] ) {
						$this->reg[ $i ]['keys'][ $r[0] ]['only'] = false;
						$dirty                                    = true;
					}
				}
			}
		}
		if ( $dirty ) {
			$this->save_reg();
		}
		foreach ( $this->reg as $d ) {
			if ( $d['ent'] !== $ent ) {
				continue;
			}
			if ( $tcol && ! $gone && ! array_intersect( $ty, $d['types'] ) && ! $this->rows( "SELECT 1 FROM {$d['table']} WHERE object_id IN ($l) LIMIT 1" ) ) {
				continue;
			}
			$old = array();
			foreach ( $d['search'] ?? array() as $g => $s ) {
				if ( 'gram' === $s['m'] ) {
					$old[ $g ] = $this->rows( 'SELECT h.object_id, ' . $this->textexpr( $s['cols'], $d ) . " FROM {$d['table']} h WHERE h.object_id IN ($l)" );
				}
			}
			$this->tally( $d, "AND object_id IN ($l)", -1 );
			$this->rows( 'REPLACE ' . sprintf( $d['insert'], "AND p.{$x['id']} IN ($l)" ) );
			$this->refold( $d, "AND object_id IN ($l)" );
			$this->rows( "DELETE h FROM {$d['table']} h LEFT JOIN {$x['prim']} p ON p.{$x['id']}=h.object_id WHERE h.object_id IN ($l) AND p.{$x['id']} IS NULL" );
			$this->tally( $d, "AND object_id IN ($l)", 1 );
			foreach ( $d['search'] ?? array() as $g => $s ) {
				$tn = array_filter( $s['cols'], fn( $k ) => '@' === $k[0] );
				if ( $tn && $this->rows( "SELECT 1 FROM {$d['table']} WHERE object_id IN ($l) AND (" . implode( ' OR ', array_map( fn( $k ) => 't_' . substr( $k, 1 ) . ' IS NULL', $tn ) ) . ') LIMIT 1' ) ) {
					// A text too long for the copy: this search goes back to a scan.
					foreach ( $this->reg as $ri => $rd ) {
						if ( $rd['table'] === $d['table'] ) {
							unset( $this->reg[ $ri ]['search'][ $g ] );
						}
					}
					$this->save_reg();
					continue;
				}
				$this->textrefresh( $d, $s, $l, $old[ $g ] ?? array() );
			}
			foreach ( $d['sets'] ?? array() as $s ) {
				$this->rows( "DELETE FROM {$s['t']} WHERE object_id IN ($l)" );
				$this->rows( 'INSERT ' . sprintf( $s['ins'], "AND h.object_id IN ($l)" ) );
			}
			foreach ( $d['vinsert'] ?? array() as $k => $s ) {
				$this->rows( "DELETE FROM {$d['table']}_v WHERE key_id={$d['keys'][$k]['n']} AND object_id IN ($l)" );
				$this->rows( 'INSERT ' . sprintf( $s, "AND m.{$x['fk']} IN ($l)" ) );
			}
		}
	}
}
