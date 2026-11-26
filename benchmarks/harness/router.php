<?php
$root = __DIR__ . '/www'; $path = parse_url( $_SERVER['REQUEST_URI'], PHP_URL_PATH );
if ( is_dir( $root . $path ) ) { $path = rtrim( $path, '/' ) . '/index.php'; }
if ( '/index.php' !== $path && is_file( $root . $path ) ) {
	if ( str_ends_with( $path, '.php' ) ) { $_SERVER['SCRIPT_NAME'] = $_SERVER['PHP_SELF'] = $path; $_SERVER['SCRIPT_FILENAME'] = $root . $path; chdir( dirname( $root . $path ) ); require $root . $path; return; }
	return false;
}
$_SERVER['SCRIPT_NAME'] = $_SERVER['PHP_SELF'] = '/index.php'; chdir( $root ); require $root . '/index.php';
