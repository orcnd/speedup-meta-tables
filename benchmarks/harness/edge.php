<?php
/**
 * LAB_ENGINE=smt wp eval-file edge.php
 *
 * Edge cases found in review: each line prints "ok" or "FAIL" with what was seen.
 *
 * @package SpeedUp_Meta_Tables
 */

global $wpdb;
$fail  = 0;
$check = function ( $name, $ok, $seen = '' ) use ( &$fail ) {
	$fail += $ok ? 0 : 1;
	echo ( $ok ? 'ok   ' : 'FAIL ' ), $name, $seen ? " ($seen)" : '', "\n";
};
$q     = $wpdb->prefix . 'speedup_meta_queue';
$pid   = (int) $wpdb->get_var( "SELECT ID FROM $wpdb->posts WHERE post_type='product' AND post_status='publish' ORDER BY ID LIMIT 1" );

// 1. A total kept for an answered page must not reach a later, unrelated FOUND_ROWS().
$a = new WP_Query(
	array(
		'post_type'      => 'product',
		'post_status'    => 'any',
		'posts_per_page' => 5,
		'fields'         => 'ids',
		'meta_query'     => array( array( 'key' => '_supplier', 'value' => 'NO-SUCH-SUPPLIER' ) ),
	)
);
$wpdb->get_col( "SELECT SQL_CALC_FOUND_ROWS ID FROM $wpdb->posts WHERE post_type='product' LIMIT 3" );
$total = (int) $wpdb->get_var( 'SELECT FOUND_ROWS()' );
$real  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $wpdb->posts WHERE post_type='product'" );
$check( 'FOUND_ROWS after an empty answered page belongs to the next query', $total === $real, "$total vs $real" );

// 2. A write that starts with a comment is noted.
$wpdb->query( "DELETE FROM $q WHERE ent='post' AND object_id=$pid" );
$wpdb->query( "/* sync */ UPDATE $wpdb->postmeta SET meta_value=meta_value WHERE post_id=$pid AND meta_key='_supplier'" );
$wpdb->get_var( 'SELECT 1' );
$noted = (int) $wpdb->dbh->query( "SELECT COUNT(*) FROM $q WHERE ent='post' AND object_id=$pid" )->fetch_row()[0];
$check( 'write with a leading comment is noted', 1 === $noted, "notes: $noted" );

// 3. A BINARY cast is left to WordPress.
$sql = "SELECT $wpdb->posts.ID FROM $wpdb->posts INNER JOIN $wpdb->postmeta ON ( $wpdb->posts.ID = $wpdb->postmeta.post_id ) WHERE 1=1 AND ( ( $wpdb->postmeta.meta_key = '_supplier' AND CAST($wpdb->postmeta.meta_value AS BINARY) = 'SUP-004' ) ) AND $wpdb->posts.post_type = 'product' GROUP BY $wpdb->posts.ID LIMIT 0, 5";
$check( 'BINARY cast is not parsed', null === SpeedUp_Meta_Tables_Sql::parse( $sql ) );

// 4. Another wpdb object (another database with the same prefix) is never rewritten.
$other = new wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
$sql   = "SELECT $wpdb->posts.ID FROM $wpdb->posts INNER JOIN $wpdb->postmeta ON ( $wpdb->posts.ID = $wpdb->postmeta.post_id ) WHERE 1=1 AND ( ( $wpdb->postmeta.meta_key = '_supplier' AND $wpdb->postmeta.meta_value = 'SUP-004' ) ) AND $wpdb->posts.post_type = 'product' GROUP BY $wpdb->posts.ID ORDER BY $wpdb->posts.post_date DESC LIMIT 0, 5";
$before = SpeedUp_Meta_Tables_Interceptor::$answered;
$other->get_col( $sql );
$check( 'a second wpdb object is not rewritten', SpeedUp_Meta_Tables_Interceptor::$answered === $before, $other->last_query === $sql ? 'same SQL' : 'rewritten' );
$wpdb->get_col( $sql );
$check( 'the site wpdb is rewritten', SpeedUp_Meta_Tables_Interceptor::$answered === $before + 1 );

// 5. repair compares everything and leaves the queue empty.
$n = SpeedUp_Meta_Tables_Planner::repair();
$left = (int) $wpdb->dbh->query( "SELECT COUNT(*) FROM $q WHERE ent<>'_v'" )->fetch_row()[0];
$check( 'repair compares every object and empties the queue', $n > 1000 && 0 === $left, "$n compared, $left left" );

echo $fail ? "$fail failed\n" : "all passed\n";
