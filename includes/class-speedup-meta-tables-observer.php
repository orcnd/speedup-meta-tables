<?php
/**
 * Learns which meta queries the site runs.
 *
 * @package SpeedUp_Meta_Tables
 */

defined( 'ABSPATH' ) || exit;

/**
 * Records the shape of every meta query that was not answered: which keys, casts, operators, order and types it
 * uses. Values are not stored, except empty strings and numbers compared with < > <= >= != (a number that never
 * changes becomes a flag column). Search terms are replaced by a neutral sample.
 *
 * Only one request in N records (the filter speedup_meta_tables_sample_rate, 10 by default), and its counts are
 * multiplied by N.
 */
class SpeedUp_Meta_Tables_Observer {

	/** Neutral search term used to measure text search methods. */
	const SAMPLE = 'ann';

	/**
	 * Whether this request records.
	 *
	 * @var bool
	 */
	private static $on = false;

	/**
	 * Weight of one sighting.
	 *
	 * @var int
	 */
	private static $rate = 10;

	/**
	 * Shapes seen in this request: hash => [shape, hits, literals].
	 *
	 * @var array
	 */
	private static $seen = array();

	/**
	 * Decides whether this request records.
	 *
	 * @param bool $reads Whether reads are answered in this request.
	 */
	public static function boot( bool $reads ) {
		/**
		 * One request in this many records the meta queries it runs.
		 *
		 * @param int $rate Sample rate, 1 records every request.
		 */
		self::$rate = max( 1, (int) apply_filters( 'speedup_meta_tables_sample_rate', 10 ) );
		self::$on   = $reads && 1 === random_int( 1, self::$rate );
	}

	/**
	 * Looks at a meta SELECT that was not answered.
	 *
	 * @param string $sql SQL.
	 */
	public static function see( string $sql ) {
		if ( ! self::$on || count( self::$seen ) > 200 ) {
			return;
		}
		$shapes = array();
		$q      = SpeedUp_Meta_Tables_Sql::parse( $sql );
		if ( $q ) {
			if ( null === SpeedUp_Meta_Tables_Sql::types( $q ) ) {
				return;
			}
			$shapes[] = $q;
		} else {
			$shapes = self::raw( $sql );
		}
		foreach ( $shapes as $s ) {
			$s    = self::clean( $s );
			$lit  = empty( $s['raw'] ) ? self::literals( $s['where'] ) : array();
			$hash = md5( (string) wp_json_encode( array( $s['ent'], $s['raw'] ?? false, SpeedUp_Meta_Tables_Sql::needs( $s ), $s['types'] ?? SpeedUp_Meta_Tables_Sql::types( $s ), $s['order'], self::skeleton( $s['where'] ), $s['sets'] ?? array(), $s['sums'] ?? array() ) ) );
			if ( isset( self::$seen[ $hash ] ) ) {
				++self::$seen[ $hash ][1];
			} else {
				self::$seen[ $hash ] = array( $s, 1, $lit );
			}
		}
	}

	/**
	 * Writes what this request saw.
	 */
	public static function flush() {
		global $wpdb;
		if ( ! self::$seen ) {
			return;
		}
		$m    = $wpdb->dbh;
		$rows = array();
		foreach ( self::$seen as $hash => $x ) {
			list( $s, $n, $lit ) = $x;
			$types               = $s['types'] ?? SpeedUp_Meta_Tables_Sql::types( $s );
			$rows[]              = "('" . $m->real_escape_string( $hash ) . "','" . $m->real_escape_string( $s['ent'] ) . "','" . $m->real_escape_string( null === $types ? '?' : implode( ',', $types ) ) . "','" . $m->real_escape_string( (string) wp_json_encode( $s ) ) . "'," . ( $n * self::$rate ) . ",'" . $m->real_escape_string( implode( "\n", $lit ) ) . "',0,UTC_TIMESTAMP(),UTC_TIMESTAMP())";
		}
		self::$seen = array();
		$t          = SpeedUp_Meta_Tables::table( 'shapes' );
		$m->query( "INSERT INTO $t (hash, ent, types, shape, hits, lit, varies, first_seen, last_seen) VALUES " . implode( ',', $rows ) . ' ON DUPLICATE KEY UPDATE hits=hits+VALUES(hits), varies=varies OR lit<>VALUES(lit), last_seen=VALUES(last_seen)' );
	}

	/**
	 * Replaces the values of a parsed query that the build does not need.
	 *
	 * @param array $q Parsed query.
	 * @return array
	 */
	public static function clean( array $q ): array {
		$q['where'] = self::clean_node( $q['where'] );
		foreach ( $q['order'] as $i => $o ) {
			if ( 'raw' === $o[0] ) {
				$q['order'][ $i ][1] = self::clean_text( $o[1] );
			}
		}
		$q['limit'] = $q['limit'] ? array( 0, 20 ) : null;
		return $q;
	}

	/**
	 * Cleans one node.
	 *
	 * @param array $n Node.
	 * @return array
	 */
	private static function clean_node( array $n ): array {
		if ( 'and' === $n[0] || 'or' === $n[0] ) {
			$n[1] = array_map( array( __CLASS__, 'clean_node' ), $n[1] );
			return $n;
		}
		if ( 'meta' === $n[0] ) {
			$n[4] = self::clean_values( $n[3], $n[4] );
			foreach ( $n[5] ?? array() as $i => $x ) {
				$n[5][ $i ][2] = self::clean_values( $x[1], $x[2] );
			}
			return $n;
		}
		if ( 'core' === $n[0] && ! in_array( $n[1], array( 'post_type', 'post_status', 'type', 'status' ), true ) ) {
			$n[3] = array_map( fn() => '0', $n[3] );
			return $n;
		}
		if ( 'raw' === $n[0] ) {
			$n[1] = self::clean_text( $n[1] );
		}
		return $n;
	}

	/**
	 * Values of one comparison: empty strings and range numbers stay, a LIKE keeps its wildcards.
	 *
	 * @param string $op Operator.
	 * @param array  $v  Values.
	 * @return array
	 */
	private static function clean_values( string $op, array $v ): array {
		return array_map(
			function ( $x ) use ( $op ) {
				$x = (string) $x;
				if ( '' === $x ) {
					return '';
				}
				if ( str_contains( $op, 'LIKE' ) ) {
					return ( str_starts_with( $x, '%' ) ? '%' : '' ) . self::SAMPLE . ( str_ends_with( $x, '%' ) ? '%' : '' );
				}
				if ( in_array( $op, array( '<', '>', '<=', '>=', '!=' ), true ) && is_numeric( $x ) ) {
					return $x;
				}
				return 'x';
			},
			$v
		);
	}

	/**
	 * Hand-written condition text with its quoted values replaced.
	 *
	 * @param string $s Text.
	 * @return string
	 */
	private static function clean_text( string $s ): string {
		return preg_replace_callback(
			"/'((?:[^'\\\\]|\\\\.)*)'/",
			function ( $m ) {
				if ( '' === $m[1] ) {
					return "''";
				}
				return "'" . ( str_starts_with( $m[1], '%' ) ? '%' : '' ) . self::SAMPLE . ( str_ends_with( $m[1], '%' ) ? '%' : '' ) . "'";
			},
			$s
		);
	}

	/**
	 * The condition tree without values, for the hash.
	 *
	 * @param array $n Node.
	 * @return array
	 */
	private static function skeleton( array $n ): array {
		if ( 'and' === $n[0] || 'or' === $n[0] ) {
			return array( $n[0], array_map( array( __CLASS__, 'skeleton' ), $n[1] ) );
		}
		if ( 'meta' === $n[0] ) {
			return array( 'meta', $n[1], $n[2], $n[3], array_map( fn( $x ) => '' === $x ? '' : '?', $n[4] ), array_map( fn( $x ) => array( $x[0], $x[1] ), $n[5] ?? array() ) );
		}
		if ( 'core' === $n[0] ) {
			return array( 'core', $n[1], $n[2], in_array( $n[1], array( 'post_type', 'type', 'post_status', 'status' ), true ) ? $n[3] : count( $n[3] ) );
		}
		return $n;
	}

	/**
	 * Range comparisons with a number: "key|cast|operator|number".
	 *
	 * @param array $n Node.
	 * @return array
	 */
	private static function literals( array $n ): array {
		$o = array();
		SpeedUp_Meta_Tables_Sql::walk(
			$n,
			function ( $x ) use ( &$o ) {
				$p = SpeedUp_Meta_Tables_Engine::predkey( $x );
				if ( null !== $p && is_numeric( $x[4][0] ) ) {
					$o[] = $p;
				}
			}
		);
		sort( $o );
		return array_values( array_unique( $o ) );
	}

	/**
	 * Shapes of the hand-written WooCommerce statements the engine knows, with the keys they need.
	 *
	 * @param string $sql SQL.
	 * @return array
	 */
	public static function raw( string $sql ): array {
		global $wpdb;
		$p = preg_quote( $wpdb->prefix, '/' );
		$f = preg_replace( '/\s+/', ' ', $sql );
		if ( str_contains( $f, $wpdb->prefix . 'wc_product_meta_lookup' ) && str_contains( $f, "'_low_stock_amount'" ) ) {
			return array( self::products() );
		}
		if ( preg_match( "/FROM {$p}postmeta as postmeta LEFT JOIN {$p}postmeta as postmeta_2 ON .* WHERE postmeta\.meta_key = '_sale_price_dates_(?:from|to)'/", $f ) ) {
			return array( self::products() );
		}
		if ( preg_match( "/^ ?SELECT SUM\(postmeta\.meta_value\) FROM {$p}posts AS posts .* WHERE postmeta\.meta_key = '(\w+)' AND postmeta\.meta_value = '\w+' AND posts\.post_type = '(\w+)' .* AND postmeta\.meta_key = '(\w+)' ?$/", $f, $m ) ) {
			$s         = self::shape(
				'post',
				array( $m[2] ),
				array(
					$m[1] => array( array( '', '=' ) ),
					$m[3] => array( array( '', 'LIKE' ), array( '+0', '>' ) ),
				)
			);
			$s['sums'] = array( array( $m[1], $m[3] ) );
			return array( $s );
		}
		if ( preg_match( "/^\s*SELECT DISTINCT \w+\.post_id FROM {$p}postmeta \w+ WHERE \w+\.meta_value LIKE '(?:[^'\\\\]|\\\\.)*' AND \w+\.meta_key IN \(([^)]*)\)\s*$/s", $f, $m ) ) {
			return array( self::search( 'post', $m[1] ) );
		}
		if ( preg_match( "/IN \( SELECT \w+\.order_id FROM {$p}wc_orders_meta as \w+ WHERE \w+\.meta_key IN \(([^)]*)\) AND \w+\.meta_value LIKE /s", $f, $m ) ) {
			return array( self::search( 'order', $m[1] ) );
		}
		if ( preg_match( "/^ ?select count\(\*\) as total from {$p}postmeta where meta_key='(\w+)' AND meta_value/i", $f, $m ) ) {
			return array( self::shape( 'post', null, array( $m[1] => array( array( '', '=' ) ) ) ) );
		}
		if ( preg_match( '/^\s*SELECT DISTINCT meta_key\s/i', $f ) ) {
			return array();
		}
		// Hand-written SQL that binds each meta alias to one key.
		if ( ! preg_match_all( '/\b(' . $p . 'postmeta|' . $p . 'usermeta|' . $p . 'wc_orders_meta)\s+(?:AS\s+)?(?!WHERE|ON|LEFT|INNER|JOIN)(\w+)/i', $sql, $all, PREG_SET_ORDER ) ) {
			return array();
		}
		$by = array();
		foreach ( $all as $x ) {
			if ( ! preg_match( "/\b{$x[2]}\.meta_key\s*=\s*'(\w+)'/", $sql, $k ) ) {
				return array();
			}
			$ent                 = array(
				$wpdb->prefix . 'postmeta'       => 'post',
				$wpdb->prefix . 'usermeta'       => 'user',
				$wpdb->prefix . 'wc_orders_meta' => 'order',
			)[ $x[1] ];
			$by[ $ent ][ $k[1] ] = array( array( '', '=' ) );
		}
		$o = array();
		foreach ( $by as $ent => $keys ) {
			$o[] = self::shape( $ent, 'user' === $ent ? array() : null, $keys );
		}
		return $o;
	}

	/**
	 * The product keys of WooCommerce's stock and scheduled sale statements.
	 *
	 * @return array
	 */
	private static function products(): array {
		$s = self::shape(
			'post',
			array( 'product', 'product_variation' ),
			array(
				'_low_stock_amount'      => array( array( '', 'LIKE' ) ),
				'_price'                 => array( array( '', 'LIKE' ) ),
				'_sale_price'            => array( array( '', 'LIKE' ) ),
				'_regular_price'         => array( array( '', 'LIKE' ) ),
				'_sale_price_dates_from' => array( array( '', 'LIKE' ), array( '+0', '>' ) ),
				'_sale_price_dates_to'   => array( array( '', 'LIKE' ), array( '+0', '>' ) ),
			)
		);
		return $s;
	}

	/**
	 * A text search over several order keys.
	 *
	 * @param string $ent  Object kind.
	 * @param string $keys_sql Quoted key list.
	 * @return array
	 */
	private static function search( string $ent, string $keys_sql ): array {
		preg_match_all( "/'(\w+)'/", $keys_sql, $k );
		$keys = array();
		foreach ( $k[1] as $x ) {
			$keys[ $x ] = array( array( '', 'LIKE' ) );
		}
		$s         = self::shape( $ent, array( 'shop_order' ), $keys );
		$s['sets'] = array( $k[1] );
		return $s;
	}

	/**
	 * A shape for hand-written SQL: the keys with their casts and operators, and the types (null: found at build time).
	 *
	 * @param string     $ent   Object kind.
	 * @param array|null $types Types.
	 * @param array      $keys  Key => [[cast, operator]].
	 * @return array
	 */
	private static function shape( string $ent, ?array $types, array $keys ): array {
		$w = array();
		if ( $types ) {
			$w[] = array( 'core', 'post' === $ent ? 'post_type' : 'type', 'IN', $types );
		}
		foreach ( $keys as $k => $ops ) {
			foreach ( $ops as $o ) {
				$w[] = array( 'meta', $k, $o[0], $o[1], array( '0' ), array() );
			}
		}
		return array(
			'ent'   => $ent,
			'where' => array( 'and', $w ),
			'order' => array(),
			'limit' => null,
			'raw'   => true,
			'types' => $types,
			'sets'  => array(),
		);
	}
}
