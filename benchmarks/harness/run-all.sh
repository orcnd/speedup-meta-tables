#!/bin/sh
# Runs every measurement of benchmarks/ against the lab site, in the order of the report. About an hour.
# LAB_DIR is the lab folder (WordPress in $LAB_DIR/www, MySQL socket in DB_HOST, cookie.txt, the router).
set -u
H="$(cd "$(dirname "$0")" && pwd)"; OUT="$H/../results"; export LAB_DIR
cd "$LAB_DIR"
WP="php -d opcache.enable_cli=1 -d memory_limit=2G $LAB_DIR/dl/wp-cli.phar --path=$LAB_DIR/www"
export CAPTURE="$LAB_DIR/deney/capture.json" PLAN="$LAB_DIR/deney/plan.json"
echo "start $(date)"; uptime; sysctl vm.swapusage
$WP speedup-meta drain
rm -f "$OUT/expected.json"
$WP eval-file "$H/verify.php" "$CAPTURE" "$OUT/expected.json" > "$OUT/verify.txt" 2>&1; tail -3 "$OUT/verify.txt"
$WP eval-file "$H/replay.php" stock,smt "$OUT/replay-warm.json" 1 >/dev/null 2>&1; sleep 4
rm -f "$OUT/replay.json"
$WP eval-file "$H/replay.php" stock,smt "$OUT/replay.json" 15 > "$OUT/replay.txt" 2>&1; rm -f "$OUT/replay-warm.json"
$WP eval-file "$H/writes.php" "$OUT/writes.json" > /dev/null 2>&1
$WP speedup-meta drain
pkill -f 127.0.0.1:8099; sleep 1
php -d opcache.enable_cli=1 -d memory_limit=1G -d max_execution_time=300 -d error_reporting=0 -S 127.0.0.1:8099 -t www router.php > logs/php.log 2>&1 &
P=$!; sleep 2; ENG=stock,smt python3 "$H/e2e.py" "$OUT/pages.json" 7 > "$OUT/pages.txt" 2>&1; kill $P; sleep 1
PHP_CLI_SERVER_WORKERS=4 php -d opcache.enable_cli=1 -d memory_limit=1G -d max_execution_time=300 -d error_reporting=0 -S 127.0.0.1:8099 -t www router.php > logs/php.log 2>&1 &
P=$!; sleep 2; python3 "$H/yuk.py" 45 "$OUT/load.json" > "$OUT/load.txt" 2>&1; kill $P; pkill -f 127.0.0.1:8099
./q -e "SELECT table_name, ROUND((data_length+index_length)/1048576,1) mb FROM information_schema.tables WHERE table_schema=DATABASE() AND (table_name LIKE 'wp\_speedup\_meta\_%' OR table_name IN ('wp_postmeta','wp_usermeta','wp_wc_orders_meta','wp_posts','wp_users','wp_wc_orders')) ORDER BY 1" > "$OUT/disk.txt"
echo "end $(date)"; uptime; sysctl vm.swapusage
