<?php
/**
 * Plugin Name:       SpeedUp Meta Tables
 * Plugin URI:        https://github.com/orcnd/speedup-meta-tables
 * Description:       Answers slow postmeta, usermeta and WooCommerce order meta queries from narrow tables it builds from the queries your site really runs. Writes still go to the meta tables untouched.
 * Version:           0.1.0
 * Requires at least: 6.4
 * Requires PHP:      8.0
 * Author:            orcnd
 * Author URI:        https://github.com/orcnd
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       speedup-meta-tables
 *
 * @package SpeedUp_Meta_Tables
 */

defined( 'ABSPATH' ) || exit;

define( 'SPEEDUP_META_TABLES_VERSION', '0.1.0' );
define( 'SPEEDUP_META_TABLES_FILE', __FILE__ );

spl_autoload_register(
	function ( $class_name ) {
		if ( str_starts_with( $class_name, 'SpeedUp_Meta_Tables' ) ) {
			$file = __DIR__ . '/includes/class-' . strtolower( str_replace( '_', '-', $class_name ) ) . '.php';
			if ( is_file( $file ) ) {
				require $file;
			}
		}
	}
);

register_activation_hook( __FILE__, array( 'SpeedUp_Meta_Tables', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'SpeedUp_Meta_Tables', 'deactivate' ) );

SpeedUp_Meta_Tables::boot();
