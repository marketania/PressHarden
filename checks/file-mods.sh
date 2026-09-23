#!/usr/bin/env bash
. "$(cd "$(dirname "$0")/.." && pwd)/lib/_lib.sh"
action="${1:-status}"
case "$action" in status|on|off) ;; *) printf 'Usage: pressharden file-mods status|on|off [path]\n' >&2; exit 2 ;; esac
command -v wp >/dev/null 2>&1 || die "WP-CLI is required for file-mods"
discover_sites
if [ "$action" != status ]; then
  ph_mutation_preflight || exit 2
  ph_confirm "Set file modifications $action for ${#WP_SITES[@]} selected site(s)?" || exit 1
fi
failed=0
for s in "${SCAN_ROOTS[@]}"; do
  label=$(site_label_from_root "$s")
  cur=$(wpq "$s" config get DISALLOW_FILE_MODS --type=constant 2>/dev/null || printf '')
  if [ "$action" = status ]; then
    case "${cur,,}" in
      1|true) printf '    %sLOCKED%s    %s\n' "$G" "$X" "$label" ;;
      0|false) printf '    %sUNLOCKED%s  %s\n' "$Y" "$X" "$label" ;;
      *) printf '    %sNOT CONFIRMED%s  %s — restriction absent or unreadable\n' "$Y" "$X" "$label" ;;
    esac
    continue
  fi
  want=true; [ "$action" = off ] && want=false
  if ph_config_transaction_set "$s" "$label" DISALLOW_FILE_MODS bool "$want"; then
    if [ "$want" = true ]; then
      if [ "$PH_CONFIG_TX_RESULT" = NOOP ]; then ok "$label" "file changes already locked"; else ok "$label" "file changes locked"; fi
    else
      if [ "$PH_CONFIG_TX_RESULT" = NOOP ]; then ok "$label" "file changes already unlocked"; else ok "$label" "file changes unlocked"; fi
    fi
  else
    printf '    %s%s✖ INCOMPLETE%s  %s%s%s  wp-config.php transaction failed; verified backup retained when one was created\n' "$B" "$R" "$X" "$B" "$label" "$X" >&2
    failed=1
  fi
done
[ "$failed" -eq 0 ] || exit 2
