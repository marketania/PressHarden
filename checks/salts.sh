#!/usr/bin/env bash
set -uo pipefail
. "$(cd "$(dirname "$0")/.." && pwd)/lib/_lib.sh"
[ "$#" -eq 1 ] || exit 2
kind=$1
case "$kind" in auth|cache) :;; *) exit 2;; esac
ph_mutation_preflight || exit 2
if [ "$kind" = auth ]; then printf 'WARNING: authentication salt rotation invalidates current WordPress login cookies/nonces.\n'; fi
ph_confirm "Rotate the $kind salt group on ${#WP_SITES[@]} selected site(s), retaining private configuration backups?" || exit 1
failed=0; changed=0; skipped=0
for site in "${WP_SITES[@]}"; do
  label=$(site_label_from_root "$site")
  if [ "$kind" = cache ]; then
    dropin="$site/wp-content/object-cache.php"
    if [ ! -f "$dropin" ] || [ -L "$dropin" ] || ! grep -q 'WP_CACHE_KEY_SALT' "$dropin"; then
      printf 'SKIPPED %s: no supported drop-in reference to WP_CACHE_KEY_SALT.\n' "$label"; skipped=$((skipped+1)); continue
    fi
  fi
  out=$(bash "$PRESSHARDEN_DIR/lib/salt-transaction.sh" rotate "$PRESSHARDEN_STATE_DIR" "$site" "$label" "$kind" "$(command -v wp)") || { failed=$((failed+1)); continue; }
  IFS=$'\t' read -r mark state tx <<< "$out"
  if [ "$mark:$state" != OK:CHANGED ]; then printf 'FAILED %s: invalid transaction response.\n' "$label"; failed=$((failed+1)); continue; fi
  printf 'VERIFIED %s: %s salts rotated. Backup: %s/config-transactions/%s/wp-config.php\n' "$label" "$kind" "$PRESSHARDEN_STATE_DIR" "$tx"
  changed=$((changed+1))
done
printf 'Summary: changed %s; skipped %s; failed %s\n' "$changed" "$skipped" "$failed"
[ "$failed" -eq 0 ] || exit 2
