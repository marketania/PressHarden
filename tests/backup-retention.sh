#!/usr/bin/env bash
# Manual retention for wp-config transaction copies and per-category backups:
# preview by default, success-only, newest per site kept, exact previewed set.
set -euo pipefail
REPO=$(cd "$(dirname "$0")/.." && pwd)
T=$(mktemp -d); trap 'rm -rf "$T"' EXIT
export PRESSHARDEN_STATE_DIR="$T/state" PRESSHARDEN_CACHE_DIR="$T/cache" PRESSHARDEN_CONFIG_FILE="$T/no-config" PRESSHARDEN_INTERACTIVE=0 HOME="$T/home"
X="$T/state/config-transactions"; P="$T/state/backups/php-policy"
mkdir -p "$X/locks" "$P" "$T/home"; chmod -R go-rwx "$T/state"
mk_tx() { mkdir "$X/$1"; printf '{"site":"%s","status":"%s"}' "$2" "$3" > "$X/$1/meta.json"; printf '<?php // fixture\n' > "$X/$1/wp-config.php"; }
mk_php() { mkdir "$P/$1"; printf '{"status":"%s"}' "$2" > "$P/$1/meta.json"; printf 'memory_limit=1\n' > "$P/$1/original.user.ini"; }
a=/sites/a/public_html; b=/sites/b/public_html; h=aaaaaaaaaaaa; r=0123456789abcdef
mk_tx tx-20200101T000000Z-0a1b2c3d4e5f "$a" COMPLETED                      # eligible (PHP id form)
mk_tx tx-20200102T000000Z-4242-123456789 "$a" LIVE_VERIFY_FAILED_BACKUP_RETAINED  # kept: failure
mk_tx tx-20200103T000000Z-4243-987654321 "$a" COMPLETED                    # newest for a: kept
mk_tx tx-20200101T000000Z-4244-111 "$b" COMPLETED                          # only for b: kept
mk_php "20200101T000000Z-$h-$r" CONFIGURED_WEB_UNVERIFIED                  # eligible
mk_php "20200102T000000Z-$h-$r" PREPARED                                   # kept: incomplete
mk_php "$(date -u +%Y%m%dT%H%M%SZ)-$h-$r" CONFIGURED_WEB_UNVERIFIED       # fresh newest
run(){ bash "$REPO/pressharden" "$@"; }
for bad in '' 'prune' 'prune --older-than=0' 'prune --older-than=30 --force'; do
  read -r -a args <<< "$bad"
  set +e; run backups "${args[@]}" > "$T/bad" 2>&1; rc=$?; set -e
  [ "$rc" -eq 2 ]
done
set +e; run backups prune --older-than=30 --target example.com > "$T/bad" 2>&1; rc=$?; set -e; [ "$rc" -eq 2 ]
run backups prune --older-than=30 > "$T/preview"
grep -q 'Eligible: 2 backup(s)' "$T/preview"
grep -q 'PRUNE  config-transactions/tx-20200101T000000Z-0a1b2c3d4e5f' "$T/preview"
grep -q "PRUNE  php-policy/20200101T000000Z-$h-$r" "$T/preview"
grep -q 'KEEP   config-transactions/tx-20200102T000000Z-4242-123456789  status LIVE_VERIFY_FAILED_BACKUP_RETAINED' "$T/preview"
grep -q 'KEEP   config-transactions/tx-20200103T000000Z-4243-987654321  newest backup' "$T/preview"
grep -q 'KEEP   config-transactions/tx-20200101T000000Z-4244-111  newest backup' "$T/preview"
grep -q "KEEP   php-policy/20200102T000000Z-$h-$r  status PREPARED" "$T/preview"
[ -d "$X/tx-20200101T000000Z-0a1b2c3d4e5f" ]
printf 'php-policy/20200101T000000Z-%s-%s\n' "$h" "$r" > "$T/stale"; chmod 600 "$T/stale"
set +e; php "$REPO/lib/backup-retention.php" apply "$T/state" 30 "$T/stale" > "$T/stale.out" 2>&1; rc=$?; set -e
[ "$rc" -eq 2 ]; grep -q 'changed since preview' "$T/stale.out"; [ -d "$P/20200101T000000Z-$h-$r" ]
run backups prune --older-than=30 --apply > "$T/apply"
grep -q 'Backups removed: 2' "$T/apply"
[ ! -e "$X/tx-20200101T000000Z-0a1b2c3d4e5f" ] && [ ! -e "$P/20200101T000000Z-$h-$r" ]
[ -d "$X/tx-20200102T000000Z-4242-123456789" ] && [ -d "$X/locks" ] && [ -d "$P/20200102T000000Z-$h-$r" ]
! find "$T/state" -name '.pruning-*' | grep -q .
grep -q 'config-transactions/tx-20200101T000000Z-0a1b2c3d4e5f' "$T/state/backups/prune-log.tsv"
[ "$(stat -c %a "$T/state/backups/prune-log.tsv")" = 600 ]
mk_tx tx-20200101T000000Z-ffff "$a" COMPLETED
set +e; PRESSHARDEN_INTERACTIVE=1 run backups prune --older-than=30 --apply < /dev/null > "$T/notty" 2>&1; rc=$?; set -e
[ "$rc" -ne 0 ]; [ -d "$X/tx-20200101T000000Z-ffff" ]
printf 'Harden backup retention preview, success gate, newest-kept and exact approval: PASS\n'
