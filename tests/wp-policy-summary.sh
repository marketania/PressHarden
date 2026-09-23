#!/usr/bin/env bash
set -euo pipefail
REPO=$(cd "$(dirname "$0")/.." && pwd)
T=$(mktemp -d); trap 'rm -rf "$T"' EXIT

base='{"file_mods":"LOCKED","editor":"DISABLED","core_updates":"MINOR","plugin_updates":"ENABLED","plugin_updates_count":"3/3","theme_updates":"DISABLED","theme_updates_count":"0/2","updater":"BLOCKED","updater_blockers":"DISALLOW_FILE_MODS","cron":"ENABLED","recovery":"ENABLED","environment":"PRODUCTION","development":"DISABLED","debug":"DISABLED","wp_cache":"ENABLED"}'
for s in a.com b.com c.com; do printf '{"site":"%s","policy":%s}\n' "$s" "$base" >> "$T/rows"; done
printf '%s\n' '{"site":"other.com","policy":{"file_mods":"UNLOCKED","editor":"ENABLED","core_updates":"MAJOR","plugin_updates":"PARTIAL","plugin_updates_count":"1/3","theme_updates":"DISABLED","theme_updates_count":"0/2","updater":"AVAILABLE","updater_blockers":"","cron":"DISABLED","recovery":"ENABLED","environment":"STAGING","development":"DISABLED","debug":"ENABLED","wp_cache":"ENABLED"}}' >> "$T/rows"
php "$REPO/lib/wp-policy-summary.php" "$T/rows" fleet > "$T/out"
grep -q $'BASELINE\tSecurity\t.*File modifications=LOCKED (3/4)' "$T/out"
grep -q $'BASELINE\tUpdates\t.*Core auto-updates=MINOR (3/4)' "$T/out"
grep -q $'BASELINE\tRuntime\t.*Environment=PRODUCTION (3/4)' "$T/out"
grep -q $'DIFF\tother.com\t.*Environment=STAGING (baseline PRODUCTION)' "$T/out"
! grep -q $'DIFF\ta.com\t' "$T/out"
grep -q $'DIFFCOUNT\t1' "$T/out"

cat > "$T/tie" <<'EOF'
{"site":"one.com","policy":{"file_mods":"LOCKED","editor":"DISABLED","core_updates":"MINOR","plugin_updates":"ENABLED","plugin_updates_count":"1/1","theme_updates":"DISABLED","theme_updates_count":"0/1","updater":"BLOCKED","updater_blockers":"DISALLOW_FILE_MODS","cron":"ENABLED","recovery":"ENABLED","environment":"PRODUCTION","development":"DISABLED","debug":"DISABLED","wp_cache":"ENABLED"}}
{"site":"two.com","policy":{"file_mods":"UNLOCKED","editor":"ENABLED","core_updates":"MAJOR","plugin_updates":"DISABLED","plugin_updates_count":"0/1","theme_updates":"ENABLED","theme_updates_count":"1/1","updater":"AVAILABLE","updater_blockers":"","cron":"DISABLED","recovery":"DISABLED","environment":"STAGING","development":"PLUGIN","debug":"ENABLED","wp_cache":"DISABLED"}}
EOF
php "$REPO/lib/wp-policy-summary.php" "$T/tie" fleet > "$T/tie-out"
grep -q $'MIXED\tFile modifications: LOCKED=1, UNLOCKED=1' "$T/tie-out"
grep -q 'File modifications=MIXED' "$T/tie-out"
# A tied field has no arbitrary baseline, so neither site is called an outlier for that field.
! grep -q 'File modifications=.*baseline' "$T/tie-out"

printf 'WordPress policy summarizer: unique baseline, outlier-only differences and MIXED ties PASS\n'
