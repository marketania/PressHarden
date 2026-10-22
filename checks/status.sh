#!/usr/bin/env bash
# Consolidated read-only status. Arguments are validated by the public CLI.
NAME=status
. "$(cd "$(dirname "$0")/.." && pwd)/lib/_lib.sh"
. "$PRESSHARDEN_DIR/lib/wp-policy-collect.sh"
areas="${1:-wp,php}"; verbose="${2:-0}"
case ",$areas," in *,wp,*|*,updates,*|*,locks,*) need_wp=1 ;; *) need_wp=0 ;; esac
case ",$areas," in *,php,*) need_php=1 ;; *) need_php=0 ;; esac

main() {
  local site label display_label row details php_details wp_line php_line wp_state php_state state text kind a b
  local incomplete=0 reviews=0 unverified=0 failed reviewed total wp_ready php_ready
  command -v php >/dev/null 2>&1 || die 'PHP CLI is required for status'
  discover_sites
  total=${#WP_SITES[@]}
  ph_ui_heading "PRESSHARDEN STATUS  v$PRESSHARDEN_VERSION"
  printf '  Read-only | %s site(s) | sections: %s\n' "$total" "$areas"
  printf '  OBSERVED means collected; UNVERIFIED means web PHP was not checked.\n\n'
  row=$(tmpf); details=$(tmpf); php_details=$(tmpf)
  for site in "${WP_SITES[@]}"; do
    label=$(site_label_from_root "$site"); display_label=$(php "$PRESSHARDEN_DIR/lib/status-summary.php" label "$label") || return 2; state=OBSERVED; failed=0; reviewed=0; text=''
    wp_ready=0; php_ready=0
    : > "$row"; : > "$details"; : > "$php_details"
    if [ "$need_wp" -eq 1 ]; then
      if ! command -v wp >/dev/null 2>&1; then
        text='WordPress: INCOMPLETE (WP-CLI missing from PATH; check pressharden doctor)'; failed=1
      elif ph_policy_collect "$site" "$label" > "$row" && wp_line=$(php "$PRESSHARDEN_DIR/lib/status-summary.php" wp "$row" "$areas" 2>/dev/null); then
        IFS=$'\t' read -r wp_state text <<< "$wp_line"
        wp_ready=1
        [ "$wp_state" != REVIEW ] || reviewed=1
        if ! { printf '\n[STATUS] validated WordPress snapshot\n'; cat "$row"; } >> "$DETAIL_LOG"; then failed=1; fi
      else
        text='WordPress: INCOMPLETE (WP-CLI failed or returned unverified data; inspect the trusted site runtime)'; failed=1
      fi
    fi
    if [ "$need_php" -eq 1 ]; then
      [ -z "$text" ] || text+=' | '
      if php_line=$(php "$PRESSHARDEN_DIR/lib/status-summary.php" php "$site" 2>/dev/null); then
        IFS=$'\t' read -r php_state php_line <<< "$php_line"
        php_ready=1
        text+="$php_line"; unverified=$((unverified+1))
        [ "$php_state" != REVIEW ] || reviewed=1
        state=UNVERIFIED
      else
        text+='PHP: INCOMPLETE (local configuration needs review)'; failed=1
      fi
    fi
    # Validate every requested view before deciding this site's status. A failed
    # detail renderer cannot leave a successful badge and zero incomplete count.
    if [ "$verbose" -eq 1 ]; then
      if [ "$wp_ready" -eq 1 ] && ! php "$PRESSHARDEN_DIR/lib/wp-policy-summary.php" "$row" single "$areas" > "$details" 2>/dev/null; then
        : > "$details"; failed=1; text+=' | WordPress details: INCOMPLETE (could not verify requested values)'
      fi
      if [ "$php_ready" -eq 1 ] && ! php "$PRESSHARDEN_DIR/lib/status-summary.php" php-details "$site" > "$php_details" 2>/dev/null; then
        : > "$php_details"; failed=1; text+=' | PHP details: INCOMPLETE (local configuration changed or could not be read)'
      fi
    fi
    [ "$reviewed" -eq 0 ] || { reviews=$((reviews+1)); state=REVIEW; }
    [ "$failed" -eq 0 ] || { incomplete=$((incomplete+1)); state=INCOMPLETE; }
    printf '  '; ph_ui_badge "$state"; printf ' %s%s%s\n' "$B$M" "$display_label" "$X"
    _meta_field 10 "" "$text"
    if [ "$verbose" -eq 1 ]; then
      if [ -s "$details" ]; then
        while IFS=$'\t' read -r kind a b; do
          case "$kind" in
            GROUP) printf '    %s%s%s\n' "$B$C" "$a" "$X" ;;
            FIELD) printf '      %-26s %s\n' "$a" "$b" ;;
          esac
        done < "$details"
      fi
      [ ! -s "$php_details" ] || cat "$php_details"
      printf '\n'
    fi
  done
  rm -f -- "$row" "$details" "$php_details"
  ph_ui_heading 'SUMMARY'
  printf '  Sites: %s | Need review: %s | Incomplete: %s | Web PHP unverified: %s\n' "$total" "$reviews" "$incomplete" "$unverified"
  if [ "$incomplete" -gt 0 ]; then
    printf '  Next: pressharden doctor --target SITE; resolve collection errors before retrying.\n'
  else
    printf '  Next: pressharden status --verbose --target SITE\n'
  fi
  printf '  Changes: pressharden help configure (explicit settings, preview and recovery).\n'
  printf '  No site settings changed. Global update blockers can be intentional.\n'
  [ "$incomplete" -eq 0 ] || return 2
  [ "$reviews" -eq 0 ] || return 1
}
run_logged status
