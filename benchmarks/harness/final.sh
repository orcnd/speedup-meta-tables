#!/bin/sh
# Every check and measurement of benchmarks/, in order, on the current plugin code. About two hours.
set -u
H="$(cd "$(dirname "$0")" && pwd)"; OUT="$H/../results"; export LAB_DIR
WP="php -d memory_limit=2G $LAB_DIR/dl/wp-cli.phar --path=$LAB_DIR/www"
cd "$LAB_DIR"
if [ -z "${SKIP_WPTEST:-}" ]; then
echo "== wptest $(date)"
LAB_ENGINE=smt $WP eval-file "$H/wptest.php" 40 > "$OUT/wptest.txt" 2>&1; tail -1 "$OUT/wptest.txt"
fi
echo "== rebuild (wptest switches a key off on purpose)"
$WP speedup-meta build --wait=0 2>&1 | tail -1
echo "== race"
PLAN="$LAB_DIR/deney/plan.json" $WP eval-file "$H/race.php" 45 3 > "$OUT/race.txt" 2>&1; tail -1 "$OUT/race.txt"
echo "== edge cases"
LAB_ENGINE=smt $WP eval-file "$H/edge.php" > "$OUT/edge.txt" 2>&1; tail -1 "$OUT/edge.txt"
echo "== measurements"
"$H/run-all.sh"
echo "== done $(date)"
