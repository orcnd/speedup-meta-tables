<?php
// Lab only: a listing post type and typical theme/plugin queries behind ?lab=NAME.
add_action( 'init', function () { register_post_type( 'listing', array( 'public' => true, 'has_archive' => true, 'label' => 'Listings' ) ); } );
add_filter( 'woocommerce_is_rest_api_request', fn( $x ) => $x );
function lab_queries() {
	$l = array( 'post_type' => 'listing', 'posts_per_page' => 12 );
	$n = fn( $k, $v, $c = '=', $t = 'NUMERIC' ) => array( 'key' => $k, 'value' => $v, 'compare' => $c, 'type' => $t );
	return array(
		'listing_plain'      => $l,
		'listing_1eq'        => $l + array( 'meta_query' => array( $n( 'city', 'Berlin', '=', 'CHAR' ) ) ),
		'listing_range'      => $l + array( 'meta_query' => array( $n( 'price', array( 200000, 300000 ), 'BETWEEN' ) ) ),
		'listing_3and'       => $l + array( 'meta_query' => array( $n( 'city', 'Berlin', '=', 'CHAR' ), $n( 'bedrooms', 3, '>=' ), $n( 'price', array( 100000, 500000 ), 'BETWEEN' ) ) ),
		'listing_5and_sort'  => $l + array( 'meta_key' => 'price', 'orderby' => 'meta_value_num', 'order' => 'ASC', 'meta_query' => array( $n( 'city', 'Berlin', '=', 'CHAR' ), $n( 'bedrooms', 2, '>=' ), $n( 'area', 60, '>=' ), $n( 'status', 'sale', '=', 'CHAR' ) ) ),
		'listing_sort_price' => $l + array( 'meta_key' => 'price', 'orderby' => 'meta_value_num', 'order' => 'ASC' ),
		'listing_or'         => $l + array( 'meta_query' => array( 'relation' => 'OR', $n( 'featured', '1', '=', 'CHAR' ), $n( 'price', 60000, '<' ) ) ),
		'listing_not_exists' => $l + array( 'meta_query' => array( array( 'key' => 'featured', 'compare' => 'NOT EXISTS' ) ) ),
		'listing_like'       => $l + array( 'meta_query' => array( $n( 'city', 'ber', 'LIKE', 'CHAR' ) ) ),
		'listing_date'       => $l + array( 'meta_key' => 'available_from', 'orderby' => 'meta_value', 'order' => 'ASC', 'meta_query' => array( $n( 'available_from', array( '20260601', '20260930' ), 'BETWEEN', 'DATE' ) ) ),
		'listing_multi'      => $l + array( 'meta_query' => array( $n( 'feature', 'pool', '=', 'CHAR' ), $n( 'price', 300000, '<' ) ) ),
		'listing_multi_in'   => $l + array( 'meta_query' => array( $n( 'feature', array( 'pool', 'sauna', 'garden' ), 'IN', 'CHAR' ) ) ),
		'product_instock'    => array( 'post_type' => 'product', 'posts_per_page' => 12, 'meta_query' => array( $n( '_stock_status', 'instock', '=', 'CHAR' ) ) ),
		'product_price_meta' => array( 'post_type' => 'product', 'posts_per_page' => 12, 'meta_key' => '_price', 'orderby' => 'meta_value_num', 'order' => 'ASC' ),
		'product_featured_sale' => array( 'post_type' => 'product', 'posts_per_page' => 12, 'meta_query' => array( $n( '_sale_price', 0, '>' ), $n( '_stock_status', 'instock', '=', 'CHAR' ) ) ),
	);
}
add_action( 'template_redirect', function () {
	if ( ! isset( $_GET['lab'] ) ) { return; }
	$name = sanitize_key( $_GET['lab'] ); $all = lab_queries();
	if ( isset( $all[ $name ] ) ) { $q = new WP_Query( $all[ $name ] ); echo count( $q->posts ) . '/' . $q->found_posts; exit; }
	$users = array(
		'users_role'     => array( 'role' => 'editor', 'number' => 20 ),
		'users_role_in'  => array( 'role__in' => array( 'editor', 'author', 'administrator' ), 'number' => 20 ),
		'users_authors'  => array( 'capability' => array( 'edit_posts' ), 'number' => 20 ),
		'users_email'    => array( 'meta_key' => 'billing_email', 'meta_value' => '400.szabina51@example.net', 'number' => 20 ),
		'users_country'  => array( 'meta_query' => array( array( 'key' => 'billing_country', 'value' => 'CU' ) ), 'number' => 20 ),
		'users_no_company' => array( 'meta_query' => array( array( 'key' => 'billing_company', 'compare' => 'NOT EXISTS' ) ), 'number' => 20 ),
		'users_lastact'  => array( 'meta_key' => 'wc_last_active', 'orderby' => 'meta_value_num', 'order' => 'DESC', 'number' => 20 ),
	);
	if ( isset( $users[ $name ] ) ) { $q = new WP_User_Query( $users[ $name ] ); echo count( $q->get_results() ) . '/' . $q->get_total(); exit; }
	if ( 'terms_meta' === $name ) { echo count( get_terms( array( 'taxonomy' => 'product_cat', 'hide_empty' => false, 'meta_key' => 'order', 'orderby' => 'meta_value_num' ) ) ); exit; }
	if ( 'attachment_url' === $name ) { echo attachment_url_to_postid( content_url( 'uploads/2026/photo-12345.jpg' ) ); exit; }
	if ( 'count_users' === $name ) { echo count_users()['total_users']; exit; }
	if ( 'dropdown_authors' === $name ) { echo strlen( wp_dropdown_users( array( 'capability' => 'edit_posts', 'echo' => 0 ) ) ); exit; }
} );

add_action( 'template_redirect', function () {
	if ( ! isset( $_GET['labrest'] ) ) { return; }
	wp_set_current_user( 1 ); $u = wp_unslash( $_GET['labrest'] ); $r = WP_REST_Request::from_url( rest_url( $u ) );
	$x = rest_do_request( $r ); $dd = $x->get_data(); $id = is_array( $dd ) && isset( $dd[0] ) ? array_column( $dd, 'id' ) : $dd; $h = $x->get_headers(); echo $x->get_status(), ' ', md5( wp_json_encode( $id ) ), ' ', $h['X-WP-Total'] ?? ''; exit;
}, 1 );
