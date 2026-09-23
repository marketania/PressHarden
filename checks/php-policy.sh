#!/usr/bin/env bash
set -uo pipefail
. "$(cd "$(dirname "$0")/.." && pwd)/lib/_lib.sh"
action="${1:-status}"; shift || true
case "$action" in status) [ "$#" -eq 0 ] || exit 2;; set) [ "$#" -eq 2 ] || exit 2;; *) exit 2;; esac
require_wp; discover_sites
if [ "$action" = set ]; then
  ph_mutation_preflight || exit 2
  ph_confirm "Configure PHP directive $1=$2 for ${#WP_SITES[@]} selected site(s)? Web effect requires separate verification." || exit 1
fi
failed=0; pending=0
for site in "${WP_SITES[@]}"; do
  printf '\n%s\n' "$(site_label_from_root "$site")"
  rc=0
  if [ "$action" = set ]; then php "$PRESSHARDEN_DIR/lib/php-policy.php" set "$PRESSHARDEN_STATE_DIR" "$site" "$1" "$2" || rc=$?
  else php "$PRESSHARDEN_DIR/lib/php-policy.php" status "$site" || rc=$?; fi
  case "$rc" in 0) :;; 1) pending=$((pending+1));; *) failed=$((failed+1));; esac
done
printf '\nSummary: failed %s; awaiting web verification %s\n' "$failed" "$pending"
[ "$failed" -eq 0 ] || exit 2
[ "$pending" -eq 0 ] || exit 1
