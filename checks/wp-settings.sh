#!/usr/bin/env bash
NAME=wp-settings; DESC="WordPress policy dashboard and fleet configuration baseline"
SCAN_DOES="Collects an allowlisted effective WordPress policy covering file/editor controls, updates, cron, recovery, environment/development, debug/cache, retention/resources, and selected non-secret configuration posture."
SCAN_WHY="Turns scattered wp-config and WordPress policy values into one comparable fleet view so intentional exceptions stand out without treating every difference as a security finding."
. "$(cd "$(dirname "$0")/.." && pwd)/lib/_lib.sh"

ACTION="${1:-status}"
SETTING="${2:-}"
VALUE="${3:-}"

_policy_snapshot() {
  local site="$1" label="$2" out err
  out=$(tmpf); err=$(tmpf); : > "$out"; : > "$err"
  if ! wp eval-file "$PRESSHARDEN_DIR/lib/wp-policy-runtime.php" "$label" --path="$site" "${WPQ[@]}" > "$out" 2> "$err"; then
    rm -f "$out" "$err"; return 2
  fi
  if [ "$(grep -c . "$out" 2>/dev/null || true)" -ne 1 ]; then
    rm -f "$out" "$err"; return 2
  fi
  cat "$out"
  rm -f "$out" "$err"
}

_policy_status() {
  local rows summary site label failed=0 mode kind a b diffshown=0 diffcount=0 done=0 total=0 percent=0
  require_wp; discover_sites; banner
  sec "WordPress policy" "fleet baseline + exceptions; informational"
  rows=$(tmpf); summary=$(tmpf); : > "$rows"; : > "$summary"
  total=${#WP_SITES[@]}
  ph_progress_init
  ph_progress_draw "WordPress policy: 0% | 0/$total sites processed" 1
  for site in "${WP_SITES[@]}"; do
    label=$(site_label_from_root "$site")
    if ! _policy_snapshot "$site" "$label" >> "$rows"; then
      printf '    INCOMPLETE: %s policy snapshot could not be collected.\n' "$label" >&2
      failed=1
    fi
    done=$((done+1)); percent=$((done*100/total))
    if [ "$failed" -ne 0 ] && [ "$percent" -ge 100 ]; then percent=99; fi
    ph_progress_draw "WordPress policy: $percent% | $done/$total sites processed | $label" "$([ "$done" -eq "$total" ] && printf 1 || printf 0)"
  done
  if [ "$failed" -ne 0 ]; then
    ph_progress_draw "WordPress policy: INCOMPLETE | $done/$total sites attempted" 1
  else
    ph_progress_draw "WordPress policy: 100% | $done/$total sites processed" 1
  fi
  ph_progress_end
  if [ "$failed" -ne 0 ]; then
    [ -z "${DETAIL_LOG:-}" ] || { printf '\n[WP-SETTINGS] retained complete normalized snapshots before incomplete collection\n'; cat "$rows"; } >> "$DETAIL_LOG" 2>/dev/null || true
    rm -f "$rows" "$summary"; return 2
  fi
  [ -z "${DETAIL_LOG:-}" ] || { printf '\n[WP-SETTINGS] normalized per-site policy snapshots\n'; cat "$rows"; } >> "$DETAIL_LOG" || { rm -f "$rows" "$summary"; printf '    INCOMPLETE: policy detail report could not be written.\n' >&2; return 2; }
  if [ "${#WP_SITES[@]}" -eq 1 ]; then mode=single; else mode=fleet; fi
  if ! php "$PRESSHARDEN_DIR/lib/wp-policy-summary.php" "$rows" "$mode" > "$summary"; then
    rm -f "$rows" "$summary"; printf '    INCOMPLETE: policy baseline could not be summarized.\n' >&2; return 2
  fi
  if [ "$mode" = single ]; then
    while IFS=$'\t' read -r kind a b; do
      case "$kind" in
        SITE) printf '    %s%s%s\n' "$B$M" "$a" "$X" ;;
        GROUP) printf '\n    %s%s%s\n' "$B$C" "$a" "$X" ;;
        FIELD) printf '      %-26s %s\n' "$a" "$b" ;;
      esac
    done < "$summary"
  else
    while IFS=$'\t' read -r kind a b; do
      case "$kind" in
        COUNT) note "Fleet baseline uses the unique most-common value across $a complete site snapshot(s)." ;;
        BASELINE) _meta_field 12 "BASELINE" "$a: $b" ;;
        MIXED) note "MIXED — $a" ;;
        DIFF)
          diffshown=$((diffshown+1))
          if [ "$diffshown" -le 30 ]; then _meta_field 12 "DIFF $a" "$b"; fi
          ;;
        DIFFCOUNT) diffcount="$a" ;;
      esac
    done < "$summary"
    if [ "$diffcount" -eq 0 ]; then
      printf '    %s✓ CONSISTENT%s  no policy differences from the fleet baseline\n' "$G" "$X"
    elif [ "$diffcount" -gt 30 ]; then
      note "$diffcount site(s) differ from the baseline; first 30 shown, full normalized policy retained in the private detail report."
    else
      note "$diffcount site(s) differ from the baseline. Differences are informational, not proof of insecure configuration."
    fi
  fi
  note "Only allowlisted non-secret policy values are collected. Status does not change settings; configured values can be overridden at runtime."
  rm -f "$rows" "$summary"
  finish
}

_policy_set() {
  local key raw string expected human site label failed=0 blocker='' kind txvalue
  [ "${PRESSHARDEN_POLICY_APPLY:-0}" = 1 ] || { printf 'Refusing wp-settings mutation without the PressHarden CLI confirmation path.\n' >&2; return 2; }
  case "$SETTING:$VALUE" in
    editor:enabled) key=DISALLOW_FILE_EDIT; raw=false; expected=false; human='Dashboard editor ENABLED' ;;
    editor:disabled) key=DISALLOW_FILE_EDIT; raw=true; expected=true; human='Dashboard editor DISABLED' ;;
    cron:enabled) key=DISABLE_WP_CRON; raw=false; expected=false; human='WP-Cron ENABLED' ;;
    cron:disabled) key=DISABLE_WP_CRON; raw=true; expected=true; human='WP-Cron DISABLED' ;;
    recovery:enabled) key=WP_DISABLE_FATAL_ERROR_HANDLER; raw=false; expected=false; human='Recovery Mode ENABLED' ;;
    recovery:disabled) key=WP_DISABLE_FATAL_ERROR_HANDLER; raw=true; expected=true; human='Recovery Mode DISABLED' ;;
    debug:enabled) key=WP_DEBUG; raw=true; expected=true; human='Debug ENABLED' ;;
    debug:disabled) key=WP_DEBUG; raw=false; expected=false; human='Debug DISABLED' ;;
    debug-display:enabled) key=WP_DEBUG_DISPLAY; raw=true; expected=true; human="WP_DEBUG_DISPLAY ENABLED" ;;
    debug-display:disabled) key=WP_DEBUG_DISPLAY; raw=false; expected=false; human="WP_DEBUG_DISPLAY DISABLED" ;;
    debug-log:enabled) key=WP_DEBUG_LOG; raw=true; expected=true; human="WP_DEBUG_LOG ENABLED" ;;
    debug-log:disabled) key=WP_DEBUG_LOG; raw=false; expected=false; human="WP_DEBUG_LOG DISABLED" ;;
    script-debug:enabled) key=SCRIPT_DEBUG; raw=true; expected=true; human="SCRIPT_DEBUG ENABLED" ;;
    script-debug:disabled) key=SCRIPT_DEBUG; raw=false; expected=false; human="SCRIPT_DEBUG DISABLED" ;;
    savequeries:enabled) key=SAVEQUERIES; raw=true; expected=true; human="SAVEQUERIES ENABLED" ;;
    savequeries:disabled) key=SAVEQUERIES; raw=false; expected=false; human="SAVEQUERIES DISABLED" ;;
    force-ssl-admin:enabled) key=FORCE_SSL_ADMIN; raw=true; expected=true; human='Force SSL Admin ENABLED' ;;
    force-ssl-admin:disabled) key=FORCE_SSL_ADMIN; raw=false; expected=false; human='Force SSL Admin DISABLED' ;;
    alternate-cron:enabled) key=ALTERNATE_WP_CRON; raw=true; expected=true; human='Alternate WP-Cron ENABLED' ;;
    alternate-cron:disabled) key=ALTERNATE_WP_CRON; raw=false; expected=false; human='Alternate WP-Cron DISABLED' ;;
    environment:production|environment:staging|environment:development|environment:local) key=WP_ENVIRONMENT_TYPE; string="$VALUE"; expected="\"$VALUE\""; human="Environment ${VALUE^^}" ;;
    development:core|development:plugin|development:theme|development:all) key=WP_DEVELOPMENT_MODE; string="$VALUE"; expected="\"$VALUE\""; human="Development Mode ${VALUE^^}" ;;
    development:disabled) key=WP_DEVELOPMENT_MODE; string=''; expected='""'; human='Development Mode DISABLED' ;;
    *) printf 'Unsupported wp-settings value.\n' >&2; return 2 ;;
  esac
  ph_mutation_preflight || return 2
  for site in "${WP_SITES[@]}"; do
    label=$(site_label_from_root "$site")
    if [ -n "${raw:-}" ]; then kind=bool; txvalue="$raw"; else kind=string; txvalue="$string"; fi
    if ! ph_config_transaction_set "$site" "$label" "$key" "$kind" "$txvalue"; then
      printf '✖ %s: wp-config.php transaction failed; verified backup retained when one was created\n' "$label"
      failed=1; continue
    fi
    blocker=''
    if [ "$SETTING" = editor ] && wp config is-true DISALLOW_FILE_MODS --type=constant --path="$site" --no-color >/dev/null 2>&1; then blocker='; effective editor remains disabled while file modifications are locked'; fi
    if [ "$PH_CONFIG_TX_RESULT" = NOOP ]; then
      printf '✓ %s: %s (already configured)%s\n' "$label" "$human" "$blocker"
    else
      printf '✓ %s: %s%s\n' "$label" "$human" "$blocker"
    fi
  done
  [ "$SETTING:$VALUE" != cron:disabled ] || printf 'ℹ WP-Cron is disabled. Confirm an external/server cron invokes wp-cron.php on the intended schedule.\n'
  [ "$SETTING:$VALUE" != alternate-cron:enabled ] || printf 'ℹ Alternate WP-Cron is a compatibility workaround, not a general hardening setting.\n'
  [ "$failed" -eq 0 ] || return 2
}

main() {
  case "$ACTION" in
    status) _policy_status ;;
    set) _policy_set ;;
    *) printf 'Use wp-settings status or wp-settings set.\n' >&2; return 2 ;;
  esac
}

if [ "$ACTION" = status ]; then run_logged wp-settings; else main; fi
