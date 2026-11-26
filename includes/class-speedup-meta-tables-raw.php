<?php
/**
 * Hand-written SQL that does not go through WP_Query.
 *
 * @package SpeedUp_Meta_Tables
 */

defined( 'ABSPATH' ) || exit;

/**
 * Answers the hand-written meta statements of WordPress and WooCommerce whose shape is known, and in general
 * replaces "meta_table AS x" (with x.meta_key = 'k' in the same scope) by the key's column.
 */
class SpeedUp_Meta_Tables_Raw {

	/**
	 * The statement to run instead, or null when any part is not understood.
	 *
	 * @param SpeedUp_Meta_Tables_Engine $h   Engine.
	 * @param string                     $sql SQL.
	 * @return string|null
	 */
	public static function answer( SpeedUp_Meta_Tables_Engine $h, string $sql ): ?string {
		$p = preg_quote( $h->wp, '/' );
		if ( ! preg_match( '/^\s*SELECT\b/i', $sql ) || ! preg_match( '/' . $p . '(postmeta|usermeta|wc_orders_meta)\b/', $sql ) ) {
			return null;
		}
		$f = preg_replace( '/\s+/', ' ', $sql );
		// The custom field box: distinct keys.
		if ( preg_match( "/^\\s*SELECT DISTINCT meta_key\\s+FROM ({$p}postmeta|{$p}wc_orders_meta)\\s+WHERE meta_key NOT BETWEEN '_' AND '_z'\\s+HAVING (.*?)\\s+ORDER BY meta_key\\s+LIMIT (\\d+)\\s*$/s", $sql, $m ) ) {
			$cc = '';
			foreach ( $h->reg as $d ) {
				if ( $h->def( $d['ent'] )['meta'] === $m[1] ) {
					$cc = $d['cc'];
				}
			}
			if ( '' === $cc ) {
				return null;
			}
			$h->fresh( array( 'ent' => 'k' ) );
			++$h->served;
			return 'SELECT k.meta_key FROM ' . $h->pre . "keys k WHERE k.tab='{$m[1]}' AND k.meta_key NOT BETWEEN '_' AND '_z' AND " . str_replace( 'meta_key', 'k.meta_key', $m[2] ) . " AND EXISTS(SELECT 1 FROM {$m[1]} m WHERE m.meta_key=CONVERT(k.meta_key USING utf8mb4) COLLATE $cc) ORDER BY k.meta_key LIMIT {$m[3]}";
		}
		// Scheduled sales of WooCommerce.
		if ( preg_match( "/FROM {$p}postmeta as postmeta LEFT JOIN {$p}postmeta as postmeta_2 ON .* LEFT JOIN {$p}postmeta as postmeta_3 ON .* WHERE postmeta\.meta_key = '(\w+)' AND postmeta_2\.meta_key = '(\w+)' AND postmeta_3\.meta_key = '(\w+)' AND postmeta\.meta_value > 0 AND postmeta\.meta_value < '(\d+|zq\d+qz)' AND postmeta_2\.meta_value != postmeta_3\.meta_value( AND NOT EXISTS \( SELECT 1 FROM {$p}postmeta as ended WHERE ended\.post_id = postmeta\.post_id AND ended\.meta_key = '(\w+)' AND ended\.meta_value > 0 AND ended\.meta_value < '(?:\d+|zq\d+qz)' \))? ?$/", $f, $m ) ) {
			return self::sales( $h, $m );
		}
		if ( str_contains( $sql, $h->wp . 'wc_product_meta_lookup' ) ) {
			$r = self::lowstock( $h, $f );
			if ( $r ) {
				++$h->served;
				return $r;
			}
		}
		// Totals per customer.
		if ( preg_match( "/^ ?SELECT SUM\(postmeta\.meta_value\) FROM {$p}posts AS posts LEFT JOIN {$p}postmeta AS postmeta ON posts\.ID = postmeta\.post_id WHERE posts\.ID IN \( SELECT posts\.ID as order_id FROM {$p}posts AS posts LEFT JOIN {$p}postmeta AS postmeta ON posts\.ID = postmeta\.post_id WHERE postmeta\.meta_key = '(\w+)' AND postmeta\.meta_value = '(\w+)' AND posts\.post_type = '(\w+)' AND posts\.post_status IN \( ([^)]*) \) \) AND postmeta\.meta_key = '(\w+)' ?$/", $f, $m ) ) {
			foreach ( $h->reg as $d ) {
				if ( 'post' === $d['ent'] && array( $m[3] ) === $d['types'] && isset( $d['sums'][ "{$m[1]}|{$m[5]}" ] ) && empty( $d['keys'][ $m[1] ]['off'] ) && empty( $d['keys'][ $m[5] ]['off'] ) ) {
					$h->fresh( $d );
					++$h->served;
					return "SELECT SUM(s)+0e0 FROM {$d['sums']["{$m[1]}|{$m[5]}"][0]} WHERE v='{$m[2]}' AND st IN ({$m[4]}) AND n>0";
				}
			}
		}
		// One table, several keys, LIKE.
		if ( preg_match( "/^\s*SELECT DISTINCT (\w+)\.post_id FROM {$p}postmeta \w+ WHERE \w+\.meta_value (LIKE '(?:[^'\\\\]|\\\\.)*') AND \w+\.meta_key IN \(([^)]*)\)\s*$/s", $f, $m ) ) {
			$s = self::multi( $h, $h->wp . 'postmeta', $m[3], $m[2], 'post_id' );
			if ( $s ) {
				++$h->served;
			}
			return $s;
		}
		if ( preg_match( "/IN \( SELECT (\w+)\.order_id FROM {$p}wc_orders_meta as \w+ WHERE \w+\.meta_key IN \(([^)]*)\) AND \w+\.meta_value (LIKE '(?:[^'\\\\]|\\\\.)*') GROUP BY \w+\.order_id \)/s", $f, $m ) ) {
			$s = self::multi( $h, $h->wp . 'wc_orders_meta', $m[2], $m[3], 'order_id' );
			if ( ! $s ) {
				return null;
			}
			++$h->served;
			return str_replace( $m[0], "IN ( $s )", $f );
		}
		// Counts of one key.
		if ( preg_match( "/^ ?select count\(\*\) as total from {$p}postmeta where meta_key='(\w+)' AND (meta_value [<>!=]+ (?:'[^']*'|\d+)(?: AND meta_value [<>!=]+ (?:'[^']*'|\d+))*) ?$/i", $f, $m ) ) {
			$r = $h->keytab( $h->wp . 'postmeta', $m[1] );
			if ( $r && ! empty( $r[0]['keys'][ $m[1] ]['dict'] ) && isset( $h->counted( $r[0] )[ $m[1] ] ) ) {
				$h->fresh( $r[0] );
				++$h->served;
				return 'SELECT COALESCE(SUM(cnt),0) AS total FROM ' . $h->pre . "counts WHERE tab='{$r[0]['table']}' AND k={$r[0]['keys'][$m[1]]['n']} AND miss=0 AND " . str_replace( 'meta_value', 'val', $m[2] );
			}
		}
		if ( preg_match( "/^ ?select count\(\*\) as total from {$p}postmeta where meta_key='(\w+)' AND (meta_value .*)$/si", $f, $m ) ) {
			$v = $h->view( $h->wp . 'postmeta', $m[1] );
			if ( ! $v ) {
				return null;
			}
			++$h->served;
			return "SELECT COUNT(*) AS total FROM ($v) x WHERE {$m[2]}";
		}
		return self::aliases( $h, $sql, $p );
	}

	/**
	 * Products whose scheduled sale starts or ends.
	 *
	 * @param SpeedUp_Meta_Tables_Engine $h Engine.
	 * @param array                      $m Matches.
	 * @return string|null
	 */
	private static function sales( SpeedUp_Meta_Tables_Engine $h, array $m ): ?string {
		$r = $h->keytab( $h->wp . 'postmeta', $m[1] );
		if ( ! $r ) {
			return null;
		}
		$k = $r[0]['keys'];
		foreach ( array( 2, 3, 6 ) as $i ) {
			if ( ! empty( $m[ $i ] ) && ! isset( $k[ $m[ $i ] ]['casts'][''] ) ) {
				return null;
			}
		}
		if ( ! isset( $k[ $m[1] ]['casts']['+0'] ) || ! $k[ $m[3] ]['row'] ) {
			return null;
		}
		$h->fresh( $r[0] );
		++$h->served;
		$k2 = $k[ $m[2] ];
		$dv = '';
		if ( ( '_regular_price' === $m[3] || '_sale_price' === $m[3] ) && '_price' === $m[2] && ! empty( $r[0]['dreg'] ) ) {
			$dv = ' AND h.' . ( '_regular_price' === $m[3] ? 'd_reg' : 'd_sal' ) . '=1';
		}
		$c  = $k[ $m[1] ]['casts'];
		$k3 = $k[ $m[3] ]['casts'][''];
		$x  = "SELECT h.object_id AS post_id FROM {$r[0]['table']} h" . ( $k2['row'] ? '' : " JOIN {$k2['vt']} v ON v.key_id={$k2['n']} AND v.object_id=h.object_id" ) . " WHERE h.{$c['+0']}>0 AND h.{$c['']}<'{$m[4]}' AND " . ( $k2['row'] ? "h.{$k2['casts']['']}" : 'v.v' ) . "!=h.$k3" . ( isset( $k2['min'] ) ? " AND (h.{$k2['min']}!=h.$k3 OR h.{$k2['max']}!=h.$k3)" : '' ) . $dv;
		if ( ! empty( $m[6] ) ) {
			$e  = $k[ $m[6] ]['casts'];
			$x .= " AND NOT (h.{$e['']} IS NOT NULL AND h.{$e['+0']}>0 AND h.{$e['']}<'{$m[4]}')";
		}
		return $x;
	}

	/**
	 * Replaces each meta alias bound to one key by a derived table of that key.
	 *
	 * @param SpeedUp_Meta_Tables_Engine $h   Engine.
	 * @param string                     $sql SQL.
	 * @param string                     $p   Quoted table prefix.
	 * @return string|null
	 */
	private static function aliases( SpeedUp_Meta_Tables_Engine $h, string $sql, string $p ): ?string {
		if ( ! preg_match_all( '/\b(' . $p . 'postmeta|' . $p . 'usermeta|' . $p . 'wc_orders_meta)\s+(?:AS\s+)?(?!WHERE|ON|LEFT|INNER|JOIN)(\w+)/i', $sql, $m, PREG_OFFSET_CAPTURE | PREG_SET_ORDER ) ) {
			return null;
		}
		$anchor = '';
		if ( count( $m ) > 1 && preg_match( "/ON\s+(\w+)\.(\w+)\s*=\s*(\w+)\.\\2\b/", $sql ) && preg_match( "/\.meta_key\s*=\s*'(\w+)'/", $sql, $ak ) ) {
			$t      = $h->keytab( $m[0][1][0], $ak[1] );
			$anchor = $t ? $t[0]['table'] : '';
		}
		$rep = array();
		foreach ( $m as $x ) {
			$a     = $x[2][0];
			$sc    = self::scope( $sql, $x[0][1] );
			$scope = self::own( substr( $sql, $sc[0], $sc[1] - $sc[0] ) );
			if ( 1 !== preg_match_all( "/\b$a\.meta_key\s*=\s*'(\w+)'/", $scope, $k ) ) {
				return null;
			}
			$v = $h->view( $x[1][0], $k[1][0], $anchor );
			if ( ! $v ) {
				return null;
			}
			$rep[] = array( $x[0][1], strlen( $x[0][0] ), "($v) AS $a" );
		}
		usort( $rep, fn( $x, $y ) => $y[0] <=> $x[0] );
		foreach ( $rep as $r ) {
			$sql = substr_replace( $sql, $r[2], $r[0], $r[1] );
		}
		$sql = preg_replace( "/\b\w+\.meta_key\s*=\s*'\w+'/", '1=1', $sql );
		if ( str_contains( $sql, 'meta_key' ) ) {
			return null;
		}
		++$h->served;
		return $sql;
	}

	/**
	 * The three low stock statements of WooCommerce, when the row also carries stock quantity and status.
	 *
	 * @param SpeedUp_Meta_Tables_Engine $h Engine.
	 * @param string                     $f SQL with single spaces.
	 * @return string|null
	 */
	public static function lowstock( SpeedUp_Meta_Tables_Engine $h, string $f ): ?string {
		$r = $h->keytab( $h->wp . 'postmeta', '_low_stock_amount' );
		if ( ! $r || empty( $r[0]['rel'] ) ) {
			return null;
		}
		$p    = preg_quote( $h->wp, '/' );
		$d    = $r[0];
		$c    = $d['keys']['_low_stock_amount']['casts'][''];
		$t    = $d['table'];
		$cnt  = 'SELECT COALESCE(SUM(cnt),0) AS total FROM ' . $h->pre . "counts WHERE tab='$t' AND k=2000 AND miss=0 AND val IN ";
		$in   = "l_qty IS NOT NULL AND l_st IN('instock', 'outofstock')";
		$base = "/^ ?SELECT count\(\*\) as total FROM {$p}wc_product_meta_lookup wc_product_meta_lookup LEFT JOIN {$p}posts {$p}posts ON {$p}posts\.ID = wc_product_meta_lookup\.product_id ";
		$tail = "WHERE {$p}posts\.post_type IN \('product', 'product_variation'\) AND {$p}posts\.post_status = '(\w+)' AND wc_product_meta_lookup\.stock_quantity IS NOT NULL AND wc_product_meta_lookup\.stock_status IN\('instock', 'outofstock'\) ";
		if ( preg_match( $base . "JOIN {$p}postmeta AS meta ON {$p}posts\.ID = meta\.post_id AND meta\.meta_key = '_low_stock_amount' AND meta\.meta_value > '' " . $tail . 'AND wc_product_meta_lookup\.stock_quantity <= CAST\(meta\.meta_value AS SIGNED\) ?$/i', $f, $m ) ) {
			$h->fresh( $d );
			return $cnt . "('1|instock|0','1|instock|1','1|outofstock|0','1|outofstock|1') AND st='{$m[1]}'";
		}
		if ( preg_match( $base . "LEFT JOIN {$p}postmeta AS meta ON {$p}posts\.ID = meta\.post_id AND meta\.meta_key = '_low_stock_amount' AND meta\.meta_value > '' " . $tail . 'AND meta\.post_id IS NULL AND wc_product_meta_lookup\.stock_quantity <= (\d+) ?$/i', $f, $m ) ) {
			$h->fresh( $d );
			if ( '2' === $m[2] ) {
				return $cnt . "('~|instock|1','~|outofstock|1') AND st='{$m[1]}'";
			}
			return "SELECT COUNT(*) AS total FROM $t WHERE d_low IS NULL AND post_status='{$m[1]}' AND $in AND l_qty<={$m[2]}";
		}
		if ( preg_match( "/^ ?SELECT (SQL_CALC_FOUND_ROWS )?{$p}posts\.ID FROM {$p}posts LEFT JOIN {$p}wc_product_meta_lookup wc_product_meta_lookup ON {$p}posts\.ID = wc_product_meta_lookup\.product_id LEFT JOIN {$p}postmeta AS low_stock_amount_meta ON {$p}posts\.ID = low_stock_amount_meta\.post_id AND low_stock_amount_meta\.meta_key = '_low_stock_amount' WHERE 1=1 AND {$p}posts\.post_type IN \('product', 'product_variation'\) AND \(\({$p}posts\.post_status = 'publish' OR {$p}posts\.post_status = 'private'\)\) AND wc_product_meta_lookup\.stock_quantity IS NOT NULL AND wc_product_meta_lookup\.stock_status = 'instock' AND \( \( low_stock_amount_meta\.meta_value > '' AND wc_product_meta_lookup\.stock_quantity <= CAST\(low_stock_amount_meta\.meta_value AS SIGNED\) AND wc_product_meta_lookup\.stock_quantity > 0 \) OR \( \( low_stock_amount_meta\.meta_value IS NULL OR low_stock_amount_meta\.meta_value <= '' \) AND wc_product_meta_lookup\.stock_quantity <= (\d+) AND wc_product_meta_lookup\.stock_quantity > 0 \) \) GROUP BY {$p}posts\.ID ORDER BY wc_product_meta_lookup\.stock_status ASC, wc_product_meta_lookup\.stock_quantity ASC( LIMIT \d+, \d+)? ?$/i", $f, $m ) ) {
			$h->fresh( $d );
			$b = "post_status IN('publish','private') AND l_st='instock' AND l_qty>0";
			return "SELECT {$m[1]}ID FROM (SELECT object_id AS ID, l_qty FROM $t WHERE d_low=1 AND $b UNION ALL SELECT object_id, l_qty FROM $t WHERE d_low IS NULL AND $b AND l_qty<={$m[2]} AND ($c IS NULL OR $c<='')) z ORDER BY l_qty ASC" . ( $m[3] ?? '' );
		}
		return null;
	}

	/**
	 * Object ids with a LIKE match on any of several keys.
	 *
	 * @param SpeedUp_Meta_Tables_Engine $h    Engine.
	 * @param string                     $meta Meta table.
	 * @param string                     $keys Quoted key list.
	 * @param string                     $like LIKE operator and pattern.
	 * @param string                     $fk   Id column name to return.
	 * @return string|null
	 */
	public static function multi( SpeedUp_Meta_Tables_Engine $h, string $meta, string $keys, string $like, string $fk ): ?string {
		preg_match_all( "/'(\w+)'/", $keys, $k );
		$by = array();
		$r  = null;
		foreach ( $k[1] as $x ) {
			$r = $h->keytab( $meta, $x );
			if ( ! $r || ! $r[0]['keys'][ $x ]['row'] ) {
				return null;
			}
			$kk                     = $r[0]['keys'][ $x ];
			$pt                     = isset( $kk['fold'] ) ? $h->pat( $r[0], stripslashes( substr( $like, 6, -1 ) ) ) : null;
			$by[ $r[0]['table'] ][] = null !== $pt ? "{$kk['fold']} LIKE " . $h->e( $pt ) : "{$kk['casts']['']} $like";
			$h->fresh( $r[0] );
		}
		if ( 1 !== count( $by ) ) {
			return null;
		}
		$list = $k[1];
		sort( $list );
		$g        = implode( ',', $list );
		$contains = (bool) preg_match( "/^LIKE '%.+%'$/s", $like );
		$needle   = stripslashes( substr( $like, 6, -1 ) );
		$ti       = $contains ? $h->textids( $r[0], $g, $needle ) : null;
		if ( $ti ) {
			return "SELECT object_id AS $fk FROM ($ti) tx";
		}
		if ( $contains && isset( $r[0]['sets'][ $g ] ) ) {
			$pt = $h->pat( $r[0], $needle );
			if ( null !== $pt ) {
				return "SELECT object_id AS $fk FROM {$r[0]['sets'][$g]['t']} WHERE s LIKE " . $h->e( $pt );
			}
		}
		$t = key( $by );
		return "SELECT object_id AS $fk FROM $t WHERE " . implode( ' OR ', $by[ $t ] );
	}

	/**
	 * A plain scan for a text pattern over several keys.
	 *
	 * @param SpeedUp_Meta_Tables_Engine $h    Engine.
	 * @param array                      $d    Table definition.
	 * @param array                      $cols Keys.
	 * @param string                     $pat  LIKE pattern.
	 * @return string
	 */
	public static function scan( SpeedUp_Meta_Tables_Engine $h, array $d, array $cols, string $pat ): string {
		$l = implode( ' OR ', array_map( fn( $k ) => "{$d['keys'][$k]['fold']} LIKE " . $h->e( $h->pat( $d, $pat ) ), $cols ) );
		sort( $cols );
		$g = implode( ',', $cols );
		return isset( $d['sets'][ $g ] ) ? "SELECT object_id FROM {$d['sets'][$g]['t']} WHERE s LIKE " . $h->e( $h->pat( $d, $pat ) ) : "SELECT object_id FROM {$d['table']} WHERE $l";
	}

	/**
	 * Start and end of the parenthesis level around a position.
	 *
	 * @param string $s Text.
	 * @param int    $p Position.
	 * @return array
	 */
	public static function scope( string $s, int $p ): array {
		$d = 0;
		for ( $i = $p; $i >= 0; $i-- ) {
			if ( ')' === $s[ $i ] ) {
				++$d;
			} elseif ( '(' === $s[ $i ] ) {
				if ( 0 === $d ) {
					break;
				}
				--$d;
			}
		}
		$d = 0;
		for ( $j = $p, $n = strlen( $s ); $j < $n; $j++ ) {
			if ( '(' === $s[ $j ] ) {
				++$d;
			} elseif ( ')' === $s[ $j ] ) {
				if ( 0 === $d ) {
					break;
				}
				--$d;
			}
		}
		return array( $i + 1, $j );
	}

	/**
	 * The scope without nested SELECTs.
	 *
	 * @param string $s Text.
	 * @return string
	 */
	public static function own( string $s ): string {
		$o = '';
		for ( $i = 0, $n = strlen( $s ); $i < $n; $i++ ) {
			if ( '(' === $s[ $i ] && preg_match( '/\G\(\s*SELECT\b/i', $s, $m, 0, $i ) ) {
				$d = 0;
				while ( $i < $n ) {
					if ( '(' === $s[ $i ] ) {
						++$d;
					} elseif ( ')' === $s[ $i ] ) {
						--$d;
						if ( 0 === $d ) {
							break;
						}
					}
					++$i;
				}
				continue;
			}
			$o .= $s[ $i ];
		}
		return $o;
	}
}
