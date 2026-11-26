#!/bin/sh
# Builds dist/speedup-meta-tables.zip with only the files the plugin needs (no benchmarks, verify/ or agent files).
set -eu
cd "$(dirname "$0")/.."
for f in speedup-meta-tables.php uninstall.php includes/*.php; do php -l "$f" >/dev/null; done
rm -rf dist && mkdir -p dist/speedup-meta-tables/includes
cp speedup-meta-tables.php uninstall.php readme.txt LICENSE dist/speedup-meta-tables/
cp includes/*.php dist/speedup-meta-tables/includes/
(cd dist && zip -qr speedup-meta-tables.zip speedup-meta-tables)
echo "dist/speedup-meta-tables.zip"
unzip -l dist/speedup-meta-tables.zip | tail -n +4 | sed '$d' | sed '$d'
