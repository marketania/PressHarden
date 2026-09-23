#!/usr/bin/env bash
# Shell fallback for transactional wp-config.php mutations when PHP proc_open()
# is unavailable (common on shared hosting). WP-CLI is executed by the shell,
# while all live-file writes retain PressHarden's staged-copy/verify/publish model.
set -uo pipefail

fail() {
  printf 'CONFIG TRANSACTION: %s.\n' "$1" >&2
  exit 2
}

[ "$#" -eq 8 ] && [ "${1:-}" = set ] || fail 'invalid arguments'
state_dir=$2
site_input=$3
label=$4
key=$5
kind=$6
value=$7
wp_bin=$8

case "$key" in
  DISALLOW_FILE_MODS|DISALLOW_FILE_EDIT|DISABLE_WP_CRON|WP_DISABLE_FATAL_ERROR_HANDLER|WP_DEBUG|WP_DEBUG_DISPLAY|WP_DEBUG_LOG|SCRIPT_DEBUG|SAVEQUERIES|FORCE_SSL_ADMIN|ALTERNATE_WP_CRON|WP_ENVIRONMENT_TYPE|WP_DEVELOPMENT_MODE|WP_AUTO_UPDATE_CORE) ;;
  *) fail 'unsupported wp-config key' ;;
esac
case "$kind" in
  bool)
    case "$value" in true|false) ;; *) fail 'invalid boolean value' ;; esac
    case "$key" in WP_ENVIRONMENT_TYPE|WP_DEVELOPMENT_MODE) fail 'invalid boolean key' ;; esac
    expected=$value
    ;;
  string)
    case "$key:$value" in
      WP_ENVIRONMENT_TYPE:production|WP_ENVIRONMENT_TYPE:staging|WP_ENVIRONMENT_TYPE:development|WP_ENVIRONMENT_TYPE:local|WP_DEVELOPMENT_MODE:|WP_DEVELOPMENT_MODE:core|WP_DEVELOPMENT_MODE:plugin|WP_DEVELOPMENT_MODE:theme|WP_DEVELOPMENT_MODE:all|WP_AUTO_UPDATE_CORE:minor) ;;
      *) fail 'invalid string value' ;;
    esac
    expected="\"$value\""
    ;;
  *) fail 'invalid value kind' ;;
esac

case "$state_dir$site_input$label$key$kind$value$wp_bin" in
  *$'\n'*|*$'\r'*) fail 'invalid control character in transaction input' ;;
esac
[ -x "$wp_bin" ] || fail 'unsafe WP-CLI executable'
[ -d "$site_input" ] || fail 'unsafe WordPress site path'
[ ! -L "$site_input" ] || fail 'unsafe WordPress site path'
site=$(cd -P -- "$site_input" 2>/dev/null && pwd -P) || fail 'unsafe WordPress site path'
config="$site/wp-config.php"
[ -f "$config" ] && [ ! -L "$config" ] || fail 'wp-config.php is not a safe regular single-link file'

_snapshot() {
  local f=$1 s1 s2 hash nlink size
  [ -f "$f" ] && [ ! -L "$f" ] || return 1
  s1=$(stat -c '%d|%i|%s|%a|%u|%g|%h' -- "$f" 2>/dev/null) || return 1
  nlink=${s1##*|}; [ "$nlink" = 1 ] || return 1
  size=$(printf '%s' "$s1" | cut -d'|' -f3)
  case "$size" in ''|*[!0-9]*) return 1 ;; esac
  [ "$size" -le 4194304 ] || return 1
  hash=$(sha256sum -- "$f" 2>/dev/null | awk '{print $1}') || return 1
  s2=$(stat -c '%d|%i|%s|%a|%u|%g|%h' -- "$f" 2>/dev/null) || return 1
  [ "$s1" = "$s2" ] || return 1
  printf '%s|%s' "$hash" "$s1"
}

_cfg_get() {
  "$wp_bin" config get "$key" --type=constant --format=json --config-file="$1" \
    --path="$site" --skip-plugins --skip-themes --skip-packages --no-color 2>/dev/null
}

current=$(_cfg_get "$config" || true)
if [ "$current" = "$expected" ]; then
  printf 'OK\tNOOP\t-\n'
  exit 0
fi

original=$(_snapshot "$config") || fail 'wp-config.php is unreadable, oversized, changing, or not a safe regular single-link file'
IFS='|' read -r orig_hash orig_dev orig_ino orig_size orig_mode orig_uid orig_gid orig_nlink <<< "$original"
[ "$orig_uid" = "$(id -u)" ] || fail 'wp-config.php owner differs from the current account; mutation refused'

php "$(dirname "$0")/ops-safety.php" scope "$state_dir" "$site" || fail 'unsafe state/site scope'
[ ! -L "$state_dir" ] || fail 'unsafe state directory'
mkdir -p -- "$state_dir/config-transactions/locks" 2>/dev/null || fail 'cannot create config transaction state directory'
chmod 700 "$state_dir" "$state_dir/config-transactions" "$state_dir/config-transactions/locks" 2>/dev/null || true
command -v flock >/dev/null 2>&1 || fail 'shell transaction fallback requires the flock command'
lock_hash=$(printf '%s' "$site" | sha256sum | awk '{print $1}')
lock_file="$state_dir/config-transactions/locks/$lock_hash.lock"
umask 077
if [ ! -e "$lock_file" ] && [ ! -L "$lock_file" ]; then
  (umask 077; set -C; : > "$lock_file") 2>/dev/null || true
fi
[ -f "$lock_file" ] && [ ! -L "$lock_file" ] && [ "$(stat -c %h "$lock_file")" = 1 ] && [ "$(stat -c %u "$lock_file")" = "$(id -u)" ] || fail 'unsafe lock file'
lock_identity=$(stat -c '%d:%i' "$lock_file") || fail 'cannot inspect lock'
exec 9<>"$lock_file" || fail 'cannot open config transaction lock'
[ "$lock_identity" = "$(stat -Lc '%d:%i' /proc/self/fd/9)" ] || fail 'lock changed while opening' 
chmod 600 "$lock_file" 2>/dev/null || true
flock -n 9 || fail 'another PressHarden wp-config mutation is active for this site'

# Re-check after taking the lock in case another PressHarden process completed
# between the optimistic no-op check and lock acquisition.
current=$(_cfg_get "$config" || true)
if [ "$current" = "$expected" ]; then
  printf 'OK\tNOOP\t-\n'
  exit 0
fi
original=$(_snapshot "$config") || fail 'wp-config.php changed while acquiring the transaction lock'
IFS='|' read -r orig_hash orig_dev orig_ino orig_size orig_mode orig_uid orig_gid orig_nlink <<< "$original"
[ "$orig_uid" = "$(id -u)" ] || fail 'wp-config.php owner differs from the current account; mutation refused'

tx_id="tx-$(date -u +%Y%m%dT%H%M%SZ)-$$-${RANDOM}${RANDOM}"
tx_dir="$state_dir/config-transactions/$tx_id"
mkdir -- "$tx_dir" 2>/dev/null || fail 'cannot create config transaction directory'
chmod 700 "$tx_dir" 2>/dev/null || true
backup="$tx_dir/wp-config.php"
stage="$tx_dir/staged-wp-config.php"
meta="$tx_dir/meta.json"

_private_copy() {
  local src=$1 dst=$2
  (umask 077; cat -- "$src" > "$dst") || return 1
  chmod 600 "$dst" 2>/dev/null || true
}
_private_copy "$config" "$backup" || fail 'cannot create verified wp-config.php backup'
backup_snap=$(_snapshot "$backup") || fail 'backup verification failed; no mutation attempted'
IFS='|' read -r backup_hash _ <<< "$backup_snap"
[ "$backup_hash" = "$orig_hash" ] || fail 'backup verification failed; no mutation attempted'
_private_copy "$backup" "$stage" || fail 'cannot create staged wp-config.php copy'

_write_meta() {
  local status=$1
  php -r '
    $d = array(
      "format" => 1, "tool" => "PressHarden", "engine" => "shell-fallback",
      "transaction_id" => $argv[2], "site" => $argv[3], "site_label" => $argv[4],
      "key" => $argv[5], "kind" => $argv[6], "requested_value" => $argv[7],
      "status" => $argv[8], "original_sha256" => $argv[9],
      "original_size" => (int)$argv[10], "original_mode" => $argv[11],
      "backup" => "wp-config.php", "updated_at" => gmdate("c")
    );
    $j = json_encode($d, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if ($j === false || file_put_contents($argv[1], $j."\n") === false) exit(2);
  ' "$meta" "$tx_id" "$site" "$label" "$key" "$kind" "$value" "$status" "$orig_hash" "$orig_size" "$orig_mode" >/dev/null 2>&1 || return 1
  chmod 600 "$meta" 2>/dev/null || true
}
_write_meta PREPARED || fail 'cannot publish transaction metadata'

args=(config set "$key" "$value" --type=constant --config-file="$stage" --path="$site" --skip-plugins --skip-themes --skip-packages --no-color)
[ "$kind" = bool ] && args+=(--raw)
if ! "$wp_bin" "${args[@]}" >/dev/null 2>&1; then
  _write_meta STAGE_MUTATION_FAILED || true
  fail 'WP-CLI could not prepare the staged config; live wp-config.php was unchanged'
fi
if ! php -l "$stage" >/dev/null 2>&1 || ! php -r '$s=file_get_contents($argv[1]);foreach(token_get_all($s) as $t){if(is_array($t)&&$t[0]===T_OPEN_TAG)exit(0);}exit(1);' "$stage"; then
  _write_meta STAGE_SYNTAX_FAILED || true
  fail 'staged PHP syntax validation failed; live config unchanged'
fi
staged_value=$(_cfg_get "$stage" || true)
if [ "$staged_value" != "$expected" ]; then
  _write_meta STAGE_VERIFY_FAILED || true
  fail 'staged config verification failed; live wp-config.php was unchanged'
fi
staged=$(_snapshot "$stage") || fail 'staged config became unsafe or unreadable'
IFS='|' read -r staged_hash _ <<< "$staged"

live_before=$(_snapshot "$config") || fail 'wp-config.php changed during staging; live file was not modified'
if [ "$live_before" != "$original" ]; then
  _write_meta REFUSED_SOURCE_CHANGED || true
  fail 'wp-config.php changed during staging; live file was not modified'
fi

_publish_source() {
  local src=$1 expected_live=$2 tmp temp_gid now
  tmp="$(dirname "$config")/.pressharden-config-publish.$$.$RANDOM.tmp"
  [ ! -e "$tmp" ] && [ ! -L "$tmp" ] || return 1
  (umask 077; cat -- "$src" > "$tmp") || { rm -f -- "$tmp"; return 1; }
  chmod "$orig_mode" "$tmp" 2>/dev/null || { rm -f -- "$tmp"; return 1; }
  temp_gid=$(stat -c '%g' -- "$tmp" 2>/dev/null) || { rm -f -- "$tmp"; return 1; }
  if [ "$temp_gid" != "$orig_gid" ]; then
    chgrp "$orig_gid" "$tmp" 2>/dev/null || { rm -f -- "$tmp"; return 1; }
  fi
  [ "$(stat -c '%u' -- "$tmp" 2>/dev/null)" = "$orig_uid" ] || { rm -f -- "$tmp"; return 1; }
  now=$(_snapshot "$config") || { rm -f -- "$tmp"; return 1; }
  [ "$now" = "$expected_live" ] || { rm -f -- "$tmp"; return 3; }
  mv -f -- "$tmp" "$config" 2>/dev/null || { rm -f -- "$tmp"; return 1; }
  return 0
}

_publish_source "$stage" "$original"
pub_rc=$?
case "$pub_rc" in
  0) ;;
  3) _write_meta REFUSED_SOURCE_CHANGED || true; fail 'wp-config.php changed before publication; live file was not modified' ;;
  *) _write_meta PUBLICATION_FAILED || true; fail 'atomic wp-config.php publication failed; verified backup retained' ;;
esac

published=$(_snapshot "$config") || { _write_meta LIVE_VERIFY_FAILED_BACKUP_RETAINED || true; fail 'published wp-config.php verification failed; verified backup retained'; }
IFS='|' read -r pub_hash pub_dev pub_ino pub_size pub_mode pub_uid pub_gid pub_nlink <<< "$published"
if [ "$pub_hash" != "$staged_hash" ] || [ "$pub_mode" != "$orig_mode" ] || [ "$pub_uid" != "$orig_uid" ] || [ "$pub_gid" != "$orig_gid" ]; then
  _write_meta LIVE_VERIFY_FAILED_BACKUP_RETAINED || true
  fail 'published wp-config.php verification failed; verified backup retained'
fi

live_value=$(_cfg_get "$config" || true)
if [ "$live_value" != "$expected" ]; then
  current_snap=$(_snapshot "$config" || true)
  if [ "$current_snap" = "$published" ]; then
    if _publish_source "$backup" "$published"; then
      restored=$(_snapshot "$config" || true)
      IFS='|' read -r rest_hash rest_dev rest_ino rest_size rest_mode rest_uid rest_gid rest_nlink <<< "$restored"
      if [ "$rest_hash" = "$orig_hash" ] && [ "$rest_size" = "$orig_size" ] && [ "$rest_mode" = "$orig_mode" ] && [ "$rest_uid" = "$orig_uid" ] && [ "$rest_gid" = "$orig_gid" ]; then
        _write_meta LIVE_VERIFY_FAILED_ROLLED_BACK || true
        fail 'live verification failed; original wp-config.php rollback verified'
      fi
    fi
  fi
  _write_meta LIVE_VERIFY_FAILED_BACKUP_RETAINED || true
  fail 'live verification failed; automatic rollback was unsafe, verified backup retained'
fi

_write_meta COMPLETED || fail 'config change succeeded but final transaction metadata could not be written'
printf 'OK\tCHANGED\t%s\n' "$tx_id"
