#!/usr/bin/env bash
set -euo pipefail
REPO=$(cd "$(dirname "$0")/.." && pwd)
T=$(mktemp -d "${TMPDIR:-/tmp}/pressharden-config-tx.XXXXXX")
trap 'rm -rf "$T"' EXIT
mkdir -p "$T/bin" "$T/state"

make_site() {
  local name="$1" p
  p="$T/sites/$name/public_html"
  mkdir -p "$p/wp-admin" "$p/wp-content" "$p/wp-includes"
  touch "$p/wp-load.php" "$p/wp-settings.php"
  printf '<?php $wp_version="7.1";\n' > "$p/wp-includes/version.php"
  printf '<?php\ndefine("DB_PASSWORD", "secret-sentinel"); define("DISALLOW_FILE_MODS", false); define("WP_ENVIRONMENT_TYPE", "production");\n' > "$p/wp-config.php"
}
for name in a.com b.com c.com d.com e.com f.com; do make_site "$name"; done
chmod 640 "$T/sites/a.com/public_html/wp-config.php"

cat > "$T/bin/wp-real" <<'WP'
#!/usr/bin/env bash
set -eu
file=''; args=()
for a in "$@"; do
  case "$a" in
    --config-file=*) file=${a#--config-file=} ;;
    --path=*|--skip-*|--no-color) ;;
    *) args+=("$a") ;;
  esac
done
set -- "${args[@]}"
[ "$1" = config ] || exit 90
case "$2" in
  get)
    key=$3
    case "$key" in
      DISALLOW_FILE_MODS) grep -q 'DISALLOW_FILE_MODS.*, true' "$file" && echo true || echo false ;;
      WP_ENVIRONMENT_TYPE) sed -n -E 's/.*WP_ENVIRONMENT_TYPE", "([^"]*)".*/"\1"/p' "$file" ;;
      WP_AUTO_UPDATE_CORE)
        grep -q 'WP_AUTO_UPDATE_CORE.*, true' "$file" && echo true || {
          grep -q 'WP_AUTO_UPDATE_CORE.*, false' "$file" && echo false || echo '"minor"'
        }
        ;;
      *) exit 91 ;;
    esac
    ;;
  set)
    key=$3; value=$4
    [ ! -f "${PH_TX_FAIL_STAGE:-/nonexistent}" ] || exit 92
    case "$key" in
      DISALLOW_FILE_MODS)
        if [ "$value" = true ]; then
          sed -i 's/DISALLOW_FILE_MODS", false/DISALLOW_FILE_MODS", true/' "$file"
        else
          sed -i 's/DISALLOW_FILE_MODS", true/DISALLOW_FILE_MODS", false/' "$file"
        fi
        ;;
      WP_ENVIRONMENT_TYPE)
        sed -i -E "s/WP_ENVIRONMENT_TYPE\", \"[^\"]*\"/WP_ENVIRONMENT_TYPE\", \"$value\"/" "$file"
        ;;
      WP_AUTO_UPDATE_CORE)
        if grep -q WP_AUTO_UPDATE_CORE "$file"; then
          if [ "$value" = true ] || [ "$value" = false ]; then
            sed -i -E "s/WP_AUTO_UPDATE_CORE\", [^)]*/WP_AUTO_UPDATE_CORE\", $value/" "$file"
          else
            sed -i -E "s/WP_AUTO_UPDATE_CORE\", [^)]*/WP_AUTO_UPDATE_CORE\", \"$value\"/" "$file"
          fi
        else
          if [ "$value" = true ] || [ "$value" = false ]; then
            printf '\ndefine("WP_AUTO_UPDATE_CORE", %s);\n' "$value" >> "$file"
          else
            printf '\ndefine("WP_AUTO_UPDATE_CORE", "%s");\n' "$value" >> "$file"
          fi
        fi
        ;;
      *) exit 93 ;;
    esac
    [ -z "${PH_TX_SLEEP:-}" ] || sleep "$PH_TX_SLEEP"
    ;;
  *) exit 94 ;;
esac
WP
chmod +x "$T/bin/wp-real"
ln -s "$T/bin/wp-real" "$T/bin/wp"
helper="$REPO/lib/config-transaction.php"
run_tx() { php "$helper" set "$T/state" "$1" "$2" "$3" "$4" "$5" "$T/bin/wp"; }

out=$(run_tx "$T/sites/a.com/public_html" a.com DISALLOW_FILE_MODS bool true)
grep -q $'OK\tCHANGED\t' <<< "$out"
grep -q 'DISALLOW_FILE_MODS", true' "$T/sites/a.com/public_html/wp-config.php"
[ "$(stat -c %a "$T/sites/a.com/public_html/wp-config.php")" = 640 ]

before=$(find "$T/state/config-transactions" -maxdepth 1 -type d -name 'tx-*' | wc -l)
out=$(run_tx "$T/sites/a.com/public_html" a.com DISALLOW_FILE_MODS bool true)
grep -q $'OK\tNOOP\t-' <<< "$out"
after=$(find "$T/state/config-transactions" -maxdepth 1 -type d -name 'tx-*' | wc -l)
[ "$before" -eq "$after" ]

run_tx "$T/sites/a.com/public_html" a.com WP_ENVIRONMENT_TYPE string staging >/dev/null
grep -q 'WP_ENVIRONMENT_TYPE", "staging"' "$T/sites/a.com/public_html/wp-config.php"

backup=$(find "$T/state/config-transactions" -path '*/wp-config.php' | head -1)
[ -f "$backup" ]
[ "$(stat -c %a "$backup")" = 600 ]
[ "$(stat -c %a "$(dirname "$backup")")" = 700 ]
find "$T/state/config-transactions" -name meta.json -exec grep -q 'COMPLETED' {} \;
! grep -R 'secret-sentinel' "$T/state/config-transactions" --include=meta.json >/dev/null

orig=$(sha256sum "$T/sites/b.com/public_html/wp-config.php")
touch "$T/fail-stage"; export PH_TX_FAIL_STAGE="$T/fail-stage"
if run_tx "$T/sites/b.com/public_html" b.com DISALLOW_FILE_MODS bool true >"$T/out" 2>"$T/err"; then
  echo 'stage failure unexpectedly succeeded' >&2; exit 1
fi
[ "$(sha256sum "$T/sites/b.com/public_html/wp-config.php")" = "$orig" ]
grep -q 'live wp-config.php was unchanged' "$T/err"
unset PH_TX_FAIL_STAGE

rm "$T/sites/c.com/public_html/wp-config.php"
ln -s "$T/sites/a.com/public_html/wp-config.php" "$T/sites/c.com/public_html/wp-config.php"
if run_tx "$T/sites/c.com/public_html" c.com DISALLOW_FILE_MODS bool false >/dev/null 2>"$T/err"; then
  echo 'symlink config unexpectedly accepted' >&2; exit 1
fi
grep -Eq 'unsafe wp-config|safe regular' "$T/err"

ln "$T/sites/d.com/public_html/wp-config.php" "$T/hardlink"
if run_tx "$T/sites/d.com/public_html" d.com DISALLOW_FILE_MODS bool true >/dev/null 2>"$T/err"; then
  echo 'hard-linked config unexpectedly accepted' >&2; exit 1
fi
grep -q 'single-link' "$T/err"

PH_TX_SLEEP=2 run_tx "$T/sites/e.com/public_html" e.com DISALLOW_FILE_MODS bool true >"$T/bg" 2>"$T/bgerr" & pid=$!
sleep .2
if run_tx "$T/sites/e.com/public_html" e.com WP_ENVIRONMENT_TYPE string staging >"$T/out" 2>"$T/err"; then
  echo 'concurrent same-site transaction unexpectedly succeeded' >&2; exit 1
fi
grep -q 'another PressHarden' "$T/err"
wait "$pid"

PH_TX_SLEEP=2 run_tx "$T/sites/f.com/public_html" f.com DISALLOW_FILE_MODS bool true >"$T/race" 2>"$T/raceerr" & pid=$!
sleep .3
printf '<?php // external-change\n' > "$T/sites/f.com/public_html/wp-config.php"
set +e; wait "$pid"; rc=$?; set -e
[ "$rc" -eq 2 ]
grep -q 'changed during staging' "$T/raceerr"
grep -q 'external-change' "$T/sites/f.com/public_html/wp-config.php"

make_site huge.com
printf '<?php\n' > "$T/sites/huge.com/public_html/wp-config.php"
head -c $((4*1024*1024+1)) /dev/zero >> "$T/sites/huge.com/public_html/wp-config.php"
if run_tx "$T/sites/huge.com/public_html" huge.com DISALLOW_FILE_MODS bool true >/dev/null 2>"$T/err"; then
  echo 'oversized config unexpectedly accepted' >&2; exit 1
fi
grep -q 'transaction limit\|safe regular' "$T/err"

printf 'Transactional wp-config staging, publication, locking, privacy and race refusal: PASS\n'
