<?php
/**
 * Plugin wiring: requirements, base tables, hooks.
 *
 * @package SpeedUp_Meta_Tables
 */

defined( 'ABSPATH' ) || exit;

/**
 * Starts the plugin and owns its base tables and options.
 */
class SpeedUp_Meta_Tables {

	/** Option with the table definitions in use (autoloaded, read on every request). */
	const REGISTRY = 'speedup_meta_tables_registry';

	/** Option with compiled query patterns. */
	const PATTERNS = 'speedup_meta_tables_patterns';

	/** Option with the last build, the last error and the plan signature. */
	const STATE = 'speedup_meta_tables_state';

	/** Option with the last time each table answered a query. */
	const USED = 'speedup_meta_tables_used';

	/** Option set while the owner has paused the plugin. */
	const PAUSED = 'speedup_meta_tables_paused';

	/** Hourly maintenance. */
	const CRON = 'speedup_meta_tables_maintain';

	/** One-off queue repair. */
	const CRON_DRAIN = 'speedup_meta_tables_drain';

	/** One-off build. */
	const CRON_BUILD = 'speedup_meta_tables_build';

	/**
	 * Why the plugin cannot run on this site, or an empty string.
	 *
	 * @var string|null
	 */
	private static $unsupported = null;

	/**
	 * Hooks everything in.
	 */
	public static function boot() {
		add_action( self::CRON, array( 'SpeedUp_Meta_Tables_Planner', 'maintain' ) );
		add_action( self::CRON_DRAIN, array( 'SpeedUp_Meta_Tables_Planner', 'drain' ) );
		add_action( self::CRON_BUILD, array( 'SpeedUp_Meta_Tables_Planner', 'build' ) );
		if ( is_admin() ) {
			SpeedUp_Meta_Tables_Admin::boot();
		}
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			WP_CLI::add_command( 'speedup-meta', 'SpeedUp_Meta_Tables_Cli' );
		}
		if ( '' === self::unsupported() ) {
			SpeedUp_Meta_Tables_Interceptor::boot();
			add_action( 'admin_init', array( __CLASS__, 'upkeep' ) );
			return;
		}
		// Writes are not followed here, so tables built earlier must not answer again later.
		$reg = get_option( self::REGISTRY );
		if ( is_array( $reg ) && 'off' !== ( $reg['state'] ?? 'off' ) ) {
			update_option(
				self::REGISTRY,
				array(
					'v'     => 0,
					'state' => 'off',
					'reg'   => array(),
				),
				true
			);
		}
	}

	/**
	 * Recreates missing base tables after an update and schedules the hourly check if it went missing.
	 */
	public static function upkeep() {
		if ( SPEEDUP_META_TABLES_VERSION !== get_option( 'speedup_meta_tables_db' ) ) {
			self::create_tables();
			update_option( 'speedup_meta_tables_db', SPEEDUP_META_TABLES_VERSION, false );
		}
		if ( ! wp_next_scheduled( self::CRON ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', self::CRON );
		}
	}

	/**
	 * Why the plugin cannot run here, as a code ("dropin", "mariadb", "mysql"), or an empty string.
	 *
	 * A db.php drop-in that only extends wpdb on one mysqli connection (Query Monitor's) is fine; one that spreads
	 * queries over several connections (HyperDB, LudicrousDB) or another database is not.
	 *
	 * @return string
	 */
	public static function unsupported(): string {
		global $wpdb;
		if ( null !== self::$unsupported ) {
			return self::$unsupported;
		}
		$why = '';
		if ( ! ( $wpdb instanceof wpdb ) || ! ( $wpdb->dbh instanceof mysqli ) || property_exists( $wpdb, 'dbhs' ) ) {
			$why = 'dropin';
		} elseif ( false !== stripos( (string) $wpdb->db_server_info(), 'mariadb' ) ) {
			$why = 'mariadb';
		} elseif ( version_compare( (string) $wpdb->db_version(), '8.0.13', '<' ) ) {
			$why = 'mysql';
		}
		self::$unsupported = $why;
		return $why;
	}

	/**
	 * The reason of unsupported() in words.
	 *
	 * @return string
	 */
	public static function unsupported_text(): string {
		$texts = array(
			'dropin'  => __( 'A database drop-in (db.php) sends queries to more than one connection or to another database.', 'speedup-meta-tables' ),
			'mariadb' => __( 'The database is MariaDB. The plugin has only been tested on MySQL 8.', 'speedup-meta-tables' ),
			'mysql'   => __( 'The plugin needs MySQL 8.0.13 or newer.', 'speedup-meta-tables' ),
		);
		return $texts[ self::unsupported() ] ?? '';
	}

	/**
	 * Name of one of the plugin's tables.
	 *
	 * @param string $name Short name.
	 * @return string
	 */
	public static function table( string $name ): string {
		global $wpdb;
		return $wpdb->prefix . 'speedup_meta_' . $name;
	}

	/**
	 * Creates the base tables and schedules maintenance.
	 *
	 * @param bool $network_wide Whether the plugin is being activated for the whole network.
	 */
	public static function activate( $network_wide = false ) {
		if ( $network_wide ) {
			wp_die( esc_html__( 'SpeedUp Meta Tables keeps tables per site. Activate it on each site that needs it, not for the whole network.', 'speedup-meta-tables' ) );
		}
		if ( '' !== self::unsupported() ) {
			return;
		}
		update_option( 'speedup_meta_tables_db', SPEEDUP_META_TABLES_VERSION, false );
		self::create_tables();
		if ( ! wp_next_scheduled( self::CRON ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', self::CRON );
		}
	}

	/**
	 * Creates the base tables if they are missing.
	 */
	public static function create_tables() {
		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- runs once, on activation and before a build.
		$cc_key = $wpdb->get_var( $wpdb->prepare( 'SELECT collation_name FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name=%s AND column_name=%s', $wpdb->postmeta, 'meta_key' ) );
		$cc_val = $wpdb->get_var( $wpdb->prepare( 'SELECT collation_name FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name=%s AND column_name=%s', $wpdb->postmeta, 'meta_value' ) );
		// phpcs:enable
		$cc_key = preg_replace( '/\W/', '', (string) $cc_key );
		$cc_val = preg_replace( '/\W/', '', (string) $cc_val );
		$cc_key = $cc_key ? $cc_key : 'utf8mb4_unicode_520_ci';
		$cc_val = $cc_val ? $cc_val : $cc_key;
		$sql    = array(
			'CREATE TABLE IF NOT EXISTS %i (id int unsigned NOT NULL AUTO_INCREMENT PRIMARY KEY, state varchar(10) NOT NULL, definition mediumtext NOT NULL, row_count bigint unsigned NOT NULL DEFAULT 0, bytes bigint unsigned NOT NULL DEFAULT 0, build_ms int unsigned NOT NULL DEFAULT 0, created datetime NOT NULL DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB' => 'tables',
			'CREATE TABLE IF NOT EXISTS %i (ent varchar(8) NOT NULL, object_id bigint unsigned NOT NULL, seq bigint unsigned NOT NULL, PRIMARY KEY (ent, object_id)) ENGINE=InnoDB' => 'queue',
			'CREATE TABLE IF NOT EXISTS %i (tab varchar(64) NOT NULL, k smallint NOT NULL, miss tinyint NOT NULL, val varchar(191) NOT NULL, st varchar(20) NOT NULL, ty varchar(20) NOT NULL, cnt bigint NOT NULL, PRIMARY KEY (tab, k, miss, val, st, ty)) ENGINE=InnoDB COLLATE ' . $cc_val => 'counts',
			'CREATE TABLE IF NOT EXISTS %i (tab varchar(64) NOT NULL, meta_key varchar(191) NOT NULL, PRIMARY KEY (tab, meta_key)) ENGINE=InnoDB COLLATE ' . $cc_key => 'keys',
			'CREATE TABLE IF NOT EXISTS %i (hash char(32) NOT NULL PRIMARY KEY, ent varchar(8) NOT NULL, types varchar(191) NOT NULL, shape mediumtext NOT NULL, hits bigint unsigned NOT NULL DEFAULT 0, lit text NOT NULL, varies tinyint NOT NULL DEFAULT 0, first_seen datetime NOT NULL, last_seen datetime NOT NULL) ENGINE=InnoDB' => 'shapes',
		);
		foreach ( $sql as $q => $name ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $q is a constant statement above (the collation is reduced to word characters), the table name is a %i placeholder.
			$wpdb->query( $wpdb->prepare( $q, self::table( $name ) ) );
		}
	}

	/**
	 * Stops serving and drops the built tables: without the plugin running, they would go stale.
	 */
	public static function deactivate() {
		wp_clear_scheduled_hook( self::CRON );
		wp_clear_scheduled_hook( self::CRON_DRAIN );
		wp_clear_scheduled_hook( self::CRON_BUILD );
		if ( '' === self::unsupported() ) {
			SpeedUp_Meta_Tables_Planner::drop_all();
		}
	}

	/**
	 * Every table of the plugin on this site.
	 *
	 * @return array
	 */
	public static function all_tables(): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- the table list must be current.
		return (array) $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $wpdb->prefix . 'speedup_meta_' ) . '%' ) );
	}
}
