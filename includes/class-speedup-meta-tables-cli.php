<?php
/**
 * WP-CLI commands.
 *
 * @package SpeedUp_Meta_Tables
 */

defined( 'ABSPATH' ) || exit;

/**
 * Builds, repairs and drops the narrow meta tables.
 */
class SpeedUp_Meta_Tables_Cli {
	// phpcs:disable Generic.CodeAnalysis.UnusedFunctionParameter -- WP-CLI passes both argument lists to every command.

	/**
	 * Shows the state, the tables and the build plan.
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Named arguments.
	 */
	public function status( $args, $assoc_args ) {
		$reg   = get_option( SpeedUp_Meta_Tables::REGISTRY );
		$state = SpeedUp_Meta_Tables_Planner::state();
		$plan  = SpeedUp_Meta_Tables_Planner::plan();
		WP_CLI::line( 'State: ' . ( is_array( $reg ) ? ( $reg['state'] ?? 'off' ) : 'off' ) );
		WP_CLI::line( 'Patterns in the plan: ' . count( $plan['shapes'] ) );
		foreach ( $plan['skipped'] as $g => $n ) {
			WP_CLI::line( "Too few objects for a table: $g ($n)" );
		}
		foreach ( ( is_array( $reg ) ? $reg['reg'] ?? array() : array() ) as $d ) {
			WP_CLI::line( $d['table'] . ': ' . $d['ent'] . ' ' . implode( ',', $d['types'] ) . ', ' . count( $d['keys'] ) . ' keys' );
		}
		if ( ! empty( $state['error'] ) ) {
			WP_CLI::warning( 'Last error: ' . $state['error'] );
		}
	}

	/**
	 * Builds the tables of the current plan now.
	 *
	 * ## OPTIONS
	 *
	 * [--wait=<seconds>]
	 * : Seconds to wait after reads stop, so running requests finish. Default 60.
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Named arguments.
	 */
	public function build( $args, $assoc_args ) {
		$r = SpeedUp_Meta_Tables_Planner::build( isset( $assoc_args['wait'] ) ? (int) $assoc_args['wait'] : null );
		if ( isset( $r['error'] ) ) {
			WP_CLI::error( $r['error'] );
		}
		WP_CLI::success( sprintf( '%d tables in %.1f s', $r['tables'], ( $r['ms'] ?? 0 ) / 1000 ) );
	}

	/**
	 * Repairs the tables after writes the plugin could not read.
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Named arguments.
	 */
	public function drain( $args, $assoc_args ) {
		WP_CLI::success( SpeedUp_Meta_Tables_Planner::drain() . ' objects refreshed' );
	}

	/**
	 * Compares every copied object with the meta tables and repairs it. Run it after other programs wrote to the
	 * database directly.
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Named arguments.
	 */
	public function repair( $args, $assoc_args ) {
		WP_CLI::success( SpeedUp_Meta_Tables_Planner::repair() . ' objects compared' );
	}

	/**
	 * Drops every built table. Observed patterns stay.
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Named arguments.
	 */
	public function drop( $args, $assoc_args ) {
		SpeedUp_Meta_Tables_Planner::drop_all();
		WP_CLI::success( 'Dropped' );
	}
}
