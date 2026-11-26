<?php
/**
 * The only place the plugin touches WordPress: the wpdb "query" filter and a few actions.
 *
 * @package SpeedUp_Meta_Tables
 */

defined( 'ABSPATH' ) || exit;

/**
 * Reads are answered from the narrow tables when the result is the same; every other statement runs untouched.
 * After a write to a watched table the numbers of the changed objects are noted, so the next read refreshes them.
 *
 * The plugin's own statements run on the mysqli handle of wpdb, not through wpdb, so $wpdb->last_query,
 * last_result, insert_id and num_rows stay what the site's own code left there.
 */
class SpeedUp_Meta_Tables_Interceptor {

	/**
	 * Watched table => [object kind, id column].
	 *
	 * @var array
	 */
	private static $watch = array();

	/**
	 * Meta tables the read path looks for.
	 *
	 * @var array
	 */
	private static $meta = array();

	/**
	 * The engine, created on the first meta read.
	 *
	 * @var SpeedUp_Meta_Tables_Engine|null
	 */
	private static $e = null;

	/**
	 * Set while the plugin runs its own code, so its statements are not looked at again.
	 *
	 * @var bool
	 */
	private static $busy = false;

	/**
	 * Whether reads are answered in this request; null until the first meta read asks the filter.
	 *
	 * @var bool|null
	 */
	private static $reads = null;

	/**
	 * Queue table of the site whose tables are watched now.
	 *
	 * @var string
	 */
	private static $queue = '';

	/**
	 * Table prefix the watch list was made for.
	 *
	 * @var string
	 */
	private static $prefix = '';

	/**
	 * Set inside an explicit transaction of the site.
	 *
	 * @var bool
	 */
	private static $tx = false;

	/**
	 * Set when an unknown write needs the repair job.
	 *
	 * @var bool
	 */
	private static $need_drain = false;

	/**
	 * Registry state as loaded: "on", "building" or "off".
	 *
	 * @var string
	 */
	private static $state = 'off';

	/**
	 * Set between LOCK TABLES and UNLOCK TABLES.
	 *
	 * @var bool
	 */
	private static $locked = false;

	/**
	 * Notes waiting for the statement they belong to: kind => [id => 1].
	 *
	 * @var array
	 */
	private static $pend = array();

	/**
	 * New meta keys seen in this request.
	 *
	 * @var array
	 */
	private static $newkeys = array();

	/**
	 * Keys of the tables in use.
	 *
	 * @var array|null
	 */
	private static $keys = null;

	/**
	 * Whether the last SELECT asked for SQL_CALC_FOUND_ROWS.
	 *
	 * @var bool
	 */
	private static $calc = false;

	/**
	 * Milliseconds spent in the plugin in this request.
	 *
	 * @var float
	 */
	public static $ms = 0.0;

	/**
	 * Statements answered in this request.
	 *
	 * @var int
	 */
	public static $answered = 0;

	/**
	 * Hooks the filter and the actions.
	 */
	public static function boot() {
		self::watch_site();
		add_filter( 'query', array( __CLASS__, 'query' ), PHP_INT_MAX );
		foreach ( array( 'post', 'user' ) as $t ) {
			foreach ( array( 'added', 'updated', 'deleted' ) as $a ) {
				add_action( "{$a}_{$t}_meta", array( __CLASS__, 'now' ), PHP_INT_MAX, 0 );
			}
		}
		add_action( 'switch_blog', array( __CLASS__, 'switched' ), PHP_INT_MAX, 0 );
		add_action( 'shutdown', array( __CLASS__, 'shutdown' ), PHP_INT_MAX - 1 );
	}

	/**
	 * Sets the watch list, queue and registry state for the current site's table prefix.
	 */
	private static function watch_site() {
		global $wpdb;
		$p            = $wpdb->prefix;
		self::$prefix = $p;
		self::$queue  = $p . 'speedup_meta_queue';
		self::$watch  = array(
			$p . 'postmeta'               => array( 'post', 'post_id' ),
			$p . 'usermeta'               => array( 'user', 'user_id' ),
			$p . 'wc_orders_meta'         => array( 'order', 'order_id' ),
			$p . 'posts'                  => array( 'post', 'ID' ),
			$p . 'users'                  => array( 'user', 'ID' ),
			$p . 'wc_orders'              => array( 'order', 'id' ),
			$p . 'wc_product_meta_lookup' => array( 'post', 'product_id' ),
		);
		self::$meta   = array( $p . 'postmeta', $p . 'usermeta', $p . 'wc_orders_meta' );
		$reg          = get_option( SpeedUp_Meta_Tables::REGISTRY );
		self::$state  = is_array( $reg ) ? (string) ( $reg['state'] ?? 'off' ) : 'off';
		self::$e      = null;
		self::$keys   = null;
		SpeedUp_Meta_Tables_Sql::init( $p );
	}

	/**
	 * After switch_to_blog() or restore_current_blog(): notes still waiting go to the old site's queue, then the
	 * other site's tables are watched. A site without the plugin has no queue; its writes are not followed.
	 */
	public static function switched() {
		global $wpdb;
		if ( $wpdb->prefix === self::$prefix ) {
			return;
		}
		self::now();
		self::watch_site();
	}

	/**
	 * Whether reads are answered in this request (asks the filter once, at the first meta read).
	 *
	 * @return bool
	 */
	private static function answering(): bool {
		if ( null === self::$reads ) {
			/**
			 * Whether meta reads are answered from the plugin's tables in this request. Writes are always noted.
			 *
			 * @param bool $enabled True unless the plugin is paused.
			 */
			self::$reads = (bool) apply_filters( 'speedup_meta_tables_enabled', ! get_option( SpeedUp_Meta_Tables::PAUSED ) );
			SpeedUp_Meta_Tables_Observer::boot( self::$reads );
		}
		return self::$reads;
	}

	/**
	 * Whether the statement being filtered was sent by the site's own wpdb (not by another connection object).
	 *
	 * @return bool
	 */
	private static function from_site_wpdb(): bool {
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_debug_backtrace -- finds the wpdb object that runs the query, only before a rewrite.
		foreach ( debug_backtrace( DEBUG_BACKTRACE_PROVIDE_OBJECT, 6 ) as $f ) {
			if ( isset( $f['object'] ) && $f['object'] instanceof wpdb ) {
				return $f['object'] === $GLOBALS['wpdb'];
			}
		}
		return false;
	}

	/**
	 * Turns answering on or off for the rest of the request (writes are still noted) and returns the old setting.
	 *
	 * @param bool $on Whether reads are answered.
	 * @return bool
	 */
	public static function reads( bool $on ): bool {
		$old         = self::answering();
		self::$reads = $on;
		return $old;
	}

	/**
	 * Forgets the loaded registry, so the next read loads it again.
	 */
	public static function reset() {
		self::watch_site();
	}

	/**
	 * The engine on the site's connection; the side connection is a second wpdb with the same settings.
	 *
	 * @return SpeedUp_Meta_Tables_Engine
	 */
	public static function engine(): SpeedUp_Meta_Tables_Engine {
		global $wpdb;
		if ( ! self::$e ) {
			self::$e = new SpeedUp_Meta_Tables_Engine(
				$wpdb->dbh,
				$wpdb->prefix,
				array(
					'connect' => array( __CLASS__, 'connect' ),
				)
			);
			self::$e->load();
		}
		return self::$e;
	}

	/**
	 * A second connection with the site's settings, without wpdb's error page when it fails.
	 *
	 * @return mysqli|null
	 */
	public static function connect() {
		global $wpdb;
		$host = $wpdb->parse_db_host( DB_HOST );
		if ( ! $host ) {
			return null;
		}
		list( $name, $port, $socket ) = $host;
		// phpcs:disable WordPress.DB.RestrictedFunctions.mysql_mysqli_init, WordPress.PHP.NoSilencedErrors.Discouraged -- the plugin needs its own connection so it never joins or ends a transaction of the site; a failure only sends the query to WordPress.
		$m  = mysqli_init();
		$ok = $m && @$m->real_connect( $name, DB_USER, DB_PASSWORD, DB_NAME, $port ? (int) $port : null, $socket, defined( 'MYSQL_CLIENT_FLAGS' ) ? MYSQL_CLIENT_FLAGS : 0 );
		// phpcs:enable
		if ( ! $ok ) {
			return null;
		}
		$m->set_charset( $wpdb->charset ? $wpdb->charset : 'utf8mb4' );
		$m->query( "SET SESSION sql_mode=''" );
		return $m;
	}

	/**
	 * The "query" filter.
	 *
	 * @param string $sql The statement wpdb is about to run.
	 * @return string
	 */
	public static function query( $sql ) {
		if ( self::$busy || ! is_string( $sql ) || '' === $sql ) {
			return $sql;
		}
		$t          = hrtime( true );
		self::$busy = true;
		$r          = null;
		try {
			$body = ltrim( $sql, " \t\r\n(" );
			while ( str_starts_with( $body, '/*' ) || str_starts_with( $body, '-- ' ) || str_starts_with( $body, '#' ) ) {
				$end  = str_starts_with( $body, '/*' ) ? strpos( $body, '*/' ) : strpos( $body, "\n" );
				$body = false === $end ? '' : ltrim( substr( $body, $end + ( str_starts_with( $body, '/*' ) ? 2 : 1 ) ), " \t\r\n(" );
			}
			$word = strtoupper( (string) strtok( $body, " \t\r\n(" ) );
			$c    = $word[0] ?? '';
			if ( 'SELECT' === $word ) {
				if ( self::$pend ) {
					self::flush();
				}
				if ( ! self::$locked ) {
					$r = self::read( $sql );
				}
				self::$calc = null !== $r ? str_contains( $r, 'SQL_CALC_FOUND_ROWS' ) : str_contains( $sql, 'SQL_CALC_FOUND_ROWS' );
			} elseif ( in_array( $word, array( 'START', 'BEGIN' ), true ) || ( 'SET' === $word && preg_match( '/autocommit\s*=\s*(0|OFF)/i', $body ) ) ) {
				self::$tx = true;
			} elseif ( in_array( $word, array( 'COMMIT', 'ROLLBACK' ), true ) || ( 'SET' === $word && preg_match( '/autocommit\s*=\s*(1|ON)/i', $body ) ) ) {
				if ( self::$pend ) {
					self::flush();
				}
				self::$tx = false;
			} elseif ( ! in_array( $word, array( 'SET', 'SHOW', 'DESCRIBE', 'DESC', 'EXPLAIN', 'USE', 'SAVEPOINT', 'RELEASE', 'ANALYZE', 'OPTIMIZE', 'CHECK', 'CREATE', 'DROP', 'ALTER' ), true ) ) {
				if ( self::$pend ) {
					self::flush();
				}
				if ( 'LOCK' === $word ) {
					self::$locked = true;
				} elseif ( 'UNLOCK' === $word ) {
					self::$locked = false;
				}
				if ( 'off' !== self::$state ) {
					self::write( $body, $word );
				}
			}
		} catch ( Throwable $x ) {
			SpeedUp_Meta_Tables_Planner::note_error( $x->getMessage() );
			$r = null;
		}
		self::$busy = false;
		self::$ms  += ( hrtime( true ) - $t ) / 1e6;
		if ( null !== $r ) {
			++self::$answered;
		}
		return $r ?? $sql;
	}

	/**
	 * Answers a SELECT, or returns null.
	 *
	 * @param string $sql SQL.
	 * @return string|null
	 */
	private static function read( string $sql ): ?string {
		$meta = false;
		if ( str_contains( $sql, 'meta' ) ) {
			foreach ( self::$meta as $m ) {
				if ( str_contains( $sql, $m ) ) {
					$meta = true;
					break;
				}
			}
		}
		$found_rows = (bool) preg_match( '/^\s*SELECT FOUND_ROWS\(\)/i', $sql );
		if ( self::$e && ! $found_rows ) {
			// A total kept for a page belongs to the very next statement only.
			self::$e->found = null;
		}
		if ( ! $meta ) {
			return self::$e && $found_rows ? self::$e->handle( $sql ) : null;
		}
		// The meta cache loader and locking reads always go to the meta table.
		if ( preg_match( '/^SELECT (post_id|user_id|id|umeta_id|meta_id|order_id)[ ,]/', $sql ) || preg_match( '/\bFOR UPDATE\b|\bLOCK IN SHARE MODE\b/i', substr( $sql, -60 ) ) ) {
			return null;
		}
		$r = null;
		if ( ! self::answering() || ! self::from_site_wpdb() ) {
			return null;
		}
		if ( 'on' === self::$state ) {
			$r = self::engine()->handle( $sql );
		}
		if ( null === $r && 'building' !== self::$state ) {
			SpeedUp_Meta_Tables_Observer::see( $sql );
		}
		return $r;
	}

	/**
	 * Whether a key is copied into a table (every key is, while a build runs).
	 *
	 * @param string $key Meta key.
	 * @return bool
	 */
	private static function watched( string $key ): bool {
		if ( 'building' === self::$state ) {
			return true;
		}
		if ( null === self::$keys ) {
			self::$keys = array();
			foreach ( self::engine()->reg as $d ) {
				self::$keys += $d['keys'];
			}
		}
		return isset( self::$keys[ $key ] ) || isset( self::$keys[ strtolower( $key ) ] );
	}

	/**
	 * Notes which objects a write statement is going to change. Unknown objects are noted as object 0.
	 *
	 * @param string $sql  SQL without leading comments.
	 * @param string $word Its first word.
	 */
	private static function write( string $sql, string $word ) {
		global $wpdb;
		$p = preg_quote( $wpdb->prefix, '/' );
		if ( ! preg_match( '/\b' . $p . '(?:postmeta|usermeta|wc_orders_meta|posts|users|wc_orders|wc_product_meta_lookup)\b/', $sql ) ) {
			return;
		}
		if ( ! preg_match( '/^\s*(INSERT|UPDATE|DELETE|REPLACE|TRUNCATE|LOAD)\b/i', $sql, $v ) ) {
			// WITH ... UPDATE, CALL and the like: every watched kind it names is unknown now.
			if ( 'CALL' === $word || ( 'WITH' === $word && preg_match( '/\b(UPDATE|DELETE|INSERT|REPLACE)\b/i', $sql ) ) ) {
				foreach ( self::$watch as $t => $x ) {
					if ( preg_match( '/\b' . preg_quote( $t, '/' ) . '\b/', $sql ) ) {
						self::add( $x[0], 0 );
					}
				}
			}
			return;
		}
		$v     = strtoupper( $v[1] );
		$tab   = preg_match( '/^\s*(?:INSERT\s+(?:IGNORE\s+)?(?:INTO\s+)?|REPLACE\s+(?:INTO\s+)?|UPDATE\s+(?:IGNORE\s+)?|DELETE\s+(?:IGNORE\s+)?FROM\s+|TRUNCATE\s+(?:TABLE\s+)?)`?(\w+)`?/i', $sql, $m ) ? $m[1] : '';
		$plain = preg_replace( "/'(?:[^'\\\\]|\\\\.)*'/s", "''", $sql );
		if ( ! isset( self::$watch[ $tab ] ) ) {
			if ( 'INSERT' !== $v && 'REPLACE' !== $v && preg_match_all( '/\b' . $p . '\w+\b/', $plain, $x ) ) {
				foreach ( array_unique( $x[0] ) as $z ) {
					if ( isset( self::$watch[ $z ] ) ) {
						self::add( self::$watch[ $z ][0], 0 );
					}
				}
			}
			return;
		}
		list( $ent, $id ) = self::$watch[ $tab ];
		$meta             = in_array( $tab, self::$meta, true );
		$usermeta         = $wpdb->prefix . 'usermeta' === $tab;
		if ( 'INSERT' === $v || 'REPLACE' === $v ) {
			self::insert( $sql, $ent, $id, $meta, $usermeta, $tab );
			return;
		}
		if ( 'TRUNCATE' === $v || 'LOAD' === $v ) {
			self::add( $ent, 0 );
			return;
		}
		if ( $meta && preg_match_all( "/`?meta_key`?\s*=\s*'((?:[^'\\\\]|\\\\.)*)'/", $sql, $k ) ) {
			$any = false;
			foreach ( $k[1] as $x ) {
				$any = $any || self::watched( stripslashes( $x ) );
			}
			if ( preg_match( "/SET[^']*`?meta_key`?\s*=\s*'/i", $sql ) ) {
				self::add( 'k', 0 );
				$any = true;
			}
			if ( ! $any ) {
				return;
			}
		}
		if ( preg_match( '/^\s*(?:UPDATE|DELETE)\s.*?\bWHERE\b(.*)$/is', $sql, $m ) && ! preg_match( '/\b(JOIN|LIMIT|ORDER\s+BY)\b/i', $plain ) && ! preg_match( '/^\s*UPDATE\s+`?\w+`?\s*,/i', $sql ) && ! preg_match( '/^\s*(?:UPDATE|DELETE\s+FROM)\s+`?\w+`?\s+(?!SET\b|WHERE\b)\w/i', $sql ) ) {
			$where = preg_replace( "/'(?:[^'\\\\]|\\\\.)*'/s", "''", $m[1] );
			if ( ! preg_match( '/\bOR\b|\|\|/i', $where ) && preg_match( "/(?<![\w.])`?$id`?\s*=\s*'?(\d+)'?(?!\d)/", $m[1], $x ) ) {
				self::add( $ent, (int) $x[1] );
				return;
			}
			// Inside the site's transaction a SELECT here would fix its snapshot early; RAND() and variables
			// would pick other rows than the write.
			if ( self::$locked || self::$tx || preg_match( '/\bRAND\s*\(|@/i', $where ) ) {
				self::add( $ent, 0 );
				return;
			}
			// Which rows the statement will touch; FOUND_ROWS() of the page before it is kept for the count after.
			$cnt = null;
			if ( self::$calc ) {
				$f   = $wpdb->dbh->query( 'SELECT FOUND_ROWS()' );
				$cnt = $f ? $f->fetch_row()[0] : null;
			}
			$r = $wpdb->dbh->query( "SELECT DISTINCT `$id` FROM `$tab` WHERE {$m[1]} LIMIT 1001" );
			if ( null !== $cnt ) {
				self::engine()->found = 'SELECT ' . (int) $cnt . ' AS `FOUND_ROWS()`';
				self::$calc           = false;
			}
			if ( $r ) {
				$l = array_column( $r->fetch_all(), 0 );
				if ( count( $l ) <= 1000 ) {
					foreach ( $l as $x ) {
						self::add( $ent, (int) $x );
					}
					return;
				}
			}
		}
		self::add( $ent, 0 );
	}

	/**
	 * Notes the objects of an INSERT or REPLACE.
	 *
	 * @param string $sql      SQL.
	 * @param string $ent      Object kind.
	 * @param string $id       Id column.
	 * @param bool   $meta     Whether the table is a meta table.
	 * @param bool   $usermeta Whether the table is usermeta.
	 * @param string $tab      Table.
	 */
	private static function insert( string $sql, string $ent, string $id, bool $meta, bool $usermeta, string $tab ) {
		if ( preg_match( '/^[^(]*\(([^)]*)\)\s*VALUES\s*(.*)$/is', $sql, $m ) ) {
			$cols = array_map( fn( $c ) => trim( $c, " `\t\n" ), explode( ',', $m[1] ) );
			$at   = array_search( $id, $cols, true );
			$kt   = $meta ? array_search( 'meta_key', $cols, true ) : false;
			$rows = self::tuples( $m[2] );
			if ( null !== $rows ) {
				foreach ( $rows as $r ) {
					$key = false !== $kt ? $r[ $kt ] ?? null : null;
					if ( null !== $key ) {
						if ( '_' !== ( $key[0] ?? '_' ) && ! $usermeta ) {
							self::$newkeys[ $tab ][ $key ] = 1;
						}
						if ( ! self::watched( $key ) ) {
							continue;
						}
					} elseif ( $meta ) {
						self::add( $ent, 0 );
						continue;
					}
					if ( false === $at ) {
						self::$pend['new'][ $ent ] = 1;
					} elseif ( is_numeric( $r[ $at ] ?? '' ) ) {
						self::add( $ent, (int) $r[ $at ] );
					} else {
						self::add( $ent, 0 );
					}
				}
				return;
			}
		}
		self::add( $ent, 0 );
		if ( $meta && ! $usermeta ) {
			self::add( 'k', 0 );
		}
	}

	/**
	 * The rows of a VALUES list, or null when it holds anything but constants.
	 *
	 * @param string $s Text after VALUES.
	 * @return array|null
	 */
	private static function tuples( string $s ): ?array {
		$o = array();
		$i = 0;
		$n = strlen( $s );
		while ( $i < $n ) {
			$i += strspn( $s, " \t\r\n,", $i );
			if ( $i >= $n || ';' === $s[ $i ] ) {
				break;
			}
			if ( '(' !== $s[ $i ] ) {
				return 0 === stripos( substr( $s, $i ), 'ON DUPLICATE' ) ? $o : null;
			}
			++$i;
			$r = array();
			while ( true ) {
				$i += strspn( $s, " \t\r\n,", $i );
				if ( $i >= $n ) {
					return null;
				}
				if ( ')' === $s[ $i ] ) {
					++$i;
					break;
				}
				if ( preg_match( "/\G'((?:[^'\\\\]|\\\\.|'')*)'/s", $s, $m, 0, $i ) ) {
					$r[] = stripslashes( str_replace( "''", "'", $m[1] ) );
				} elseif ( preg_match( '/\G(NULL|-?\d+(?:\.\d+)?)(?=\s*[,)])/i', $s, $m, 0, $i ) ) {
					$r[] = 'NULL' === strtoupper( $m[1] ) ? null : $m[1];
				} else {
					return null;
				}
				$i += strlen( $m[0] );
			}
			$o[] = $r;
		}
		return $o;
	}

	/**
	 * Notes one object.
	 *
	 * @param string $ent Object kind.
	 * @param int    $id  Object id, 0 for unknown objects.
	 */
	private static function add( string $ent, int $id ) {
		self::$pend[ $ent ][ $id ] = 1;
	}

	/**
	 * Writes the notes after the statements they belong to have run, on the site's connection, so they are part
	 * of the same transaction.
	 */
	private static function flush() {
		global $wpdb;
		$p          = self::$pend;
		self::$pend = array();
		foreach ( array_keys( $p['new'] ?? array() ) as $ent ) {
			$p[ $ent ][ (int) $wpdb->dbh->insert_id ] = 1;
		}
		unset( $p['new'] );
		$v       = array();
		$unknown = false;
		foreach ( $p as $ent => $l ) {
			$l = isset( $l[0] ) ? array( 0 => 1 ) + array_slice( $l, 0, 100, true ) : $l;
			foreach ( array_keys( $l ) as $id ) {
				$v[]     = "('" . $wpdb->dbh->real_escape_string( $ent ) . "'," . (int) $id . ',1)';
				$unknown = $unknown || 0 === (int) $id;
			}
			if ( self::$e ) {
				self::$e->touch( $ent );
			}
		}
		$q = self::$queue;
		foreach ( array_chunk( $v, 500 ) as $c ) {
			$s = "INSERT INTO $q VALUES" . implode( ',', $c ) . ' ON DUPLICATE KEY UPDATE seq=seq+1';
			if ( self::$locked || ! $wpdb->dbh->query( $s ) ) {
				self::engine()->side_connection()->query( $s );
			}
		}
		self::$need_drain = self::$need_drain || $unknown;
	}

	/**
	 * Adds new meta keys to the key list.
	 */
	private static function keysflush() {
		global $wpdb;
		$t = self::$prefix . 'speedup_meta_keys';
		foreach ( self::$newkeys as $tab => $l ) {
			foreach ( array_keys( $l ) as $k ) {
				if ( strlen( (string) $k ) < 180 ) {
					$wpdb->dbh->query( "INSERT IGNORE INTO $t VALUES('" . $wpdb->dbh->real_escape_string( $tab ) . "','" . $wpdb->dbh->real_escape_string( (string) $k ) . "')" );
				}
			}
		}
		self::$newkeys = array();
	}

	/**
	 * Writes pending notes now (after a meta API write).
	 */
	public static function now() {
		if ( self::$busy ) {
			return;
		}
		self::$busy = true;
		try {
			if ( self::$pend ) {
				self::flush();
			}
			if ( self::$newkeys ) {
				self::keysflush();
			}
		} finally {
			self::$busy = false;
		}
	}

	/**
	 * End of the request: notes, observed patterns, the pattern cache and table use.
	 */
	public static function shutdown() {
		self::now();
		if ( self::$busy ) {
			return;
		}
		self::$busy = true;
		try {
			SpeedUp_Meta_Tables_Observer::flush();
		} catch ( Throwable $x ) {
			SpeedUp_Meta_Tables_Planner::note_error( $x->getMessage() );
		}
		self::$busy = false;
		if ( self::$need_drain && ! wp_next_scheduled( SpeedUp_Meta_Tables::CRON_DRAIN ) ) {
			wp_schedule_single_event( time() + 30, SpeedUp_Meta_Tables::CRON_DRAIN );
		}
		if ( ! self::$e ) {
			return;
		}
		self::$e->save_patterns();
		if ( self::$e->used ) {
			$used = get_option( SpeedUp_Meta_Tables::USED );
			$used = is_array( $used ) ? $used : array();
			$old  = $used;
			foreach ( array_keys( self::$e->used ) as $t ) {
				if ( ( $used[ $t ] ?? 0 ) < time() - HOUR_IN_SECONDS ) {
					$used[ $t ] = time();
				}
			}
			if ( $used !== $old ) {
				update_option( SpeedUp_Meta_Tables::USED, $used, false );
			}
		}
	}
}
