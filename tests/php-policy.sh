#!/usr/bin/env bash
set -euo pipefail
REPO=$(cd "$(dirname "$0")/.." && pwd)
T=$(mktemp -d); trap 'rm -rf "$T"' EXIT
mkdir -p "$T/site"; printf '<?php\n' > "$T/site/wp-config.php"
helper="$REPO/lib/php-policy.php"
refused(){ local rc=0; "$@" >/dev/null 2>&1 || rc=$?; [ "$rc" -eq 2 ]; }
php "$helper" status "$T/site" > "$T/status"; grep -q UNKNOWN "$T/status"
# No attested web SAPI: fail with no write.
refused php "$helper" set "$T/state" "$T/site" display_errors Off
[ ! -e "$T/site/.user.ini" ]
export PRESSHARDEN_PHP_WEB_SAPI=fpm-fcgi
set +e; php "$helper" set "$T/state" "$T/site" display_errors Off > "$T/changed"; rc=$?; set -e
[ "$rc" -eq 1 ]; grep -q 'display_errors = 0' "$T/site/.user.ini"; grep -q 'WEB EFFECT UNVERIFIED' "$T/changed"
find "$T/state/backups/php-policy" -name original.user.ini | grep -q .
before=$(sha256sum "$T/site/.user.ini")
for key in expose_php allow_url_fopen allow_url_include disable_functions auto_prepend_file; do
 refused php "$helper" set "$T/state" "$T/site" "$key" Off
 [ "$(sha256sum "$T/site/.user.ini")" = "$before" ]
done
# Preserve unknown unrelated directives; reject duplicates, sections and executable loaders.
printf 'precision = 14\ndisplay_errors = On\n' > "$T/site/.user.ini"
set +e; php "$helper" set "$T/state" "$T/site" display_errors Off >/dev/null; rc=$?; set -e
[ "$rc" -eq 1 ]; grep -q 'precision = 14' "$T/site/.user.ini"
for content in 'display_errors=On\ndisplay_errors=Off' '[PATH=/]\ndisplay_errors=On' 'auto_prepend_file=/tmp/loader.php'; do
 printf '%b\n' "$content" > "$T/site/.user.ini"; before=$(sha256sum "$T/site/.user.ini")
 refused php "$helper" set "$T/state" "$T/site" display_errors Off
 [ "$(sha256sum "$T/site/.user.ini")" = "$before" ]
done
rm "$T/site/.user.ini"; echo sentinel > "$T/other.ini"; ln -s "$T/other.ini" "$T/site/.user.ini"
refused php "$helper" set "$T/state" "$T/site" display_errors Off
grep -q sentinel "$T/other.ini"
printf 'PHP policy: status distinctions, supported staging, no false web success, private backups, unsupported keys, content/symlink refusal PASS\n'
