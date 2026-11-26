<?php
/** CAPTURE=capture.json PLAN=plan.json wp eval-file replay.php stock,smt OUT REPS [only,list] : replays each scenario's captured meta queries, as WordPress wrote them (stock) and as the plugin answers them (smt). Needs a MySQL root login without password on the same socket for CPU time from performance_schema. */
ini_set( 'memory_limit', '1G' ); $argv = array_merge( array( 0 ), $args );
function db() { $m = ( new wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST ) )->dbh; $m->query( 'set session max_execution_time=150000' ); return $m; }
$root = new mysqli( 'localhost', 'root', '', 'performance_schema', 0, explode( ':', DB_HOST )[1] ?? null ); $root->query( "update setup_consumers set enabled='YES' where name='events_statements_cpu'" );
$m = db(); $tid = $root->query( "select thread_id from threads where processlist_id={$m->thread_id}" )->fetch_row()[0];
$cpu = fn() => (float) $root->query( "select sum(sum_cpu_time)/1e9 from events_statements_summary_by_thread_by_event_name where thread_id=$tid" )->fetch_row()[0];
$pcpu = function () { $r = getrusage(); return ( $r['ru_utime.tv_sec'] + $r['ru_stime.tv_sec'] ) * 1000 + ( $r['ru_utime.tv_usec'] + $r['ru_stime.tv_usec'] ) / 1000; };
$mk = array( 'stock' => fn() => null, 'smt' => fn() => ( new SpeedUp_Meta_Tables_Engine( $m, $GLOBALS['wpdb']->prefix, array( 'connect' => fn() => ( new wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST ) )->dbh ) ) )->load() );
$engines = explode( ',', $argv[1] ); $reps = (int) $argv[3]; $only = isset( $argv[4] ) ? explode( ',', $argv[4] ) : null;
$cap = json_decode( file_get_contents( getenv( 'CAPTURE' ) ), true ); $plan = json_decode( file_get_contents( getenv( 'PLAN' ) ), true );
if ( ! $plan ) { exit( "PLAN must name the list of queries to replay\n" ); }
$out = is_file( $argv[2] ) ? json_decode( file_get_contents( $argv[2] ), true ) : array(); $med = function ( $a ) { sort( $a ); return $a ? $a[ intdiv( count( $a ), 2 ) ] : 0; };
foreach ( $plan as $k => $qs ) {
	if ( ! $qs || ( $only && ! in_array( $k, $only, true ) ) ) { continue; } $S = array(); $miss = array(); $est = 0;
	for ( $i = 0; $i < $reps + 1; $i++ ) { $ord = $engines; shuffle( $ord ); foreach ( $ord as $en ) {
		if ( 'stock' === $en && $i > 0 && $est > 5000 && $i > ( $est > 40000 ? 1 : 3 ) ) { continue; }
		$c0 = $cpu(); $p0 = $pcpu(); $t = hrtime( true ); $E = $mk[ $en ](); $miss[ $en ] = 0; $eng = 0; $bytes = 0;
		foreach ( $qs as $s ) { $s2 = $s; if ( $E ) { $a = hrtime( true ); $r = $E->handle( $s ); $eng += hrtime( true ) - $a; if ( null === $r ) { $miss[ $en ]++; } else { $s2 = $r; } } $res = $m->query( $s2 ); if ( ! $res ) { die( "$k $en {$m->error}\n$s2\n" ); } $res->fetch_all(); }
		$w = ( hrtime( true ) - $t ) / 1e6; if ( $E ) { $E->save_patterns(); /* a request saves new patterns at shutdown, outside the measured time */ } $p = $pcpu() - $p0; $c = $cpu() - $c0; if ( 0 === $i && 'stock' === $en ) { $est = $w; if ( $est < 40000 ) { continue; } } elseif ( 0 === $i ) { continue; }
		$S[ $en ]['w'][] = $w; $S[ $en ]['c'][] = $c + $p; $S[ $en ]['e'][] = $eng / 1e6;
	} }
	foreach ( $S as $en => $x ) { $out[ $k ][ $en ] = array( 'wall' => round( $med( $x['w'] ), 3 ), 'min' => round( min( $x['w'] ), 3 ), 'cpu' => round( $med( $x['c'] ), 3 ), 'eng' => round( $med( $x['e'] ), 3 ), 'n' => count( $x['w'] ), 'miss' => $miss[ $en ], 'q' => count( $qs ) ); }
	printf( "%-4s q%-4d", $k, count( $qs ) ); foreach ( $engines as $en ) { $x = $out[ $k ][ $en ]; printf( " | %-5s wall %9.2f cpu %9.2f eng %6.2f%s", $en, $x['wall'], $x['cpu'], $x['eng'], $x['miss'] ? " MISS {$x['miss']}" : '' ); } echo "\n";
	file_put_contents( $argv[2], json_encode( $out, JSON_PRETTY_PRINT ) );
}
