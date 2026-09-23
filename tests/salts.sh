#!/usr/bin/env bash
set -euo pipefail
REPO=$(cd "$(dirname "$0")/.." && pwd)
T=$(mktemp -d); trap 'rm -rf "$T"' EXIT
mkdir -p "$T/bin" "$T/site"
printf '<?php\n// salt fixture\n' > "$T/site/wp-config.php"
cat > "$T/bin/wp" <<'WP'
#!/usr/bin/env bash
set -eu
cfg='';args=()
for a in "$@"; do case "$a" in --config-file=*)cfg=${a#*=};; --path=*|--skip-*|--no-color|--strict|--fields=*|--format=*|--force) :;; *)args+=("$a");; esac; done
set -- "${args[@]}"
case "$1 $2" in
 'config list')
  shift 2
  php -r '$s=file_get_contents($argv[1]);$rows=[];foreach(array_slice($argv,2) as $k){if(preg_match("/define\\(\"".preg_quote($k,"/")."\", \"([^\"]+)\"\\)/",$s,$m))$rows[]=["name"=>$k,"value"=>$m[1],"type"=>"constant"];}echo json_encode($rows);' "$cfg" "$@";;
 'config shuffle-salts')
  [ "${TEST_FAIL_STAGE:-0}" != 1 ] || exit 42
  php -r '$keys=($argv[2]??"")==="WP_CACHE_KEY_SALT"?["WP_CACHE_KEY_SALT"]:["AUTH_KEY","SECURE_AUTH_KEY","LOGGED_IN_KEY","NONCE_KEY","AUTH_SALT","SECURE_AUTH_SALT","LOGGED_IN_SALT","NONCE_SALT"];$s=file_get_contents($argv[1]);foreach($keys as $k){$line="define(\"$k\", \"".bin2hex(random_bytes(32))."\");";$p="/^define\\(\"".preg_quote($k,"/")."\", .*$/m";$s=preg_match($p,$s)?preg_replace($p,$line,$s):$s."\n".$line."\n";}file_put_contents($argv[1],$s);' "$cfg" "${3:-}"
  if [ "${TEST_BAD_STAGE:-0}" = 1 ]; then printf '<?php broken invalid syntax !\n' >> "$cfg"; fi;;
 *) exit 90;;
esac
WP
chmod +x "$T/bin/wp"
helper="$REPO/lib/salt-transaction.sh"
bash "$helper" rotate "$T/state" "$T/site" example.com auth "$T/bin/wp" > "$T/out"
grep -q $'OK\tCHANGED' "$T/out"
[ "$(grep -c '^define(' "$T/site/wp-config.php")" -eq 8 ]
old=$(sha256sum "$T/site/wp-config.php")
bash "$helper" rotate "$T/state" "$T/site" example.com auth "$T/bin/wp" > "$T/second"
[ "$(sha256sum "$T/site/wp-config.php")" != "$old" ]
old=$(sha256sum "$T/site/wp-config.php")
for flag in TEST_FAIL_STAGE TEST_BAD_STAGE; do
 if env "$flag=1" bash "$helper" rotate "$T/state" "$T/site" example.com auth "$T/bin/wp" >/dev/null 2>&1; then exit 1; fi
 [ "$(sha256sum "$T/site/wp-config.php")" = "$old" ]
done
# Cache namespace rotation remains a single-key transaction.
bash "$helper" rotate "$T/state" "$T/site" example.com cache "$T/bin/wp" > "$T/cache"
grep -q WP_CACHE_KEY_SALT "$T/site/wp-config.php"
find "$T/state/config-transactions" -name wp-config.php | grep -q .
! grep -q 'AUTH_KEY\|AUTH_SALT\|DB_PASSWORD' "$T/out" "$T/second"
# Wrong group and linked source remain fail closed.
if bash "$helper" rotate "$T/state" "$T/site" example.com arbitrary "$T/bin/wp" > /dev/null 2>&1; then exit 1; fi
mv "$T/site/wp-config.php" "$T/outside.php"; ln -s "$T/outside.php" "$T/site/wp-config.php"
if bash "$helper" rotate "$T/state" "$T/site" example.com auth "$T/bin/wp" >/dev/null 2>&1; then exit 1; fi
printf 'Salt transactions: grouped rotation, uniqueness/change checks, private staging, syntax/stage failure, cache key and symlink refusal PASS\n'
