#!/usr/bin/env bash
set -euo pipefail
REPO=$(cd "$(dirname "$0")/.." && pwd)
T=$(mktemp -d); trap 'rm -rf "$T"' EXIT
mkdir -p "$T/bin"
for name in example.com other.com; do
  p="$T/sites/$name/public_html"; mkdir -p "$p/wp-admin" "$p/wp-content" "$p/wp-includes"
  touch "$p/wp-load.php" "$p/wp-settings.php"; printf '<?php $wp_version="7.1";\n' > "$p/wp-includes/version.php"
  printf '<?php\ndefine("DISALLOW_FILE_MODS",false);\n' > "$p/wp-config.php"
done
cat > "$T/bin/wp" <<'WP'
#!/usr/bin/env bash
set -eu
p=''; cfg=''; args=()
for a in "$@"; do
  case "$a" in
    --path=*) p=${a#--path=} ;;
    --config-file=*) cfg=${a#--config-file=} ;;
    --skip-*|--no-color|--type=constant|--format=json|--raw) ;;
    *) args+=("$a") ;;
  esac
done
set -- "${args[@]}"
[ -n "$p" ] || exit 94
[ -n "$cfg" ] || cfg="$p/wp-config.php"
[ "$1" = config ] && [ -f "$cfg" ] || exit 95
case "$2" in
  get)
    grep -q 'DISALLOW_FILE_MODS", true' "$cfg" && echo true || echo false
    ;;
  set)
    value=$4
    tmp="$cfg.tmp.$$"
    grep -v 'define("DISALLOW_FILE_MODS"' "$cfg" > "$tmp" || true
    printf 'define("DISALLOW_FILE_MODS", %s);\n' "$value" >> "$tmp"
    mv "$tmp" "$cfg"
    ;;
  *) exit 96 ;;
esac
WP
chmod +x "$T/bin/wp"
export PATH="$T/bin:$PATH" PRESSHARDEN_SCAN_ROOT="$T/sites" PRESSHARDEN_CONFIG_FILE="$T/no-config" PRESSHARDEN_STATE_DIR="$T/state" PRESSHARDEN_CACHE_DIR="$T/cache" PRESSHARDEN_INTERACTIVE=0 PRESSHARDEN_NOCOLOR=1
run(){ bash "$REPO/pressharden" "$@"; }
old=$(sha256sum "$T/sites/other.com/public_html/wp-config.php")

# Normal PHP transaction engine remains supported.
run lock example.com > "$T/out"; grep -q 'file changes locked' "$T/out"
grep -q true "$T/sites/example.com/public_html/wp-config.php"
[ "$(sha256sum "$T/sites/other.com/public_html/wp-config.php")" = "$old" ]
run lock-status example.com > "$T/status"; grep -q 'LOCKED.*example.com' "$T/status"; ! grep -q other.com "$T/status"
find "$T/state/config-transactions" -name wp-config.php | grep -q .

# Shared hosts can disable PHP proc_open(). Force the safe shell transaction
# engine and prove the same named-site lock/unlock path still works.
PRESSHARDEN_CONFIG_TX_FORCE_SHELL=1 run unlock example.com > "$T/out"
grep -q false "$T/sites/example.com/public_html/wp-config.php"
run lock-status example.com > "$T/status"; grep -q 'UNLOCKED.*example.com' "$T/status"
find "$T/state/config-transactions" -name meta.json -exec grep -l 'shell-fallback' {} \; | grep -q .
PRESSHARDEN_CONFIG_TX_FORCE_SHELL=1 run lock example.com > "$T/out"
grep -q true "$T/sites/example.com/public_html/wp-config.php"

PRESSHARDEN_EXCLUDE=other.com run lock all > "$T/out"
[ "$(sha256sum "$T/sites/other.com/public_html/wp-config.php")" = "$old" ]
run lock all > "$T/out"; grep -q true "$T/sites/other.com/public_html/wp-config.php"
run unlock all > "$T/out"; grep -q false "$T/sites/other.com/public_html/wp-config.php"
printf 'Named/fleet lock actions: PHP and shared-host fallback transactions, backups, exclusions and readable status PASS\n'
