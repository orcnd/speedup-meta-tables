<?php
/**
 * PLAN=plan.json wp eval-file writes.php OUT.json
 *
 * What the plugin adds to writes and reads: the cost of noting an object after a meta update, of a read while
 * 0 to 200 objects wait for a refresh, and of refreshing 1, 12 and 40 products.
 *
 * @package SpeedUp_Meta_Tables
 */

global $wpdb;
$m    = $wpdb->dbh;
$plan = json_decode( file_get_contents( getenv( 'PLAN' ) ), true );
$q    = $wpdb->prefix . 'speedup_meta_queue';
$sql  = $plan['P1'][0];
$med  = function ( $a ) {
	sort( $a );
	return round( $a[ intdiv( count( $a ), 2 ) ], 3 );
};
$make = fn() => ( new SpeedUp_Meta_Tables_Engine( $m, $wpdb->prefix, array( 'connect' => fn() => ( new wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST ) )->dbh ) ) )->load();
$ids  = array_column( $m->query( "SELECT ID FROM $wpdb->posts WHERE post_type='product' AND post_status='publish' ORDER BY RAND(4) LIMIT 500" )->fetch_all(), 0 );
$o    = array();
$m->query( "DELETE FROM $q WHERE ent<>'_v'" );

// A meta update and the note the plugin writes after it.
$a = array();
$b = array();
foreach ( array_slice( $ids, 0, 300 ) as $id ) {
	$t = hrtime( true );
	$m->query( "UPDATE $wpdb->postmeta SET meta_value=meta_value+1 WHERE post_id=$id AND meta_key='_stock'" );
	$a[] = ( hrtime( true ) - $t ) / 1e6;
	$t   = hrtime( true );
	$m->query( "INSERT INTO $q VALUES('post',$id,1) ON DUPLICATE KEY UPDATE seq=seq+1" );
	$b[] = ( hrtime( true ) - $t ) / 1e6;
}
$o['meta_update_ms'] = $med( $a );
$o['note_ms']        = $med( $b );

// A read (P1 page and count) while N objects wait for a refresh; above 50 the read goes to WordPress.
foreach ( array( 0, 1, 10, 50, 200 ) as $n ) {
	$x = array();
	for ( $i = 0; $i < 15; $i++ ) {
		$m->query( "DELETE FROM $q WHERE ent<>'_v'" );
		if ( $n ) {
			$m->query( "INSERT INTO $q VALUES" . implode( ',', array_map( fn( $id ) => "('post',$id,1)", array_slice( $ids, 0, $n ) ) ) );
		}
		$t = hrtime( true );
		$e = $make();
		$r = $e->handle( $sql );
		$m->query( $r ?? $sql )->fetch_all();
		$f = $e->handle( 'SELECT FOUND_ROWS()' ) ?? 'SELECT FOUND_ROWS()';
		$m->query( $f )->fetch_all();
		$x[] = ( hrtime( true ) - $t ) / 1e6;
		$e->close();
	}
	$o['read_with_waiting'][ $n ] = array(
		'ms'       => $med( $x ),
		'answered' => null !== $r,
	);
}
$m->query( "DELETE FROM $q WHERE ent<>'_v'" );

// Refreshing products: first time and again.
$e = $make();
foreach ( array( 1, 12, 40 ) as $n ) {
	$x = array();
	$y = array();
	for ( $i = 0; $i < 9; $i++ ) {
		$list = array_slice( $ids, $i * 40, $n );
		$t    = hrtime( true );
		$e->refresh( 'post', $list );
		$x[]  = ( hrtime( true ) - $t ) / 1e6;
		$t    = hrtime( true );
		$e->refresh( 'post', $list );
		$y[]  = ( hrtime( true ) - $t ) / 1e6;
	}
	$o['refresh'][ $n ] = array(
		'first' => $med( $x ),
		'warm'  => $med( $y ),
	);
}
file_put_contents( $args[0], json_encode( $o, JSON_PRETTY_PRINT ) );
echo json_encode( $o ), "\n";
