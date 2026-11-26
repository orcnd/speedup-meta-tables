<?php
// Lab only: the filters and sorts that admin column plugins add to list screens (?lf=name), and nonces for AJAX scenarios.
add_action( 'admin_init', function () { if ( isset( $_GET['labnonce'] ) ) { echo wp_create_nonce( sanitize_text_field( wp_unslash( $_GET['labnonce'] ) ) ); exit; } } );
function lab_filters() {
	$eq = fn( $k, $v, $c = '=', $t = 'CHAR' ) => array( 'key' => $k, 'value' => $v, 'compare' => $c, 'type' => $t );
	$no = fn( $k ) => array( 'key' => $k, 'compare' => 'NOT EXISTS' );
	$now = 1795600000;
	return array(
		'supplier'     => array( 'meta_query' => array( $eq( '_supplier', 'SUP-004' ) ) ),
		'supplier_in'  => array( 'meta_query' => array( $eq( '_supplier', array( 'SUP-004', 'SUP-011', 'SUP-027' ), 'IN' ), $eq( '_stock_status', 'instock' ) ) ),
		'sort_cost'    => array( 'meta_key' => '_purchase_cost', 'orderby' => 'meta_value_num', 'order' => 'DESC' ),
		'sort_stock'   => array( 'meta_key' => '_stock', 'orderby' => 'meta_value_num', 'order' => 'ASC' ),
		'sort_shelf'   => array( 'meta_query' => array( 'relation' => 'OR', 'a' => array( 'key' => '_shelf', 'compare' => 'EXISTS' ), 'b' => $no( '_shelf' ) ), 'orderby' => 'a', 'order' => 'ASC' ),
		'no_image'     => array( 'meta_query' => array( 'relation' => 'OR', $no( '_thumbnail_id' ), $eq( '_thumbnail_id', '' ) ) ),
		'sort_sales'   => array( 'meta_key' => 'total_sales', 'orderby' => 'meta_value_num', 'order' => 'DESC' ),
		'l_via'        => array( 'meta_query' => array( $eq( '_created_via', array( 'admin', 'checkout' ), 'IN' ) ) ),
		'no_ean'       => array( 'meta_query' => array( 'relation' => 'OR', $no( '_ean' ), $eq( '_ean', '' ) ) ),
		'reorder'      => array( 'meta_query' => array( $eq( '_supplier', 'SUP-004' ), $eq( '_stock', array( -50, 10 ), 'BETWEEN', 'NUMERIC' ), $eq( '_manage_stock', 'yes' ) ), 'meta_key' => '_stock', 'orderby' => 'meta_value_num', 'order' => 'ASC' ),
		'cost_range'   => array( 'meta_query' => array( $eq( '_purchase_cost', array( 40, 60 ), 'BETWEEN', 'DECIMAL(10,2)' ), $eq( '_discontinued', 'yes', '=' ) ) ),
		'sale_ends'    => array( 'meta_query' => array( $eq( '_sale_price_dates_to', array( $now, $now + 30 * 86400 ), 'BETWEEN', 'NUMERIC' ) ), 'meta_key' => '_sale_price_dates_to', 'orderby' => 'meta_value_num', 'order' => 'ASC' ),
		'on_sale'      => array( 'meta_query' => array( $eq( '_sale_price', 0, '>', 'NUMERIC' ), $eq( '_stock_status', 'instock' ) ) ),
		'erp_like'     => array( 'meta_query' => array( $eq( '_erp_id', '0012', 'LIKE' ) ) ),
		'coupon_exp'   => array( 'meta_query' => array( $eq( 'date_expires', array( $now, $now + 30 * 86400 ), 'BETWEEN', 'NUMERIC' ), $eq( 'usage_count', 0, '>', 'NUMERIC' ) ) ),
		'coupon_used'  => array( 'meta_key' => 'usage_count', 'orderby' => 'meta_value_num', 'order' => 'DESC' ),
		'u_country'    => array( 'meta_query' => array( $eq( 'billing_country', 'CU' ) ) ),
		'u_spent'      => array( 'meta_key' => 'wc_money_spent_wp', 'orderby' => 'meta_value_num', 'order' => 'DESC' ),
		'u_country_spent' => array( 'role' => 'customer', 'meta_query' => array( $eq( 'billing_country', 'CU' ), $eq( 'wc_order_count_wp', 5, '>=', 'NUMERIC' ) ) ),
		'u_nocompany'  => array( 'meta_query' => array( $no( 'billing_company' ) ) ),
		'u_lastactive' => array( 'meta_key' => 'wc_last_active', 'orderby' => 'meta_value_num', 'order' => 'DESC' ),
		'u_vip'        => array( 'role__in' => array( 'customer', 'subscriber' ), 'meta_query' => array( $eq( '_vip', 'yes' ) ) ),
		'o_invoice'    => array( 'meta_query' => array( $eq( '_invoice_number', 'INV-2026-031234' ) ) ),
		'o_tracking'   => array( 'meta_query' => array( $eq( '_tracking_number', '7919', 'LIKE' ) ) ),
		'o_notrack'    => array( 'status' => 'wc-processing', 'meta_query' => array( $no( '_tracking_number' ) ) ),
		'o_delivery'   => array( 'meta_query' => array( $eq( '_delivery_date', array( '2026-11-20', '2026-11-27' ), 'BETWEEN', 'DATE' ) ) ),
		'l_customer'   => array( 'meta_query' => array( $eq( '_customer_user', '1234' ) ) ),
		'l_invoice'    => array( 'meta_query' => array( $eq( '_invoice_number', 'LINV-004321' ) ) ),
		'l_total'      => array( 'meta_key' => '_order_total', 'orderby' => 'meta_value_num', 'order' => 'DESC' ),
	);
}
function lab_filter() { $f = isset( $_GET['lf'] ) ? sanitize_key( $_GET['lf'] ) : ''; return lab_filters()[ $f ] ?? array(); }
add_action( 'pre_get_posts', function ( $q ) { if ( is_admin() && $q->is_main_query() ) { foreach ( lab_filter() as $k => $v ) { $q->set( $k, $v ); } } }, 50 );
add_action( 'pre_get_users', function ( $q ) { if ( is_admin() && isset( $GLOBALS['pagenow'] ) && 'users.php' === $GLOBALS['pagenow'] && 'all_with_meta' === $q->get( 'fields' ) ) { foreach ( lab_filter() as $k => $v ) { $q->set( $k, $v ); } } }, 50 );
add_filter( 'users_list_table_query_args', function ( $a ) { return array_merge( $a, 'users.php' === $GLOBALS['pagenow'] ? lab_filter() : array() ); } );
add_filter( 'woocommerce_order_list_table_prepare_items_query_args', fn( $a ) => array_merge( $a, lab_filter() ) );
// Lookups an ERP or marketplace connector makes, and a stock sync.
add_action( 'admin_init', function () {
	if ( ! isset( $_GET['labjob'] ) ) { return; }
	$job = sanitize_key( $_GET['labjob'] );
	if ( 'erp' === $job ) { $f = 0; for ( $i = 0; $i < 100; $i++ ) { $p = get_posts( array( 'post_type' => array( 'product', 'product_variation' ), 'post_status' => 'any', 'fields' => 'ids', 'posts_per_page' => 1, 'meta_key' => '_erp_id', 'meta_value' => sprintf( 'ERP-%07d', 1 + ( $i * 739 ) % 74000 ) ) ); $f += count( $p ); } echo $f; exit; }
	if ( 'stock' === $job ) { global $wpdb; $ids = array_map( 'intval', explode( ',', (string) ( $_GET['ids'] ?? '' ) ) ); foreach ( $ids as $i ) { $p = wc_get_product( $i ); if ( $p ) { $p->set_stock_quantity( (int) $_GET['n'] + $i % 50 ); $p->update_meta_data( '_purchase_cost', number_format( 3 + ( $i * (int) $_GET['n'] % 9000 ) / 100, 2 ) ); $p->save(); } } echo count( $ids ); exit; }
	if ( 'export' === $job ) { $q = new WP_Query( array( 'post_type' => 'product', 'posts_per_page' => 500, 'fields' => 'ids', 'meta_query' => array( array( 'key' => '_discontinued', 'compare' => 'NOT EXISTS' ), array( 'key' => '_stock_status', 'value' => 'instock' ) ) ) ); echo count( $q->posts ), '/', $q->found_posts; exit; }
	if ( 'sales' === $job ) { $d = WC_Data_Store::load( 'product' ); echo count( $d->get_starting_sales() ), '/', count( $d->get_ending_sales() ); exit; }
	if ( 'spent' === $job ) { global $wpdb; $o = array(); foreach ( $wpdb->get_col( "SELECT DISTINCT post_author FROM {$wpdb->posts} WHERE 1=0" ) as $x ) {} foreach ( array_map( 'intval', explode( ',', (string) $_GET['ids'] ) ) as $u ) { delete_user_meta( $u, 'wc_money_spent_wp' ); $o[] = wc_get_customer_total_spent( $u ); } echo implode( ',', $o ); exit; }
	if ( 'members' === $job ) { $o = array(); foreach ( array( 'approved', 'awaiting_admin_review', 'inactive', 'rejected' ) as $s ) { $q = new WP_User_Query( array( 'fields' => 'ids', 'number' => 1, 'meta_query' => array( array( 'key' => 'account_status', 'value' => $s ) ) ) ); $o[] = $q->get_total(); } $q = new WP_User_Query( array( 'fields' => 'ids', 'number' => 1, 'meta_query' => array( array( 'key' => 'account_status', 'compare' => 'NOT EXISTS' ) ) ) ); $o[] = $q->get_total(); $q = new WP_User_Query( array( 'fields' => 'ids', 'number' => 1, 'meta_query' => array( array( 'key' => 'account_status', 'value' => array( 'awaiting_admin_review', 'inactive' ), 'compare' => 'IN' ) ) ) ); $o[] = $q->get_total(); echo implode( '/', $o ); exit; }
	if ( 'wcorders_customer' === $job ) { echo count( wc_get_orders( array( 'billing_email' => sanitize_email( wp_unslash( $_GET['e'] ) ), 'limit' => 20 ) ) ); exit; }
} );
