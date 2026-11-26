<?php
/**
 * Checks that every captured meta query the plugin answers returns the same rows as WordPress' own SQL:
 * the full result set, the total of SQL_CALC_FOUND_ROWS and the order of the page.
 *
 * wp eval-file verify.php CAPTURE.json EXPECTED.json [SCENARIO ...]
 *
 * EXPECTED.json caches WordPress' answers; delete it after the data changes.
 *
 * @package SpeedUp_Meta_Tables
 */

ini_set( 'memory_limit', '2G' );
global $wpdb;
$m     = $wpdb->dbh;
$cap   = json_decode( file_get_contents( $args[0] ), true );
$cf    = $args[1];
$only  = array_slice( $args, 2 );
$cache = is_file( $cf ) ? json_decode( file_get_contents( $cf ), true ) : array();
$make  = function () use ( $wpdb ) {
	$e = new SpeedUp_Meta_Tables_Engine(
		$wpdb->dbh,
		$wpdb->prefix,
		array( 'connect' => fn() => ( new wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST ) )->dbh )
	);
	return $e->load( true );
};
$rows  = function ( $s ) use ( $m ) {
	$r = $m->query( $s );
	if ( ! $r ) {
		throw new Exception( $m->error . "\n" . substr( $s, 0, 700 ) );
	}
	return $r->fetch_all();
};
$nolimit = fn( $s ) => preg_replace( '/\s+LIMIT\s+\d+\s*(,\s*\d+)?\s*;?\s*$/i', '', preg_replace( '/SQL_CALC_FOUND_ROWS\s+/', '', $s ) );
$bad     = 0;
$total   = 0;
$missed  = array();
foreach ( $cap as $k => $x ) {
	if ( $only && ! in_array( $k, $only, true ) ) {
		continue;
	}
	$msg = array();
	foreach ( $x['q'] as list( $t, $s ) ) {
		$s = preg_replace( '/\{[0-9a-f]{64}\}/', '%', $s );
		if ( str_contains( $s, 'FOUND_ROWS()' ) ) {
			continue;
		}
		$e = $make();
		$r = $e->handle( $s );
		if ( null === $r ) {
			if ( $t > 5 && preg_match( '/^\s*SELECT/i', $s ) && ! preg_match( '/^SELECT (post_id|user_id), meta_key|^SELECT umeta_id|^SELECT id, order_id/', $s ) ) {
				$missed[] = "$k " . round( $t ) . ' ms ' . substr( preg_replace( '/\s+/', ' ', $s ), 0, 110 ) . ' ' . $e->why;
			}
			continue;
		}
		++$total;
		$p    = SpeedUp_Meta_Tables_Sql::parse( $s );
		$full = $p || str_contains( $s, 'wc_product_meta_lookup' );
		$h    = md5( $s );
		if ( ! isset( $cache[ $h ] ) ) {
			$f = $rows( $full ? $nolimit( $s ) : $s );
			$c = array( 'set' => array_column( $f, 0 ) );
			if ( $p && 'count' !== $p['sel'] && $p['limit'] ) {
				$c['page'] = array_slice( $c['set'], $p['limit'][0], $p['limit'][1] );
			}
			sort( $c['set'] );
			$cache[ $h ] = $c;
			file_put_contents( $cf, json_encode( $cache ) );
		}
		$c   = $cache[ $h ];
		$r2  = $make()->handle( $full ? $nolimit( $s ) : $s );
		$set = array_column( $rows( $r2 ), 0 );
		sort( $set );
		if ( array_map( 'strval', $set ) != array_map( 'strval', $c['set'] ) ) {
			$msg[] = 'SET ' . count( $set ) . ' vs ' . count( $c['set'] );
		}
		if ( $p && $p['calc'] ) {
			$rows( $r );
			$fr  = $e->handle( 'SELECT FOUND_ROWS()' ) ?? 'SELECT FOUND_ROWS()';
			$cnt = (int) $rows( $fr )[0][0];
			if ( count( array_unique( $c['set'] ) ) !== $cnt ) {
				$msg[] = "COUNT $cnt vs " . count( $c['set'] );
			}
		}
		// P7 sorts on a key many products lack, so its page order among equal values is not fixed.
		if ( isset( $c['page'] ) && 'P7' !== $k ) {
			$pg = array_column( $rows( $r ), 0 );
			$o  = $p['order'][0] ?? null;
			if ( $pg != $c['page'] && $o ) {
				// Different ids are fine when the sort values are the same (ties).
				$d   = SpeedUp_Meta_Tables_Sql::$ent[ $p['prim'] ];
				$val = function ( $ids ) use ( $rows, $o, $p, $d ) {
					if ( ! $ids ) {
						return array();
					}
					$l = implode( ',', $ids );
					if ( 'raw' === $o[0] ) {
						$ex = $o[1];
					} elseif ( 'core' === $o[0] ) {
						$ex = "{$p['prim']}.{$o[1]}";
					} else {
						$z  = '+0' === $o[2] ? 'm.meta_value+0' : ( '' === $o[2] ? 'm.meta_value' : "CAST(m.meta_value AS {$o[2]})" );
						$ex = "(SELECT MIN($z) FROM {$d['meta']} m WHERE m.{$d['fk']}={$p['prim']}.{$d['id']} AND m.meta_key='{$o[1]}')";
					}
					return array_map( 'strval', array_column( $rows( "SELECT $ex FROM {$p['prim']} WHERE {$d['id']} IN ($l) ORDER BY FIELD({$d['id']},$l)" ), 0 ) );
				};
				if ( $val( $pg ) !== $val( $c['page'] ) ) {
					$msg[] = 'ORDER';
				}
			} elseif ( $pg != $c['page'] && array_diff( $pg, $c['page'] ) ) {
				$msg[] = 'PAGE';
			}
		}
	}
	if ( $msg ) {
		$bad += count( $msg );
		echo "$k: ", implode( ' | ', array_slice( $msg, 0, 3 ) ), "\n";
	}
}
echo "$total queries answered, $bad differences\n";
foreach ( $missed as $u ) {
	echo "  not answered: $u\n";
}
