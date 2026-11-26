<?php
// Lab only: header X-Lab-Engine: smt (or env LAB_ENGINE=smt) lets SpeedUp Meta Tables answer reads; X-Lab-Learn: 1 records every request.
$lab_smt = $_SERVER['HTTP_X_LAB_ENGINE'] ?? ( getenv( 'LAB_ENGINE' ) ?: '' );
add_filter( 'speedup_meta_tables_enabled', fn() => 'smt' === $lab_smt );
add_filter( 'speedup_meta_tables_sample_rate', fn( $r ) => empty( $_SERVER['HTTP_X_LAB_LEARN'] ) && ! getenv( 'LAB_LEARN' ) ? $r : 1 );
add_filter( 'speedup_meta_tables_build_wait', fn() => (int) ( getenv( 'LAB_WAIT' ) ?: 0 ) );
add_action( 'shutdown', function () {
	if ( class_exists( 'SpeedUp_Meta_Tables_Interceptor', false ) ) {
		$GLOBALS['lab_engine_ms'] = SpeedUp_Meta_Tables_Interceptor::$ms;
		$GLOBALS['lab_served']    = array_fill( 0, SpeedUp_Meta_Tables_Interceptor::$answered, 'x' );
	}
}, PHP_INT_MAX - 2 );
// The lab loads each screen a few times, not hundreds: two sightings are enough here.
add_filter( 'speedup_meta_tables_min_hits', fn() => 2 );
