<?php
// Lab only: per request SQL log. Header X-Lab-Log: name turns it on.
if ( isset( $_SERVER['HTTP_X_LAB_LOG'] ) ) {
	define( 'SAVEQUERIES', true );
	$GLOBALS['lab_engine_ms'] = 0.0;
	if ( ! empty( $_SERVER['HTTP_X_LAB_LEGACY'] ) ) { add_filter( 'pre_option_woocommerce_custom_orders_table_enabled', fn() => 'no' ); add_filter( 'pre_option_woocommerce_custom_orders_table_data_sync_enabled', fn() => 'no' ); }
	add_action( 'shutdown', function () {
		global $wpdb; $q = array();
		foreach ( (array) $wpdb->queries as $x ) { $q[] = array( round( $x[1] * 1000, 3 ), $x[0], substr( $x[2], -220 ) ); }
		$r = getrusage();
		file_put_contents( dirname( ABSPATH ) . '/logs/req.json', json_encode( array( 'uri' => $_SERVER['REQUEST_URI'], 'mode' => $_SERVER['HTTP_X_LAB_ENGINE'] ?? 'stock', 'wall' => ( microtime( true ) - $_SERVER['REQUEST_TIME_FLOAT'] ) * 1000, 'engine' => $GLOBALS['lab_engine_ms'], 'served' => $GLOBALS['lab_served'] ?? array(), 'q' => $q ), JSON_INVALID_UTF8_SUBSTITUTE ) );
	}, PHP_INT_MAX );
}
