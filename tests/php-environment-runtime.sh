#!/usr/bin/env bash
set -euo pipefail
REPO=$(cd "$(dirname "$0")/.." && pwd)
T=$(mktemp -d); trap 'rm -rf "$T"' EXIT
mkdir -p "$T/bin" "$T/state" "$T/cache/hostinger-php"
for d in a.example b.example c.example; do
  p="$T/sites/$d/public_html"; mkdir -p "$p/wp-admin" "$p/wp-content" "$p/wp-includes"
  touch "$p/wp-load.php" "$p/wp-settings.php"; printf '<?php $wp_version="7.1";\n' > "$p/wp-includes/version.php"
done
export PRESSHARDEN_SCAN_ROOT="$T/sites" PRESSHARDEN_STATE_DIR="$T/state" PRESSHARDEN_CACHE_DIR="$T/cache" PRESSHARDEN_CONFIG_FILE="$T/no-config" PRESSHARDEN_NOCOLOR=1 PRESSHARDEN_INTERACTIVE=0 PRESSHARDEN_HOSTINGER_API_TOKEN=ci-only PRESSHARDEN_HOSTINGER_USERNAME=fixture
for b in curl wget wp; do printf '#!/usr/bin/env bash\nexit 98\n' > "$T/bin/$b"; done
chmod +x "$T/bin/"*; export PATH="$T/bin:$PATH"
php -r '$j=["php_version_full"=>"8.5.4","options"=>["max_execution_time"=>["value"=>180,"default"=>300],"opcache.enable_cli"=>["value"=>""],"open_basedir"=>["value"=>""]],"extensions"=>["curl"=>["state"=>"On"]]];foreach(["a.example","b.example","c.example"] as $d)file_put_contents($argv[1]."/$d.json",json_encode($j));' "$T/cache/hostinger-php"
run(){ bash "$REPO/pressharden" php inspect "$@"; }
set +e; run > "$T/out" 2>&1; rc=$?; set -e
[ "$rc" -le 1 ] || { cat "$T/out"; exit 1; }
grep -q 'Settings: 3 consistent; 0 with differences' "$T/out"
! grep -q 'CUSTOM max_execution_time' "$T/out"
grep -q 'Full PHP settings and per-site values saved' "$T/out"
f=$(find "$T/state/reports" -name 'php-runtime-*-findings.log' | head -1)
grep -q 'CUSTOM max_execution_time' "$f"; grep -q 'open_basedir = (empty)' "$f"
[ "$(stat -c %a "$f")" = 600 ]
set +e; run a.example --details > "$T/details" 2>&1; rc=$?; set -e
[ "$rc" -le 1 ]; grep -q 'CUSTOM max_execution_time' "$T/details"
! grep -q 'b.example\|c.example' "$T/details"
# Source data isn't changed by presentation.
before=$(sha256sum "$T/cache/hostinger-php/"*)
set +e; run all > "$T/second" 2>&1; rc=$?; set -e
[ "$rc" -le 1 ]; [ "$(sha256sum "$T/cache/hostinger-php/"*)" = "$before" ]
# A damaged provider value yields incomplete, not a success/clean summary.
printf '{"php_version_full":"8.5.4","options":{"bad":{"value":[]}}}' > "$T/cache/hostinger-php/a.example.json"
set +e; run a.example > "$T/failure" 2>&1; rc=$?; set -e
[ "$rc" -eq 2 ]; grep -q INCOMPLETE "$T/failure"
! grep -q 'ALL CLEAR\|No differences among reported' "$T/failure"
# Missing/empty fields use explicit values, never shifting columns in Bash.
php -r '$j=["php_version_full"=>"8.5.4","options"=>["max_execution_time"=>["value"=>300],"opcache.enable_cli"=>["value"=>"On"],"open_basedir"=>["value"=>"/home/a"]],"extensions"=>[]];file_put_contents($argv[1],json_encode($j));' "$T/cache/hostinger-php/a.example.json"
set +e; run > "$T/outlier" 2>&1; rc=$?; set -e
[ "$rc" -le 1 ]; grep -q 'max_execution_time: 300 (common 180)' "$T/outlier"
grep -q 'open_basedir: /home/a (common (empty))' "$T/outlier"
! grep -q 'b.example —\|c.example —' "$T/outlier"
printf 'PHP environment runtime: compact/details, private evidence, named scope and failure propagation PASS\n'
