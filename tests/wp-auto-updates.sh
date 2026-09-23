#!/usr/bin/env bash
set -euo pipefail
REPO=$(cd "$(dirname "$0")/.." && pwd)
T=$(mktemp -d); trap 'rm -rf "$T"' EXIT
mkdir -p "$T/bin"
for name in example.com other.com; do
  p="$T/sites/$name/public_html"; mkdir -p "$p/wp-admin" "$p/wp-content" "$p/wp-includes"
  touch "$p/wp-load.php" "$p/wp-settings.php"; printf '<?php $wp_version="7.1";\n' > "$p/wp-includes/version.php"
  printf '<?php\ndefine("WP_AUTO_UPDATE_CORE", "minor");\n' > "$p/wp-config.php"
  printf 'minor\n' > "$p/.core"; printf '4\n' > "$p/.plugins-total"; printf '2\n' > "$p/.plugins-enabled"; printf '3\n' > "$p/.themes-total"; printf '0\n' > "$p/.themes-enabled"
done
# Keep one mixed theme state to reproduce WP-CLI's batch warning/error behavior.
printf '1\n' > "$T/sites/other.com/public_html/.themes-enabled"
touch "$T/sites/other.com/public_html/.DISALLOW_FILE_MODS"
cat > "$T/bin/wp" <<'WP'
#!/usr/bin/env bash
set -eu
p=''; cfg=''; args=(); for a in "$@"; do case "$a" in --path=*) p=${a#--path=} ;; --config-file=*) cfg=${a#--config-file=} ;; --skip-*|--no-color|--type=constant|--format=json|--raw) ;; *) args+=("$a") ;; esac; done
set -- "${args[@]}"; [ -n "$p" ] || exit 90
[ -n "$cfg" ] || cfg="$p/wp-config.php"
case "$1" in
 config)
   case "$2" in
    has) case "$3" in WP_AUTO_UPDATE_CORE) grep -q 'define("WP_AUTO_UPDATE_CORE"' "$cfg" ;; AUTOMATIC_UPDATER_DISABLED|DISALLOW_FILE_MODS) [ -f "$p/.${3}" ] ;; *) exit 1 ;; esac ;;
    get)
      case "$3" in
       WP_AUTO_UPDATE_CORE)
        grep -q 'define("WP_AUTO_UPDATE_CORE"' "$cfg" || exit 1
        grep -q 'WP_AUTO_UPDATE_CORE", true' "$cfg" && echo true || { grep -q 'WP_AUTO_UPDATE_CORE", false' "$cfg" && echo false || echo '"minor"'; }
        ;;
       *) exit 1 ;;
      esac ;;
    is-true) [ -f "$p/.${3}" ] ;;
    set)
      [ "$3" = WP_AUTO_UPDATE_CORE ] || exit 94
      value=$4; tmp="$cfg.tmp.$$"; grep -v 'define("WP_AUTO_UPDATE_CORE"' "$cfg" > "$tmp" || true
      if [ "$value" = true ] || [ "$value" = false ]; then printf 'define("WP_AUTO_UPDATE_CORE", %s);\n' "$value" >> "$tmp"; else printf 'define("WP_AUTO_UPDATE_CORE", "%s");\n' "$value" >> "$tmp"; fi
      mv "$tmp" "$cfg"
      ;;
   esac ;;
 option) case "$3" in auto_update_core_major) echo '"unset"';; auto_update_core_minor) echo '"enabled"';; *) exit 1;; esac ;;
 plugin|theme)
   type=$1; total=$(cat "$p/.${type}s-total"); enabled=$(cat "$p/.${type}s-enabled")
   [ "$2" = auto-updates ] || exit 91
   case "$3" in
    status)
      [ ! -f "$p/.status-fail-$type" ] || exit 95
      case " $* " in
       *' --field=name '*) i=1; while [ "$i" -le "$enabled" ]; do echo "$type$i"; i=$((i+1)); done ;;
       *' --enabled-only '*) echo "$enabled" ;;
       *) echo "$total" ;;
      esac
      ;;
    enable)
      if [ "${4:-}" = --all ]; then
        case " $* " in
          *' --disabled-only '*) [ "$enabled" -lt "$total" ] || exit 96; echo "$total" > "$p/.${type}s-enabled" ;;
          *) [ "$enabled" -eq 0 ] || exit 97; echo "$total" > "$p/.${type}s-enabled" ;;
        esac
      else
        cur=$(cat "$p/.${type}s-enabled"); [ "$cur" -lt "$total" ] && echo $((cur+1)) > "$p/.${type}s-enabled"
      fi
      ;;
    disable)
      if [ "${4:-}" = --all ]; then
        case " $* " in
          *' --enabled-only '*) [ "$enabled" -gt 0 ] || exit 98; echo 0 > "$p/.${type}s-enabled" ;;
          *) [ "$enabled" -eq "$total" ] || exit 99; echo 0 > "$p/.${type}s-enabled" ;;
        esac
      fi
      ;;
    *) exit 92 ;;
   esac ;;
 *) exit 93 ;;
esac
WP
chmod +x "$T/bin/wp"
export PATH="$T/bin:$PATH" PRESSHARDEN_SCAN_ROOT="$T/sites" PRESSHARDEN_CONFIG_FILE="$T/no-config" PRESSHARDEN_STATE_DIR="$T/state" PRESSHARDEN_CACHE_DIR="$T/cache" PRESSHARDEN_INTERACTIVE=0 PRESSHARDEN_NOCOLOR=1 PRESSHARDEN_PROGRESS=0
run(){ bash "$REPO/pressharden" "$@"; }

run auto-updates status example.com > "$T/status"
grep -q 'Core .*MINOR' "$T/status"; grep -q 'Plugins PARTIAL 2/4' "$T/status"; grep -q 'Themes DISABLED 0/3' "$T/status"
run auto-update status example.com > "$T/status-alias"
grep -q 'Core .*MINOR' "$T/status-alias"
run auto-updates status other.com > "$T/blocked"
grep -q 'BLOCKED by DISALLOW_FILE_MODS' "$T/blocked"

# Mixed plugin state: unfiltered `disable --all` is modeled as nonzero, matching
# WP-CLI batch semantics when some items are already disabled. PressHarden must
# operate on enabled items only, then treat a second disable as an idempotent no-op.
run auto-updates plugins disable other.com > "$T/out"
[ "$(cat "$T/sites/other.com/public_html/.plugins-enabled")" = 0 ]
grep -q 'Plugins auto-updates DISABLED' "$T/out"
run auto-updates plugins disable other.com > "$T/out"
grep -q 'already DISABLED' "$T/out"

run auto-updates core major example.com > "$T/out"; grep -q 'WP_AUTO_UPDATE_CORE", true' "$T/sites/example.com/public_html/wp-config.php"; grep -q 'WP_AUTO_UPDATE_CORE", "minor"' "$T/sites/other.com/public_html/wp-config.php"

# Mixed plugin state on example.com must also enable cleanly by selecting only
# disabled items. A repeated enable is a successful no-op, not a batch failure.
run auto-updates plugins enable example.com > "$T/out"; [ "$(cat "$T/sites/example.com/public_html/.plugins-enabled")" = 4 ]; [ "$(cat "$T/sites/other.com/public_html/.plugins-enabled")" = 0 ]
run auto-updates plugins enable example.com > "$T/out"; grep -q 'already ENABLED' "$T/out"

# other.com starts with 1/3 theme auto-updates enabled, so fleet enable also
# exercises the real mixed-state failure that was seen in production.
run auto-updates themes enable all > "$T/out"; [ "$(cat "$T/sites/example.com/public_html/.themes-enabled")" = 3 ]; [ "$(cat "$T/sites/other.com/public_html/.themes-enabled")" = 3 ]
run auto-updates themes disable other.com > "$T/out"; [ "$(cat "$T/sites/other.com/public_html/.themes-enabled")" = 0 ]
run auto-updates themes disable other.com > "$T/out"; grep -q 'already DISABLED' "$T/out"

run auto-updates core disabled other.com > "$T/out"; grep -q 'WP_AUTO_UPDATE_CORE", false' "$T/sites/other.com/public_html/wp-config.php"
run auto-updates status all > "$T/fleet"; grep -q 'Core .*MAJOR' "$T/fleet"; grep -q 'Core .*DISABLED' "$T/fleet"

# One unreadable site must not hide the readable site's status. The command
# remains exit 2 / INCOMPLETE because fleet coverage is partial.
touch "$T/sites/other.com/public_html/.status-fail-plugin"
if run auto-updates status all > "$T/partial" 2>&1; then
  echo 'partial fleet auto-update status unexpectedly returned success' >&2; exit 1
fi
grep -q 'example.com' "$T/partial"
grep -q 'INCOMPLETE.*1/2 site' "$T/partial"
grep -q 'other.com.*plugin auto-update inventory failed' "$T/partial"
rm -f "$T/sites/other.com/public_html/.status-fail-plugin"

# Standalone mutation/status commands remain; Fast/Full use the unified policy dashboard.
find "$T/state/config-transactions" -name wp-config.php | grep -q .
find "$T/state/backups/auto-updates" -type f | grep -q .
printf 'WordPress automatic-update policy: idempotent mixed-state changes, partial status, targeting and transactional core backups PASS\n'
