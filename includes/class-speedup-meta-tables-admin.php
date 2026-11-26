<?php
/**
 * Tools > SpeedUp Meta Tables.
 *
 * @package SpeedUp_Meta_Tables
 */

defined( 'ABSPATH' ) || exit;

/**
 * Shows what the plugin learned and built, and lets the owner build now, pause or drop the tables.
 */
class SpeedUp_Meta_Tables_Admin {

	const PAGE = 'speedup-meta-tables';

	/**
	 * Hooks the page and its form handlers.
	 */
	public static function boot() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_post_speedup_meta_tables', array( __CLASS__, 'handle' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( SPEEDUP_META_TABLES_FILE ), array( __CLASS__, 'links' ) );
	}

	/**
	 * Capability for the page: on multisite only network admins, since a build changes the database schema.
	 *
	 * @return string
	 */
	private static function cap(): string {
		return is_multisite() ? 'manage_network_options' : 'manage_options';
	}

	/**
	 * Adds the Tools page.
	 */
	public static function menu() {
		add_management_page(
			__( 'SpeedUp Meta Tables', 'speedup-meta-tables' ),
			__( 'SpeedUp Meta Tables', 'speedup-meta-tables' ),
			self::cap(),
			self::PAGE,
			array( __CLASS__, 'render' )
		);
	}

	/**
	 * A "Status" link on the Plugins screen.
	 *
	 * @param array $links Links.
	 * @return array
	 */
	public static function links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( admin_url( 'tools.php?page=' . self::PAGE ) ) . '">' . esc_html__( 'Status', 'speedup-meta-tables' ) . '</a>' );
		return $links;
	}

	/**
	 * Handles the buttons.
	 */
	public static function handle() {
		if ( ! current_user_can( self::cap() ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'speedup-meta-tables' ) );
		}
		check_admin_referer( 'speedup_meta_tables' );
		$do  = isset( $_POST['do'] ) ? sanitize_key( wp_unslash( $_POST['do'] ) ) : '';
		$msg = '';
		if ( 'build' === $do ) {
			wp_clear_scheduled_hook( SpeedUp_Meta_Tables::CRON_BUILD );
			wp_schedule_single_event( time(), SpeedUp_Meta_Tables::CRON_BUILD );
			spawn_cron();
			$msg = 'build';
		} elseif ( 'pause' === $do ) {
			update_option( SpeedUp_Meta_Tables::PAUSED, 1, true );
			$msg = 'pause';
		} elseif ( 'resume' === $do ) {
			delete_option( SpeedUp_Meta_Tables::PAUSED );
			$msg = 'resume';
		} elseif ( 'drop' === $do ) {
			$msg = SpeedUp_Meta_Tables_Planner::drop_all() ? 'drop' : 'busy';
		}
		wp_safe_redirect( add_query_arg( 'done', $msg, admin_url( 'tools.php?page=' . self::PAGE ) ) );
		exit;
	}

	/**
	 * A size in MB.
	 *
	 * @param int $bytes Bytes.
	 * @return string
	 */
	private static function mb( $bytes ): string {
		/* translators: %s: size in megabytes */
		return sprintf( __( '%s MB', 'speedup-meta-tables' ), number_format_i18n( $bytes / 1048576, 1 ) );
	}

	/**
	 * A time as "x ago", or a dash.
	 *
	 * @param int $t Unix time.
	 * @return string
	 */
	private static function ago( $t ): string {
		/* translators: %s: time span such as "5 mins" */
		return $t ? sprintf( __( '%s ago', 'speedup-meta-tables' ), human_time_diff( (int) $t ) ) : '-';
	}

	/**
	 * Renders the page.
	 */
	public static function render() {
		global $wpdb;
		if ( ! current_user_can( self::cap() ) ) {
			return;
		}
		$why    = SpeedUp_Meta_Tables::unsupported_text();
		$state  = SpeedUp_Meta_Tables_Planner::state();
		$reg    = get_option( SpeedUp_Meta_Tables::REGISTRY );
		$mode   = is_array( $reg ) ? ( $reg['state'] ?? 'off' ) : 'off';
		$paused = (bool) get_option( SpeedUp_Meta_Tables::PAUSED );
		$used   = (array) get_option( SpeedUp_Meta_Tables::USED, array() );
		$tables = array();
		$shapes = array();
		$queue  = 0;
		if ( '' === $why && in_array( SpeedUp_Meta_Tables::table( 'tables' ), SpeedUp_Meta_Tables::all_tables(), true ) ) {
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the status page shows current rows.
			$tables = $wpdb->get_results( $wpdb->prepare( 'SELECT id, state, definition, row_count, bytes, build_ms, created FROM %i ORDER BY id', SpeedUp_Meta_Tables::table( 'tables' ) ), ARRAY_A );
			$shapes = $wpdb->get_results( $wpdb->prepare( 'SELECT ent, types, shape, hits, last_seen FROM %i ORDER BY hits DESC LIMIT 25', SpeedUp_Meta_Tables::table( 'shapes' ) ), ARRAY_A );
			$queue  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE ent<>'_v'", SpeedUp_Meta_Tables::table( 'queue' ) ) );
			// phpcs:enable
		}
		$labels = array(
			'on'       => __( 'Answering meta queries', 'speedup-meta-tables' ),
			'building' => __( 'Building tables; meta queries go to WordPress meanwhile', 'speedup-meta-tables' ),
			'off'      => __( 'Learning: no tables yet', 'speedup-meta-tables' ),
		);
		$done   = isset( $_GET['done'] ) ? sanitize_key( wp_unslash( $_GET['done'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- only picks a message.
		$notes  = array(
			'build'  => __( 'The build was started in the background. Reload this page in a minute.', 'speedup-meta-tables' ),
			'pause'  => __( 'Paused: meta queries go to WordPress. Writes are still noted, so the tables stay correct.', 'speedup-meta-tables' ),
			'resume' => __( 'Resumed.', 'speedup-meta-tables' ),
			'busy'   => __( 'A build is running. Try again when it has finished.', 'speedup-meta-tables' ),
			'drop'   => __( 'The tables were dropped. The observed queries stay, so the next build can start right away.', 'speedup-meta-tables' ),
		);
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'SpeedUp Meta Tables', 'speedup-meta-tables' ); ?></h1>
			<?php if ( isset( $notes[ $done ] ) ) : ?>
				<div class="notice notice-success"><p><?php echo esc_html( $notes[ $done ] ); ?></p></div>
			<?php endif; ?>
			<?php if ( '' !== $why ) : ?>
				<div class="notice notice-error"><p><?php echo esc_html( $why ); ?> <?php esc_html_e( 'The plugin does nothing on this site.', 'speedup-meta-tables' ); ?></p></div>
			<?php else : ?>
			<p><?php esc_html_e( 'The plugin records the meta queries your site runs, builds a narrow table for the ones it sees often and answers them from it. Writes always go to the meta tables; the narrow tables are refreshed right before the next read.', 'speedup-meta-tables' ); ?></p>
			<table class="widefat striped" style="max-width:720px">
				<tbody>
					<tr><th scope="row"><?php esc_html_e( 'State', 'speedup-meta-tables' ); ?></th><td><?php echo esc_html( $paused ? __( 'Paused', 'speedup-meta-tables' ) : $labels[ $mode ] ?? $mode ); ?></td></tr>
					<tr><th scope="row"><?php esc_html_e( 'Last build', 'speedup-meta-tables' ); ?></th><td><?php echo esc_html( self::ago( $state['built'] ?? 0 ) ); ?><?php echo isset( $state['build_ms'] ) && ! empty( $state['built'] ) ? esc_html( ' (' . number_format_i18n( $state['build_ms'] / 1000, 1 ) . ' s)' ) : ''; ?></td></tr>
					<tr><th scope="row"><?php esc_html_e( 'Objects waiting for a refresh', 'speedup-meta-tables' ); ?></th><td><?php echo esc_html( number_format_i18n( $queue ) ); ?></td></tr>
					<tr><th scope="row"><?php esc_html_e( 'Next check', 'speedup-meta-tables' ); ?></th><td>
					<?php
					$next = wp_next_scheduled( SpeedUp_Meta_Tables::CRON );
					echo esc_html( $next ? human_time_diff( $next ) : '-' );
					?>
					</td></tr>
					<?php if ( ! empty( $state['error'] ) ) : ?>
						<tr><th scope="row"><?php esc_html_e( 'Last error', 'speedup-meta-tables' ); ?></th><td><code><?php echo esc_html( $state['error'] ); ?></code> <?php echo esc_html( self::ago( $state['error_at'] ?? 0 ) ); ?></td></tr>
					<?php endif; ?>
				</tbody>
			</table>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin:16px 0">
				<input type="hidden" name="action" value="speedup_meta_tables">
				<?php wp_nonce_field( 'speedup_meta_tables' ); ?>
				<button class="button button-primary" name="do" value="build"><?php esc_html_e( 'Build now', 'speedup-meta-tables' ); ?></button>
				<?php if ( $paused ) : ?>
					<button class="button" name="do" value="resume"><?php esc_html_e( 'Resume', 'speedup-meta-tables' ); ?></button>
				<?php else : ?>
					<button class="button" name="do" value="pause"><?php esc_html_e( 'Pause', 'speedup-meta-tables' ); ?></button>
				<?php endif; ?>
				<button class="button" name="do" value="drop"><?php esc_html_e( 'Drop tables', 'speedup-meta-tables' ); ?></button>
			</form>

			<h2><?php esc_html_e( 'Tables', 'speedup-meta-tables' ); ?></h2>
				<?php if ( ! $tables ) : ?>
				<p><?php esc_html_e( 'None yet. A table is built once a query pattern has been seen often enough on a type with at least 1,000 objects.', 'speedup-meta-tables' ); ?></p>
			<?php else : ?>
			<table class="widefat striped">
				<thead><tr>
					<th><?php esc_html_e( 'Table', 'speedup-meta-tables' ); ?></th>
					<th><?php esc_html_e( 'Holds', 'speedup-meta-tables' ); ?></th>
					<th><?php esc_html_e( 'Keys', 'speedup-meta-tables' ); ?></th>
					<th><?php esc_html_e( 'Rows', 'speedup-meta-tables' ); ?></th>
					<th><?php esc_html_e( 'Size', 'speedup-meta-tables' ); ?></th>
					<th><?php esc_html_e( 'Last used', 'speedup-meta-tables' ); ?></th>
				</tr></thead>
				<tbody>
				<?php
				foreach ( $tables as $t ) :
					$d = json_decode( $t['definition'], true );
					if ( ! is_array( $d ) ) {
						continue;
					}
					?>
					<tr>
						<td><code><?php echo esc_html( $d['table'] ); ?></code></td>
						<td><?php echo esc_html( $d['ent'] . ( $d['types'] ? ': ' . implode( ', ', $d['types'] ) : '' ) ); ?></td>
						<td><?php echo esc_html( implode( ', ', array_keys( $d['keys'] ) ) ); ?></td>
						<td><?php echo esc_html( number_format_i18n( (int) $t['row_count'] ) ); ?></td>
						<td><?php echo esc_html( self::mb( (int) $t['bytes'] ) ); ?></td>
						<td><?php echo esc_html( self::ago( $used[ $d['table'] ] ?? 0 ) ); ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<?php endif; ?>

			<h2><?php esc_html_e( 'Observed query patterns', 'speedup-meta-tables' ); ?></h2>
				<?php if ( ! $shapes ) : ?>
				<p><?php esc_html_e( 'None yet. Patterns are recorded from meta queries the tables do not answer.', 'speedup-meta-tables' ); ?></p>
			<?php else : ?>
			<table class="widefat striped">
				<thead><tr>
					<th><?php esc_html_e( 'Object', 'speedup-meta-tables' ); ?></th>
					<th><?php esc_html_e( 'Keys', 'speedup-meta-tables' ); ?></th>
					<th><?php esc_html_e( 'Seen (estimate)', 'speedup-meta-tables' ); ?></th>
					<th><?php esc_html_e( 'Last seen', 'speedup-meta-tables' ); ?></th>
				</tr></thead>
				<tbody>
				<?php
				foreach ( $shapes as $s ) :
					$q = json_decode( $s['shape'], true );
					if ( ! is_array( $q ) || ! isset( $q['where'] ) ) {
						continue;
					}
					?>
					<tr>
						<td><?php echo esc_html( $s['ent'] . ( '' !== $s['types'] && '?' !== $s['types'] ? ': ' . $s['types'] : '' ) ); ?></td>
						<td><?php echo esc_html( implode( ', ', array_keys( SpeedUp_Meta_Tables_Sql::needs( $q ) ) ) ); ?></td>
						<td><?php echo esc_html( number_format_i18n( (int) $s['hits'] ) ); ?></td>
						<td><?php echo esc_html( self::ago( strtotime( $s['last_seen'] . ' UTC' ) ) ); ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<?php endif; ?>
			<?php endif; ?>
		</div>
		<?php
	}
}
