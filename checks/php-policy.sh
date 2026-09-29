#!/usr/bin/env bash
set -uo pipefail
. "$(cd "$(dirname "$0")/.." && pwd)/lib/_lib.sh"
action="${1:-status}"; shift || true
case "$action" in status) [ "$#" -eq 0 ] || exit 2;; set) [ "$#" -eq 2 ] || exit 2;; *) exit 2;; esac
# This local INI workflow never invokes WP-CLI or bootstraps WordPress.
discover_sites
if [ "$action" = set ]; then
  php "$PRESSHARDEN_DIR/lib/ops-safety.php" scope "$PRESSHARDEN_STATE_DIR" "${WP_SITES[@]}" || exit 2
  # The helper confirms the validated current/proposed values per site.
fi
failed=0; pending=0
for site in "${WP_SITES[@]}"; do
  printf '\n%s\n' "$(site_label_from_root "$site")"
  rc=0
  if [ "$action" = set ]; then php "$PRESSHARDEN_DIR/lib/php-policy.php" set "$PRESSHARDEN_STATE_DIR" "$site" "$1" "$2" || rc=$?
  else php "$PRESSHARDEN_DIR/lib/php-policy.php" status "$site" || rc=$?; fi
  case "$rc" in 0) :;; 1) pending=$((pending+1));; *) failed=$((failed+1));; esac
done
printf '\nSummary: failed %s; review/declined/web-unverified %s\n' "$failed" "$pending"
[ "$failed" -eq 0 ] || exit 2
[ "$pending" -eq 0 ] || exit 1
