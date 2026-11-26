<?php
/**
 * Background work: repairing the queue, deciding which tables to build, building and dropping them.
 *
 * @package SpeedUp_Meta_Tables
 */

defined( 'ABSPATH' ) || exit;

/**
 * Runs from WP-Cron, WP-CLI or the admin page. Never from a visitor's request.
 */
class SpeedUp_Meta_Tables_Planner {

	/**
	 * A fresh engine for background work.
	 *
	 * @return SpeedUp_Meta_Tables_Engine
	 */
	private static function engine(): SpeedUp_Meta_Tables_Engine {
		global $wpdb;
		$e = new SpeedUp_Meta_Tables_Engine(
			$wpdb->dbh,
			$wpdb->prefix,
			array(
				'connect' => array( 'SpeedUp_Meta_Tables_Interceptor', 'connect' ),
			)
		);
		return $e->load( true );
	}

	/**
	 * Takes a named database lock; false when another process holds it.
	 *
	 * @param string $name Lock name.
	 * @return bool
	 */
	private static function lock( string $name ): bool {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- a lock is never cached.
		return '1' === (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 0)', $wpdb->prefix . $name ) );
	}

	/**
	 * Releases a named lock.
	 *
	 * @param string $name Lock name.
	 */
	private static function unlock( string $name ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- a lock is never cached.
		$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $wpdb->prefix . $name ) );
	}

	/**
	 * The state option.
	 *
	 * @return array
	 */
	public static function state(): array {
		$s = get_option( SpeedUp_Meta_Tables::STATE );
		return is_array( $s ) ? $s : array();
	}

	/**
	 * Changes some fields of the state option.
	 *
	 * @param array $fields Fields.
	 */
	private static function set_state( array $fields ) {
		update_option( SpeedUp_Meta_Tables::STATE, array_merge( self::state(), $fields ), false );
	}

	/**
	 * Keeps the last error for the admin page (at most one write a minute).
	 *
	 * @param string $message Error.
	 */
	public static function note_error( string $message ) {
		$s = self::state();
		if ( ( $s['error'] ?? '' ) === $message && ( $s['error_at'] ?? 0 ) > time() - MINUTE_IN_SECONDS ) {
			return;
		}
		self::set_state(
			array(
				'error'    => substr( wp_specialchars_decode( $message, ENT_QUOTES ), 0, 500 ),
				'error_at' => time(),
			)
		);
	}

	/**
	 * Hourly: repair the queue, then build or drop when the observed queries changed.
	 */
	public static function maintain() {
		if ( '' !== SpeedUp_Meta_Tables::unsupported() ) {
			return;
		}
		self::drain();
		$plan  = self::plan();
		$state = self::state();
		$e     = self::engine();
		$built = (int) ( $state['built'] ?? 0 );
		$off   = false;
		foreach ( $e->reg as $d ) {
			foreach ( $d['keys'] as $k ) {
				$off = $off || ! empty( $k['off'] );
			}
		}
		if ( ! $plan['shapes'] ) {
			if ( $e->reg ) {
				self::drop_all();
			}
			return;
		}
		$changed = ( $state['sig'] ?? '' ) !== $plan['sig'];
		if ( ! $e->reg || ( $changed && $built < time() - 6 * HOUR_IN_SECONDS ) || ( $off && $built < time() - DAY_IN_SECONDS ) ) {
			self::build();
		}
	}

	/**
	 * Repairs the queue: objects written with SQL the plugin could not read are compared and copied again.
	 *
	 * @return int Objects refreshed.
	 */
	public static function drain(): int {
		if ( '' !== SpeedUp_Meta_Tables::unsupported() || ! self::lock( 'speedup_meta_drain' ) ) {
			return 0;
		}
		$n = 0;
		try {
			$e = self::engine();
			if ( 'on' === $e->state ) {
				foreach ( array( 'post', 'user', 'order', 'k' ) as $ent ) {
					$n += $e->drain( $ent );
				}
			}
		} catch ( Throwable $x ) {
			self::note_error( $x->getMessage() );
		} finally {
			self::unlock( 'speedup_meta_drain' );
		}
		return $n;
	}

	/**
	 * Compares every copied object with the meta tables and repairs differences, for data written outside
	 * WordPress. Reads of each kind go to WordPress until its repair is done.
	 *
	 * @return int Objects compared.
	 */
	public static function repair(): int {
		if ( '' !== SpeedUp_Meta_Tables::unsupported() ) {
			return 0;
		}
		$e    = self::engine();
		$ents = array();
		foreach ( $e->reg as $d ) {
			$ents[ $d['ent'] ] = 1;
		}
		if ( ! $ents ) {
			return 0;
		}
		$values = array();
		foreach ( array_keys( $ents ) as $ent ) {
			$values[] = '(' . $e->e( $ent ) . ', 0, 1)';
		}
		$e->rows( 'INSERT INTO ' . SpeedUp_Meta_Tables::table( 'queue' ) . ' VALUES ' . implode( ',', $values ) . ' ON DUPLICATE KEY UPDATE seq=seq+1' );
		return self::drain();
	}

	/**
	 * The patterns to build tables for: seen often enough lately, on object types with enough rows.
	 *
	 * @return array [shapes => list, sig => signature, skipped => group => objects]
	 */
	public static function plan(): array {
		global $wpdb;
		/** Minimum weighted sightings of a pattern. */
		$min_hits = (int) apply_filters( 'speedup_meta_tables_min_hits', 50 );
		/** Minimum objects of the types a table would hold. */
		$min_objects = (int) apply_filters( 'speedup_meta_tables_min_objects', 1000 );
		/** Days a pattern stays in the plan after it was last seen. */
		$keep = max( 1, (int) apply_filters( 'speedup_meta_tables_keep_days', 30 ) );
		$t    = SpeedUp_Meta_Tables::table( 'shapes' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- background job.
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE last_seen < UTC_TIMESTAMP() - INTERVAL %d DAY', $t, $keep ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- background job.
		$rows   = $wpdb->get_results( $wpdb->prepare( 'SELECT hash, ent, shape, hits, lit, varies FROM %i WHERE hits >= %d ORDER BY hash', $t, $min_hits ), ARRAY_A );
		$consts = array();
		foreach ( $rows as $r ) {
			if ( ! (int) $r['varies'] && '' !== $r['lit'] ) {
				foreach ( explode( "\n", $r['lit'] ) as $l ) {
					$consts[ $l ] = 1;
				}
			}
		}
		$consts = array_keys( $consts );
		sort( $consts );
		$groups = array();
		$hashes = array();
		$e      = self::engine();
		foreach ( $rows as $r ) {
			$s = json_decode( $r['shape'], true );
			if ( ! is_array( $s ) || ! isset( $s['ent'], $s['where'] ) ) {
				continue;
			}
			if ( ! empty( $s['raw'] ) && null === ( $s['types'] ?? null ) && 'user' !== $s['ent'] ) {
				$s = self::resolve_types( $e, $s );
				if ( ! $s ) {
					continue;
				}
			}
			$types = SpeedUp_Meta_Tables_Sql::types( $s );
			if ( null === $types ) {
				continue;
			}
			sort( $types );
			$s['const'] = $consts;
			$groups[ $s['ent'] . '|' . implode( ',', $types ) ][] = $s;
			$hashes[ $s['ent'] . '|' . implode( ',', $types ) ][] = $r['hash'];
		}
		$shapes  = array();
		$sig     = array();
		$skipped = array();
		foreach ( $groups as $g => $list ) {
			list( $ent, $types ) = explode( '|', $g );
			$n                   = self::objects( $e, $ent, '' === $types ? array() : explode( ',', $types ) );
			if ( $n < $min_objects ) {
				$skipped[ $g ] = $n;
				continue;
			}
			array_push( $shapes, ...$list );
			array_push( $sig, ...$hashes[ $g ] );
		}
		sort( $sig );
		return array(
			'shapes'  => $shapes,
			'sig'     => md5( implode( ',', $sig ) . '|' . implode( ',', $consts ) ),
			'skipped' => $skipped,
		);
	}

	/**
	 * Adds the types that have the keys of a hand-written statement.
	 *
	 * @param SpeedUp_Meta_Tables_Engine $e Engine.
	 * @param array                      $s Shape.
	 * @return array|null
	 */
	private static function resolve_types( SpeedUp_Meta_Tables_Engine $e, array $s ): ?array {
		$d    = $e->def( $s['ent'] );
		$keys = array_keys( SpeedUp_Meta_Tables_Sql::needs( $s ) );
		if ( ! $d || ! $keys || ! $e->has_table( $d['meta'] ) ) {
			return null;
		}
		$col   = 'post' === $s['ent'] ? 'post_type' : 'type';
		$types = array_column( $e->rows( "SELECT DISTINCT p.$col FROM {$d['meta']} m JOIN {$d['prim']} p ON p.{$d['id']}=m.{$d['fk']} WHERE m.meta_key IN " . $e->inlist( $keys ) ), 0 );
		if ( ! $types || count( $types ) > 8 ) {
			return null;
		}
		sort( $types );
		$s['types']      = $types;
		$s['where'][1][] = array( 'core', $col, 'IN', $types );
		$s['where']      = array( 'and', $s['where'][1] );
		return $s;
	}

	/**
	 * How many objects of these types exist.
	 *
	 * @param SpeedUp_Meta_Tables_Engine $e     Engine.
	 * @param string                     $ent   Object kind.
	 * @param array                      $types Types.
	 * @return int
	 */
	private static function objects( SpeedUp_Meta_Tables_Engine $e, string $ent, array $types ): int {
		$d = $e->def( $ent );
		if ( ! $d || ! $e->has_table( $d['prim'] ) ) {
			return 0;
		}
		$w = $types ? ' WHERE ' . ( 'post' === $ent ? 'post_type' : 'type' ) . ' IN ' . $e->inlist( $types ) : '';
		return (int) $e->rows( "SELECT COUNT(*) FROM {$d['prim']}$w" )[0][0];
	}

	/**
	 * Builds the tables of the current plan next to the old ones, switches over and drops the old ones.
	 *
	 * While it builds, reads go to the meta tables and every write is noted, so nothing written meanwhile is lost.
	 *
	 * @param int|null $wait Seconds to wait after reads stop, so requests that still use the old tables finish.
	 * @return array Result for the caller: tables, ms, error.
	 * @throws Throwable Inside, when the copy fails; it is caught and returned as the error.
	 */
	public static function build( $wait = null ): array {
		global $wpdb;
		if ( '' !== SpeedUp_Meta_Tables::unsupported() ) {
			return array( 'error' => SpeedUp_Meta_Tables::unsupported_text() );
		}
		if ( ! self::lock( 'speedup_meta_build' ) ) {
			return array( 'error' => 'busy' );
		}
		if ( function_exists( 'set_time_limit' ) ) {
			// phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged -- a build copies every object of a type; on large sites it outlasts the default limit.
			set_time_limit( 0 );
		}
		$t0 = microtime( true );
		try {
			SpeedUp_Meta_Tables::create_tables();
			$plan = self::plan();
			if ( ! $plan['shapes'] ) {
				self::drop_all();
				return array( 'tables' => 0 );
			}
			$e    = self::engine();
			$old  = $e->reg;
			$was  = $e->state;
			$tabs = SpeedUp_Meta_Tables::table( 'tables' );
			$max  = (int) $e->rows( "SELECT COALESCE(MAX(id),0) FROM $tabs" )[0][0];
			$e->publish( $old, 'building' );
			/** Seconds between stopping reads and copying the data. */
			$wait = null === $wait ? (int) apply_filters( 'speedup_meta_tables_build_wait', 60 ) : (int) $wait;
			if ( $wait > 0 ) {
				sleep( $wait );
			}
			try {
				$new = $e->build( $plan['shapes'] );
			} catch ( Throwable $x ) {
				self::drop_ids( $e, "id>$max" );
				$e->publish( $old, $old ? $was : 'off' );
				throw $x;
			}
			if ( $new ) {
				$e->rows( "UPDATE $tabs SET state='on' WHERE id IN (" . implode( ',', array_map( 'intval', array_keys( $new ) ) ) . ')' );
			}
			$e->publish( $new, $new ? 'on' : 'off' );
			self::drop_ids( $e, "id<=$max" );
			delete_option( SpeedUp_Meta_Tables::PATTERNS );
			$ms = (int) ( 1000 * ( microtime( true ) - $t0 ) );
			self::set_state(
				array(
					'sig'      => $plan['sig'],
					'built'    => time(),
					'build_ms' => $ms,
					'tables'   => count( $new ),
				)
			);
			self::unlock( 'speedup_meta_build' );
			self::drain();
			return array(
				'tables' => count( $new ),
				'ms'     => $ms,
			);
		} catch ( Throwable $x ) {
			self::note_error( $x->getMessage() );
			return array( 'error' => $x->getMessage() );
		} finally {
			self::unlock( 'speedup_meta_build' );
		}
	}

	/**
	 * Drops the built tables whose registry rows match a condition, with their counters.
	 *
	 * @param SpeedUp_Meta_Tables_Engine $e     Engine.
	 * @param string                     $where Condition on the id column.
	 */
	private static function drop_ids( SpeedUp_Meta_Tables_Engine $e, string $where ) {
		$tabs = SpeedUp_Meta_Tables::table( 'tables' );
		foreach ( $e->rows( "SELECT id FROM $tabs WHERE $where" ) as $r ) {
			$base = SpeedUp_Meta_Tables::table( 'table_' . (int) $r[0] );
			$list = array_merge(
				array_column( $e->rows( 'SHOW TABLES LIKE ' . $e->e( addcslashes( $base, '_%\\' ) ) ), 0 ),
				array_column( $e->rows( 'SHOW TABLES LIKE ' . $e->e( addcslashes( $base . '_', '_%\\' ) . '%' ) ), 0 )
			);
			if ( $list ) {
				$e->rows( 'DROP TABLE IF EXISTS ' . implode( ',', $list ) );
			}
			$e->rows( 'DELETE FROM ' . SpeedUp_Meta_Tables::table( 'counts' ) . ' WHERE tab=' . $e->e( $base ) );
			$e->rows( "DELETE FROM $tabs WHERE id=" . (int) $r[0] );
		}
	}

	/**
	 * Stops answering and drops every built table. The observed patterns stay.
	 *
	 * @return bool False when a build is running.
	 */
	public static function drop_all() {
		if ( ! self::lock( 'speedup_meta_build' ) ) {
			return false;
		}
		try {
			$e = self::engine();
			if ( ! $e->has_table( SpeedUp_Meta_Tables::table( 'queue' ) ) ) {
				return;
			}
			$e->publish( array(), 'off' );
			self::drop_ids( $e, '1' );
			$e->rows( 'DELETE FROM ' . SpeedUp_Meta_Tables::table( 'queue' ) . " WHERE ent<>'_v'" );
			delete_option( SpeedUp_Meta_Tables::PATTERNS );
			delete_option( SpeedUp_Meta_Tables::USED );
			self::set_state(
				array(
					'sig'    => '',
					'built'  => 0,
					'tables' => 0,
				)
			);
		} catch ( Throwable $x ) {
			self::note_error( $x->getMessage() );
		} finally {
			self::unlock( 'speedup_meta_build' );
		}
		return true;
	}
}
