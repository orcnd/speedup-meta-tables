<?php
/**
 * Reads the SELECT statements WordPress and WooCommerce build over a key-value meta table.
 *
 * @package SpeedUp_Meta_Tables
 */

defined( 'ABSPATH' ) || exit;

/**
 * Turns the meta SQL of WP_Query, WP_User_Query and WooCommerce's order query into a tree the engine can answer.
 *
 * Anything it cannot account for completely makes parse() return null, and the original SQL runs.
 */
class SpeedUp_Meta_Tables_Sql {

	/**
	 * The object kinds, keyed by their main table: entity name, id column, meta table, meta foreign key and the
	 * columns of the main table the engine copies.
	 *
	 * @var array
	 */
	public static $ent = array();

	/**
	 * Sets the table names for a table prefix.
	 *
	 * @param string $prefix The WordPress table prefix.
	 */
	public static function init( string $prefix ) {
		self::$ent = array(
			$prefix . 'posts'     => array(
				'ent'  => 'post',
				'id'   => 'ID',
				'meta' => $prefix . 'postmeta',
				'fk'   => 'post_id',
				'core' => array( 'post_type', 'post_status', 'post_date', 'ID' ),
			),
			$prefix . 'users'     => array(
				'ent'  => 'user',
				'id'   => 'ID',
				'meta' => $prefix . 'usermeta',
				'fk'   => 'user_id',
				'core' => array( 'user_login', 'ID' ),
			),
			$prefix . 'wc_orders' => array(
				'ent'  => 'order',
				'id'   => 'id',
				'meta' => $prefix . 'wc_orders_meta',
				'fk'   => 'order_id',
				'core' => array( 'type', 'status', 'date_created_gmt', 'id' ),
			),
		);
	}

	/**
	 * Splits SQL into tokens: quoted strings, identifiers (backticks removed), numbers, words and operators.
	 *
	 * @param string $s SQL.
	 * @return array
	 */
	public static function tokens( string $s ): array {
		preg_match_all( "/'(?:[^'\\\\]|\\\\.|'')*'|`[^`]+`(?:\\.`?\\w+`?)?|\\w+\\.`?\\w+`?|\\d+\\.\\d+|-?\\d+(?=[\\s,)])|\\w+|<=|>=|!=|<>|[-+*\\/=<>(),;.]/s", $s, $m );
		$o = array();
		foreach ( $m[0] as $t ) {
			$o[] = "'" === $t[0] ? $t : str_replace( '`', '', $t );
		}
		return $o;
	}

	/**
	 * The value of a literal token.
	 *
	 * @param string $t Token.
	 * @return string
	 */
	public static function lit( string $t ) {
		return "'" === $t[0] ? stripslashes( str_replace( "''", "'", substr( $t, 1, -1 ) ) ) : $t;
	}

	/**
	 * Position of the parenthesis that closes the one at $i, or -1.
	 *
	 * @param array $t Tokens.
	 * @param int   $i Position of an opening parenthesis.
	 * @return int
	 */
	public static function close( array $t, int $i ): int {
		$d = 0;
		for ( $n = count( $t ); $i < $n; $i++ ) {
			if ( '(' === $t[ $i ] ) {
				++$d;
			} elseif ( ')' === $t[ $i ] ) {
				--$d;
				if ( 0 === $d ) {
					return $i;
				}
			}
		}
		return -1;
	}

	/**
	 * Splits tokens on a top-level word; a BETWEEN keeps its AND.
	 *
	 * @param array  $t    Tokens.
	 * @param string $word AND or OR.
	 * @return array
	 */
	public static function split( array $t, string $word ): array {
		$o       = array();
		$c       = array();
		$d       = 0;
		$between = false;
		foreach ( $t as $x ) {
			$u = strtoupper( $x );
			if ( '(' === $x ) {
				++$d;
			} elseif ( ')' === $x ) {
				--$d;
			}
			if ( 0 === $d ) {
				if ( 'BETWEEN' === $u ) {
					$between = true;
				} elseif ( 'AND' === $u && $between ) {
					$between = false;
					$c[]     = $x;
					continue;
				}
				if ( $u === $word ) {
					$o[] = $c;
					$c   = array();
					continue;
				}
			}
			$c[] = $x;
		}
		$o[] = $c;
		return $o;
	}

	/**
	 * A boolean tree of AND, OR and leaves.
	 *
	 * @param array $t Tokens.
	 * @return array
	 */
	public static function tree( array $t ) {
		while ( $t && '(' === $t[0] ) {
			$last = count( $t ) - 1;
			if ( self::close( $t, 0 ) !== $last ) {
				break;
			}
			$t = array_slice( $t, 1, -1 );
		}
		foreach ( array( 'OR', 'AND' ) as $w ) {
			$p = self::split( $t, $w );
			if ( count( $p ) > 1 ) {
				return array( strtolower( $w ), array_map( array( __CLASS__, 'tree' ), $p ) );
			}
		}
		return array( 'leaf', $t );
	}

	/**
	 * Meta aliases: alias => [left join, meta key or null].
	 *
	 * @var array
	 */
	private $al = array();

	/**
	 * The object kind of the statement.
	 *
	 * @var array
	 */
	private $def;

	/**
	 * Alias => the one key its conditions name (null when several).
	 *
	 * @var array
	 */
	private $keys = array();

	/**
	 * Aliases used by a condition.
	 *
	 * @var array
	 */
	private $used = array();

	/**
	 * Parses a SELECT over a meta table, or returns null.
	 *
	 * @param string $sql SQL.
	 * @return array|null
	 */
	public static function parse( string $sql ): ?array {
		if ( ! str_contains( $sql, 'meta' ) || 0 !== strncasecmp( ltrim( $sql ), 'select', 6 ) ) {
			return null;
		}
		if ( ! self::$ent ) {
			global $wpdb;
			self::init( $wpdb->prefix );
		}
		$p = new self();
		return $p->read( self::tokens( $sql ) );
	}

	/**
	 * Reads the clauses of one statement.
	 *
	 * @param array $t Tokens.
	 * @return array|null
	 */
	private function read( array $t ): ?array {
		$upper = array_map( 'strtoupper', $t );
		$n     = count( $t );
		$pos   = array();
		$d     = 0;
		for ( $i = 0; $i < $n; $i++ ) {
			if ( '(' === $t[ $i ] ) {
				++$d;
			} elseif ( ')' === $t[ $i ] ) {
				--$d;
			} elseif ( 0 === $d && in_array( $upper[ $i ], array( 'SELECT', 'FROM', 'WHERE', 'GROUP', 'ORDER', 'LIMIT', 'HAVING', 'UNION' ), true ) ) {
				if ( isset( $pos[ $upper[ $i ] ] ) ) {
					return null;
				}
				$pos[ $upper[ $i ] ] = $i;
			}
		}
		if ( ! isset( $pos['FROM'], $pos['WHERE'] ) || isset( $pos['HAVING'] ) || isset( $pos['UNION'] ) ) {
			return null;
		}
		$end  = function ( $k ) use ( $pos, $n ) {
			$e = $n;
			foreach ( $pos as $x ) {
				if ( $x > $pos[ $k ] && $x < $e ) {
					$e = $x;
				}
			}
			return $e;
		};
		$part = function ( $k, $skip = 1 ) use ( $pos, $t, $end ) {
			return isset( $pos[ $k ] ) ? array_slice( $t, $pos[ $k ] + $skip, $end( $k ) - $pos[ $k ] - $skip ) : array();
		};
		$q    = array(
			'distinct' => false,
			'calc'     => false,
		);
		$sel  = $part( 'SELECT' );
		while ( $sel && in_array( strtoupper( $sel[0] ), array( 'DISTINCT', 'SQL_CALC_FOUND_ROWS' ), true ) ) {
			$flag       = 'DISTINCT' === strtoupper( $sel[0] ) ? 'distinct' : 'calc';
			$q[ $flag ] = true;
			array_shift( $sel );
		}
		$from = $part( 'FROM' );
		$prim = $from[0] ?? '';
		if ( ! isset( self::$ent[ $prim ] ) ) {
			return null;
		}
		$this->def = self::$ent[ $prim ];
		$q['prim'] = $prim;
		$q        += $this->def;
		$idc       = $prim . '.' . $q['id'];
		$s         = implode( ' ', $sel );
		if ( $s === $idc ) {
			$q['sel'] = 'id';
		} elseif ( 0 === strcasecmp( $s, "COUNT ( DISTINCT $idc )" ) || 0 === strcasecmp( $s, 'COUNT ( * )' ) ) {
			$q['sel'] = 'count';
		} elseif ( "$prim . *" === $s || "$prim.*" === $s ) {
			$q['sel'] = 'all';
		} else {
			return null;
		}
		$other = $this->joins( $from, $q, $idc );
		if ( null === $other || ! $this->al ) {
			return null;
		}
		$q['other'] = $other;
		$w          = $this->node( self::tree( $part( 'WHERE' ) ) );
		if ( null === $w ) {
			return null;
		}
		$q['where'] = self::flat( $w );
		$g          = $part( 'GROUP', 2 );
		if ( $g && implode( ' ', $g ) !== $idc ) {
			return null;
		}
		$q['order']  = array();
		$q['dedupe'] = $g || $q['distinct'] || ( 'count' === $q['sel'] && str_contains( $s, 'DISTINCT' ) );
		foreach ( isset( $pos['ORDER'] ) ? self::splitc( $part( 'ORDER', 2 ) ) : array() as $o ) {
			$dir  = 'ASC';
			$last = strtoupper( end( $o ) );
			if ( 'ASC' === $last || 'DESC' === $last ) {
				$dir = $last;
				array_pop( $o );
			}
			$v = $this->val( $o );
			if ( $v ) {
				$k = $this->al[ $v[0] ]['key'] ?? $this->keys[ $v[0] ] ?? null;
				if ( null === $k ) {
					return null;
				}
				$q['order'][] = array( 'meta', $k, $v[1], $dir );
				continue;
			}
			if ( $this->refs( $o ) ) {
				return null;
			}
			$c            = $this->col( $o );
			$q['order'][] = $c ? array( 'core', $c, '', $dir ) : array( 'raw', implode( ' ', $o ), '', $dir );
		}
		$q['limit'] = null;
		if ( isset( $pos['LIMIT'] ) ) {
			$l          = array_values( array_filter( $part( 'LIMIT' ), fn( $x ) => ',' !== $x && ';' !== $x ) );
			$q['limit'] = count( $l ) > 1 ? array( (int) $l[0], (int) $l[1] ) : array( 0, (int) $l[0] );
		}
		// An INNER JOIN alias that no condition uses would require "has any meta row".
		foreach ( $this->al as $a => $x ) {
			if ( ! $x['left'] && empty( $this->keys[ $a ] ) && empty( $this->used[ $a ] ) ) {
				return null;
			}
		}
		return $q;
	}

	/**
	 * Reads the joins: meta aliases go to $this->al, other joins are returned as text.
	 *
	 * @param array  $j   Tokens of the FROM clause.
	 * @param array  $q   The statement so far.
	 * @param string $idc The id column with its table.
	 * @return array|null
	 */
	private function joins( array $j, array $q, string $idc ): ?array {
		$other = array();
		$i     = 1;
		$m     = count( $j );
		while ( $i < $m ) {
			$st   = $i;
			$type = strtoupper( $j[ $i ] );
			if ( ! in_array( $type, array( 'INNER', 'LEFT', 'JOIN' ), true ) ) {
				return null;
			}
			$i  += 'JOIN' === $type ? 1 : 2;
			$tab = $j[ $i++ ] ?? '';
			$as  = $tab;
			if ( 'AS' === strtoupper( $j[ $i ] ?? '' ) ) {
				++$i;
				$as = $j[ $i++ ] ?? '';
			} elseif ( 'ON' !== strtoupper( $j[ $i ] ?? '' ) ) {
				$as = $j[ $i++ ] ?? '';
			}
			if ( 'ON' !== strtoupper( $j[ $i ] ?? '' ) ) {
				return null;
			}
			++$i;
			$e = '(' === ( $j[ $i ] ?? '' ) ? self::close( $j, $i ) : -1;
			if ( $e < 0 ) {
				$e = $i;
				while ( $e + 1 < $m && ! in_array( strtoupper( $j[ $e + 1 ] ), array( 'INNER', 'LEFT', 'JOIN' ), true ) ) {
					++$e;
				}
			}
			$on = array_slice( $j, $i, $e - $i + 1 );
			$i  = $e + 1;
			if ( $tab !== $q['meta'] ) {
				$other[] = implode( ' ', array_slice( $j, $st, $i - $st ) );
				continue;
			}
			$key = null;
			$ok  = false;
			$x   = self::tree( $on );
			foreach ( 'and' === $x[0] ? $x[1] : array( $x ) as $l ) {
				if ( 'leaf' !== $l[0] ) {
					return null;
				}
				$l = $l[1];
				if ( 3 !== count( $l ) || '=' !== $l[1] ) {
					return null;
				}
				if ( ( $l[0] === $idc && "$as.{$q['fk']}" === $l[2] ) || ( $l[2] === $idc && "$as.{$q['fk']}" === $l[0] ) ) {
					$ok = true;
				} elseif ( "$as.meta_key" === $l[0] ) {
					$key = self::lit( $l[2] );
				} else {
					return null;
				}
			}
			if ( ! $ok ) {
				return null;
			}
			$this->al[ $as ] = array(
				'left' => 'LEFT' === $type,
				'key'  => $key,
			);
		}
		return $other;
	}

	/**
	 * Splits tokens on top-level commas.
	 *
	 * @param array $t Tokens.
	 * @return array
	 */
	public static function splitc( array $t ): array {
		$o = array();
		$c = array();
		$d = 0;
		foreach ( $t as $x ) {
			if ( '(' === $x ) {
				++$d;
			} elseif ( ')' === $x ) {
				--$d;
			}
			if ( ',' === $x && 0 === $d ) {
				$o[] = $c;
				$c   = array();
			} else {
				$c[] = $x;
			}
		}
		$o[] = $c;
		return $o;
	}

	/**
	 * Meta aliases the tokens refer to.
	 *
	 * @param array $t Tokens.
	 * @return array
	 */
	private function refs( array $t ): array {
		$r = array();
		foreach ( $t as $x ) {
			if ( "'" !== $x[0] && preg_match( '/^(\w+)\.\w+$/', $x, $m ) && isset( $this->al[ $m[1] ] ) ) {
				$r[ $m[1] ] = 1;
			}
		}
		return array_keys( $r );
	}

	/**
	 * The main table column a single token names, or null.
	 *
	 * @param array $t Tokens.
	 * @return string|null
	 */
	private function col( array $t ): ?string {
		if ( 1 !== count( $t ) ) {
			return null;
		}
		$prim = '';
		foreach ( self::$ent as $k => $d ) {
			if ( $d['ent'] === $this->def['ent'] ) {
				$prim = $k;
			}
		}
		$x = $t[0];
		if ( str_contains( $x, '.' ) ) {
			$c = str_starts_with( $x, $prim . '.' ) ? substr( $x, strlen( $prim ) + 1 ) : '';
		} else {
			$c = $x;
		}
		return in_array( $c, $this->def['core'], true ) ? $c : null;
	}

	/**
	 * A meta value expression: [alias, cast], or null.
	 *
	 * @param array $t Tokens.
	 * @return array|null
	 */
	private function val( array $t ): ?array {
		$n = count( $t );
		if ( 1 === $n && preg_match( '/^(\w+)\.meta_value$/', $t[0], $m ) && isset( $this->al[ $m[1] ] ) ) {
			return array( $m[1], '' );
		}
		if ( 3 === $n && preg_match( '/^(\w+)\.meta_value$/', $t[0], $m ) && isset( $this->al[ $m[1] ] ) && '+' === $t[1] && '0' === $t[2] ) {
			return array( $m[1], '+0' );
		}
		if ( $n >= 6 && 'CAST' === strtoupper( $t[0] ) && '(' === $t[1] && preg_match( '/^(\w+)\.meta_value$/', $t[2], $m ) && isset( $this->al[ $m[1] ] ) && 'AS' === strtoupper( $t[3] ) && self::close( $t, 1 ) === $n - 1 ) {
			$c = strtoupper( implode( '', array_slice( $t, 4, -1 ) ) );
			if ( 'CHAR' === $c ) {
				return array( $m[1], '' );
			}
			// Only casts the copy table can hold exactly; BINARY, TIME and others stay with WordPress.
			return in_array( $c, array( 'SIGNED', 'UNSIGNED', 'DATE', 'DATETIME' ), true ) || preg_match( '/^DECIMAL\(\d+,\d+\)$/', $c ) ? array( $m[1], $c ) : null;
		}
		return null;
	}

	/**
	 * One condition.
	 *
	 * @param array $t Tokens.
	 * @return array|null
	 */
	private function leaf( array $t ) {
		$r = $this->refs( $t );
		if ( ! $r ) {
			$s = implode( ' ', $t );
			if ( '1 = 1' === $s || '1' === $s ) {
				return array( 'true' );
			}
			if ( count( $t ) > 2 ) {
				$c = $this->col( array( $t[0] ) );
				$o = $c ? $this->ops( array_slice( $t, 1 ) ) : null;
				if ( $o ) {
					return array( 'core', $c, $o[0], $o[1] );
				}
			}
			return array( 'raw', $s );
		}
		if ( count( $r ) > 1 ) {
			return null;
		}
		$a = $r[0];
		$k = $this->al[ $a ]['key'];
		if ( ( 4 === count( $t ) || 3 === count( $t ) ) && "$a.{$this->def['fk']}" === $t[0] && 'IS' === strtoupper( $t[1] ) && 'NULL' === strtoupper( $t[2] ) ) {
			return null !== $k && $this->al[ $a ]['left'] ? array( 'noex', $k ) : null;
		}
		if ( "$a.meta_key" === $t[0] && null === $k ) {
			$o = $this->ops( array_slice( $t, 1 ) );
			if ( ! $o ) {
				return null;
			}
			if ( '=' === $o[0] ) {
				return array( 'key', $a, array( $o[1][0] ) );
			}
			return 'IN' === $o[0] ? array( 'key', $a, $o[1] ) : null;
		}
		for ( $n = count( $t ) - 1; $n > 0; $n-- ) {
			$v = $this->val( array_slice( $t, 0, $n ) );
			if ( $v ) {
				$o = $this->ops( array_slice( $t, $n ) );
				if ( ! $o ) {
					return null;
				}
				return null !== $k ? array( 'meta', $k, $v[1], $o[0], $o[1] ) : array( 'v', $a, $v[1], $o[0], $o[1] );
			}
		}
		return null;
	}

	/**
	 * An operator with constant operands: [operator, values], or null.
	 *
	 * @param array $t Tokens after the left side.
	 * @return array|null
	 */
	private function ops( array $t ): ?array {
		$u   = strtoupper( $t[0] ?? '' );
		$neg = '';
		if ( 'NOT' === $u ) {
			$neg = 'NOT ';
			array_shift( $t );
			$u = strtoupper( $t[0] ?? '' );
		}
		$con = fn( $x ) => "'" === $x[0] || is_numeric( $x );
		if ( in_array( $u, array( '=', '!=', '<>', '<', '>', '<=', '>=', 'LIKE' ), true ) && 2 === count( $t ) && $con( $t[1] ) ) {
			return array( $neg . ( '<>' === $u ? '!=' : $u ), array( self::lit( $t[1] ) ) );
		}
		if ( 'IN' === $u && '(' === ( $t[1] ?? '' ) && ')' === end( $t ) ) {
			$l = array();
			foreach ( self::splitc( array_slice( $t, 2, -1 ) ) as $x ) {
				if ( 1 !== count( $x ) || ! $con( $x[0] ) ) {
					return null;
				}
				$l[] = self::lit( $x[0] );
			}
			return array( $neg . 'IN', $l );
		}
		if ( 'BETWEEN' === $u && 4 === count( $t ) && 'AND' === strtoupper( $t[2] ) && $con( $t[1] ) && $con( $t[3] ) ) {
			return array( $neg . 'BETWEEN', array( self::lit( $t[1] ), self::lit( $t[3] ) ) );
		}
		return null;
	}

	/**
	 * Joins "alias.meta_key = k" with the value conditions of the same alias beside it.
	 *
	 * @param array $n Tree node.
	 * @return array|null
	 */
	private function node( array $n ) {
		if ( 'leaf' === $n[0] ) {
			$l = $this->leaf( $n[1] );
			if ( null === $l ) {
				return null;
			}
			if ( 'key' === $l[0] ) {
				return $this->atom( $l, array() );
			}
			return 'v' === $l[0] ? null : $l;
		}
		if ( 'or' === $n[0] ) {
			$o = array();
			foreach ( $n[1] as $c ) {
				$x = $this->node( $c );
				if ( null === $x ) {
					return null;
				}
				$o[] = $x;
			}
			return array( 'or', $o );
		}
		$o    = array();
		$key  = array();
		$vals = array();
		foreach ( $n[1] as $c ) {
			if ( 'leaf' !== $c[0] ) {
				$x = $this->node( $c );
				if ( null === $x ) {
					return null;
				}
				$o[] = $x;
				continue;
			}
			$l = $this->leaf( $c[1] );
			if ( null === $l ) {
				return null;
			}
			if ( 'key' === $l[0] ) {
				if ( isset( $key[ $l[1] ] ) ) {
					return null;
				}
				$key[ $l[1] ] = $l;
			} elseif ( 'v' === $l[0] ) {
				$vals[ $l[1] ][] = $l;
			} else {
				$o[] = $l;
			}
		}
		foreach ( array_keys( $vals ) as $a ) {
			if ( ! isset( $key[ $a ] ) ) {
				return null;
			}
		}
		foreach ( $key as $a => $l ) {
			$o[] = $this->atom( $l, $vals[ $a ] ?? array() );
		}
		return array( 'and', $o );
	}

	/**
	 * One key condition with its value conditions.
	 *
	 * @param array $l Key leaf.
	 * @param array $v Value leaves of the same alias.
	 * @return array
	 */
	private function atom( array $l, array $v ) {
		$a                = $l[1];
		$this->used[ $a ] = 1;
		$b                = array();
		foreach ( $l[2] as $k ) {
			$this->keys[ $a ] = isset( $this->keys[ $a ] ) && $this->keys[ $a ] !== $k ? null : $k;
			$x                = array();
			foreach ( $v as $y ) {
				$x[] = array( $y[2], $y[3], $y[4] );
			}
			$b[] = $x ? array( 'meta', $k, $x[0][0], $x[0][1], $x[0][2], array_slice( $x, 1 ) ) : array( 'ex', $k );
		}
		return 1 === count( $b ) ? $b[0] : array( 'or', $b );
	}

	/**
	 * Merges nested nodes of the same kind and drops "true" from AND lists.
	 *
	 * @param array $n Node.
	 * @return array
	 */
	public static function flat( array $n ): array {
		if ( ! in_array( $n[0], array( 'and', 'or' ), true ) ) {
			return $n;
		}
		$o = array();
		foreach ( $n[1] as $c ) {
			$c = self::flat( $c );
			if ( 'true' === $c[0] && 'and' === $n[0] ) {
				continue;
			}
			if ( $c[0] === $n[0] ) {
				array_push( $o, ...$c[1] );
			} else {
				$o[] = $c;
			}
		}
		return 1 === count( $o ) ? $o[0] : array( $n[0], $o );
	}

	/**
	 * Calls $f on every leaf.
	 *
	 * @param array    $n Node.
	 * @param callable $f Callback.
	 */
	public static function walk( array $n, callable $f ) {
		if ( in_array( $n[0], array( 'and', 'or' ), true ) ) {
			foreach ( $n[1] as $c ) {
				self::walk( $c, $f );
			}
		} else {
			$f( $n );
		}
	}

	/**
	 * Calls $f on every node, inner nodes included.
	 *
	 * @param array    $n Node.
	 * @param callable $f Callback.
	 */
	public static function walk_all( array $n, callable $f ) {
		$f( $n );
		if ( in_array( $n[0], array( 'and', 'or' ), true ) ) {
			foreach ( $n[1] as $c ) {
				self::walk_all( $c, $f );
			}
		}
	}

	/**
	 * Whether a node has a meta condition.
	 *
	 * @param array $n Node.
	 * @return bool
	 */
	public static function has_meta( array $n ): bool {
		$h = false;
		self::walk(
			$n,
			function ( $x ) use ( &$h ) {
				$h = $h || in_array( $x[0], array( 'meta', 'ex', 'noex', 'flag' ), true );
			}
		);
		return $h;
	}

	/**
	 * The conditions of an AND list, or the node itself.
	 *
	 * @param array $n Node.
	 * @return array
	 */
	public static function conj( array $n ): array {
		return 'and' === $n[0] ? $n[1] : array( $n );
	}

	/**
	 * Which keys and casts a query needs.
	 *
	 * @param array $q Parsed query.
	 * @return array
	 */
	public static function needs( array $q ): array {
		$k = array();
		self::walk(
			$q['where'],
			function ( $n ) use ( &$k ) {
				if ( 'meta' === $n[0] ) {
					$k[ $n[1] ][ $n[2] ] = 1;
					foreach ( $n[5] ?? array() as $x ) {
						$k[ $n[1] ][ $x[0] ] = 1;
					}
				} elseif ( 'ex' === $n[0] || 'noex' === $n[0] ) {
					$k[ $n[1] ] = $k[ $n[1] ] ?? array();
				}
			}
		);
		foreach ( $q['order'] as $o ) {
			if ( 'meta' === $o[0] ) {
				$k[ $o[1] ][ $o[2] ] = 1;
			}
		}
		return $k;
	}

	/**
	 * The post or order types a query is limited to (an empty list for users), or null.
	 *
	 * @param array $q Parsed query.
	 * @return array|null
	 */
	public static function types( array $q ): ?array {
		if ( 'user' === $q['ent'] ) {
			return array();
		}
		return self::find_types( $q['where'], 'post' === $q['ent'] ? 'post_type' : 'type' );
	}

	/**
	 * Finds the type condition.
	 *
	 * @param array  $n Node.
	 * @param string $c Type column.
	 * @return array|null
	 */
	private static function find_types( array $n, string $c ): ?array {
		if ( 'core' === $n[0] && $n[1] === $c && ( '=' === $n[2] || 'IN' === $n[2] ) ) {
			return $n[3];
		}
		if ( 'and' === $n[0] ) {
			foreach ( $n[1] as $x ) {
				$r = self::find_types( $x, $c );
				if ( $r ) {
					return $r;
				}
			}
			return null;
		}
		if ( 'or' === $n[0] ) {
			$a = array();
			foreach ( $n[1] as $x ) {
				$r = self::find_types( $x, $c );
				if ( ! $r ) {
					return null;
				}
				$a = array_merge( $a, $r );
			}
			return array_values( array_unique( $a ) );
		}
		return null;
	}
}
