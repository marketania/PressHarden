#!/usr/bin/env bash
set -euo pipefail
REPO=$(cd "$(dirname "$0")/.." && pwd)
T=$(mktemp -d); trap 'rm -rf "$T"' EXIT
mkdir -p "$T/bin"
for name in a.com b.com c.com other.com; do
  p="$T/sites/$name/public_html"; mkdir -p "$p/wp-admin" "$p/wp-content" "$p/wp-includes"
  touch "$p/wp-load.php" "$p/wp-settings.php"; printf '<?php $wp_version="7.1";\n' > "$p/wp-includes/version.php"
  printf '<?php // policy fixture\n' > "$p/wp-config.php"
  printf 'locked\n' > "$p/.filemods"; printf 'disabled\n' > "$p/.editor"; printf 'minor\n' > "$p/.core"
  printf 'enabled\n' > "$p/.plugins"; printf '3/3\n' > "$p/.plugins-count"
  printf 'disabled\n' > "$p/.themes"; printf '0/2\n' > "$p/.themes-count"
  printf 'enabled\n' > "$p/.cron"; printf 'enabled\n' > "$p/.recovery"; printf 'production\n' > "$p/.environment"
  printf 'disabled\n' > "$p/.development"; printf 'disabled\n' > "$p/.debug"; printf 'enabled\n' > "$p/.ssl"
  printf 'disabled\n' > "$p/.alternate"
done
p="$T/sites/other.com/public_html"
printf 'unlocked\n' > "$p/.filemods"; printf 'enabled\n' > "$p/.editor"; printf 'major\n' > "$p/.core"
printf 'partial\n' > "$p/.plugins"; printf '1/3\n' > "$p/.plugins-count"; printf 'disabled\n' > "$p/.cron"
printf 'staging\n' > "$p/.environment"; printf 'enabled\n' > "$p/.debug"; printf 'disabled\n' > "$p/.ssl"

cat > "$T/bin/wp" <<'WP'
#!/usr/bin/env bash
set -eu
p=''; cfg=''; args=()
for a in "$@"; do case "$a" in --path=*) p=${a#--path=} ;; --config-file=*) cfg=${a#--config-file=} ;; --skip-*|--no-color|--type=constant|--format=json|--raw) ;; *) args+=("$a") ;; esac; done
set -- "${args[@]}"; [ -n "$p" ] || exit 90
[ -n "$cfg" ] || cfg="$p/wp-config.php"
case "$1" in
 eval-file)
   [ -f "$p/.bad-json" ] && { echo 'not-json'; exit 0; }
   label=${3:-site}
   filemods=$(cat "$p/.filemods"); editor=$(cat "$p/.editor"); core=$(cat "$p/.core"); plugins=$(cat "$p/.plugins"); pc=$(cat "$p/.plugins-count")
   themes=$(cat "$p/.themes"); tc=$(cat "$p/.themes-count"); cron=$(cat "$p/.cron"); recovery=$(cat "$p/.recovery")
   env=$(cat "$p/.environment"); dev=$(cat "$p/.development"); debug=$(cat "$p/.debug"); ssl=$(cat "$p/.ssl")
   updater=AVAILABLE; blockers=''; [ "$filemods" = locked ] && { updater=BLOCKED; blockers=DISALLOW_FILE_MODS; }
   [ "$filemods" = locked ] && fm=LOCKED || fm=UNLOCKED
   [ "$filemods" = locked ] && ed=DISABLED || { [ "$editor" = disabled ] && ed=DISABLED || ed=ENABLED; }
   printf '{"site":"%s","policy":{"file_mods":"%s","editor":"%s","core_updates":"%s","plugin_updates":"%s","plugin_updates_count":"%s","theme_updates":"%s","theme_updates_count":"%s","updater":"%s","updater_blockers":"%s","cron":"%s","recovery":"%s","environment":"%s","development":"%s","debug":"%s","debug_log":"INACTIVE","debug_display":"INACTIVE","savequeries":"DISABLED","script_debug":"DISABLED","force_ssl_admin":"%s","wp_cache":"ENABLED","revisions":"ENABLED","trash_days":"30 DAYS","autosave_interval":"60 SEC","wp_memory_limit":"128M","wp_max_memory_limit":"256M","db_charset":"UTF8MB4","db_collate":"DEFAULT","home_override":"DEFAULT","siteurl_override":"DEFAULT","cookie_domain":"DEFAULT","fs_method":"AUTO","allow_repair":"DISABLED","unfiltered_uploads":"DISABLED","unfiltered_html":"DISABLED","http_block_external":"DISABLED"}}\n' \
     "$label" "$fm" "$ed" "${core^^}" "${plugins^^}" "$pc" "${themes^^}" "$tc" "$updater" "$blockers" "${cron^^}" "${recovery^^}" "${env^^}" "${dev^^}" "${debug^^}" "${ssl^^}"
   ;;
 config)
   case "$2" in
    set)
      key=$3; value=$4; tmp="$cfg.tmp.$$"
      grep -v "define(\"$key\"" "$cfg" > "$tmp" || true
      case "$key" in
       WP_ENVIRONMENT_TYPE|WP_DEVELOPMENT_MODE) printf 'define("%s", "%s");\n' "$key" "$value" >> "$tmp" ;;
       DISALLOW_FILE_EDIT|DISABLE_WP_CRON|WP_DISABLE_FATAL_ERROR_HANDLER|WP_DEBUG|FORCE_SSL_ADMIN|ALTERNATE_WP_CRON) printf 'define("%s", %s);\n' "$key" "$value" >> "$tmp" ;;
       *) rm -f "$tmp"; exit 91 ;;
      esac
      mv "$tmp" "$cfg"
      ;;
    get)
      key=$3
      grep -q "define(\"$key\"" "$cfg" || exit 92
      case "$key" in
       WP_ENVIRONMENT_TYPE|WP_DEVELOPMENT_MODE) sed -n -E "s/.*define\(\"$key\", \"([^\"]*)\"\).*/\"\1\"/p" "$cfg" | tail -1 ;;
       *) grep "define(\"$key\"" "$cfg" | tail -1 | grep -q ', true)' && echo true || echo false ;;
      esac
      ;;
    is-true) [ "$3" = DISALLOW_FILE_MODS ] && [ "$(cat "$p/.filemods")" = locked ] ;;
    *) exit 93 ;;
   esac
   ;;
 *) exit 94 ;;
esac
WP
chmod +x "$T/bin/wp"
export PATH="$T/bin:$PATH" PRESSHARDEN_SCAN_ROOT="$T/sites" PRESSHARDEN_CONFIG_FILE="$T/no-config" PRESSHARDEN_STATE_DIR="$T/state" PRESSHARDEN_CACHE_DIR="$T/cache" PRESSHARDEN_INTERACTIVE=0 PRESSHARDEN_NOCOLOR=1 PRESSHARDEN_PROGRESS=0
run(){ bash "$REPO/pressharden" "$@"; }

run wp-settings a.com > "$T/one"
grep -q 'Security' "$T/one"; grep -q 'Core auto-updates.*MINOR' "$T/one"; grep -q 'Plugin auto-updates.*ENABLED (3/3)' "$T/one"
grep -q 'Retention / resources' "$T/one"; grep -q 'Configuration posture' "$T/one"

run wp-settings all > "$T/fleet"
grep -q 'BASELINE.*Security' "$T/fleet"; grep -q 'DIFF other.com' "$T/fleet"
! grep -q 'DIFF a.com' "$T/fleet"; grep -q 'Environment=STAGING' "$T/fleet"

run wp-settings set editor enabled a.com > "$T/set"; grep -q 'effective editor remains disabled' "$T/set"; grep -q 'define("DISALLOW_FILE_EDIT", false)' "$T/sites/a.com/public_html/wp-config.php"
run wp-settings set cron disabled a.com > "$T/set"; grep -q 'external/server cron' "$T/set"; grep -q 'define("DISABLE_WP_CRON", true)' "$T/sites/a.com/public_html/wp-config.php"
run wp-settings set recovery disabled a.com > "$T/set"; grep -q 'define("WP_DISABLE_FATAL_ERROR_HANDLER", true)' "$T/sites/a.com/public_html/wp-config.php"
run wp-settings set environment staging a.com > "$T/set"; grep -q 'define("WP_ENVIRONMENT_TYPE", "staging")' "$T/sites/a.com/public_html/wp-config.php"
run wp-settings set development theme a.com > "$T/set"; grep -q 'define("WP_DEVELOPMENT_MODE", "theme")' "$T/sites/a.com/public_html/wp-config.php"
run wp-settings set development disabled a.com > "$T/set"; grep -q 'define("WP_DEVELOPMENT_MODE", "")' "$T/sites/a.com/public_html/wp-config.php"
run wp-settings set debug enabled a.com > "$T/set"; grep -q 'define("WP_DEBUG", true)' "$T/sites/a.com/public_html/wp-config.php"
run wp-settings set force-ssl-admin disabled a.com > "$T/set"; grep -q 'define("FORCE_SSL_ADMIN", false)' "$T/sites/a.com/public_html/wp-config.php"
run wp-settings set alternate-cron enabled a.com > "$T/set"; grep -q 'compatibility workaround' "$T/set"; grep -q 'define("ALTERNATE_WP_CRON", true)' "$T/sites/a.com/public_html/wp-config.php"
find "$T/state/config-transactions" -name wp-config.php | grep -q .

[ "$(cat "$T/sites/b.com/public_html/.environment")" = production ]

touch "$T/sites/other.com/public_html/.bad-json"
if run wp-settings other.com > "$T/bad" 2>&1; then echo 'malformed policy unexpectedly passed' >&2; exit 1; fi
grep -q 'INCOMPLETE' "$T/bad"

printf 'WordPress settings dashboard: full site view, fleet baseline/diffs, safe setters, backups and malformed-data failure PASS\n'
