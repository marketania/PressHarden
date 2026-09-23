# Shared transactional wp-config mutation wrapper.
# Callers provide a validated site, display label, allowlisted constant, kind,
# and value. The transaction helper owns backup, serialization, verification and rollback.
#
# Prefer the PHP helper when proc_open() is available. Shared hosts such as
# Hostinger may disable proc_open; in that case use the shell transaction engine
# so lock/unlock and other wp-config mutations remain usable without weakening
# PressHarden's staged-copy, verified-backup and atomic-publication safeguards.
ph_config_transaction_set() {
  local site="$1" label="$2" key="$3" kind="$4" value="$5" wp_bin out rc state _ok engine
  PH_CONFIG_TX_RESULT=''; PH_CONFIG_TX_ID=''; PH_CONFIG_TX_ENGINE=''
  command -v php >/dev/null 2>&1 || { printf 'CONFIG TRANSACTION: PHP CLI is required.\n' >&2; return 2; }
  wp_bin=$(command -v wp 2>/dev/null) || { printf 'CONFIG TRANSACTION: WP-CLI is required.\n' >&2; return 2; }
  case "$wp_bin" in /*) ;; *) printf 'CONFIG TRANSACTION: could not resolve WP-CLI executable.\n' >&2; return 2 ;; esac
  state="${PRESSHARDEN_STATE_DIR:?PRESSHARDEN_STATE_DIR is not set}"

  php "$PRESSHARDEN_DIR/lib/ops-safety.php" scope "$state" "$site" || return 2
  engine=php
  if [ "${PRESSHARDEN_CONFIG_TX_FORCE_SHELL:-0}" = 1 ] ||
     ! php -r '$d=array_filter(array_map("trim",explode(",",(string)ini_get("disable_functions")))); exit((!function_exists("proc_open") || in_array("proc_open",$d,true)) ? 1 : 0);' >/dev/null 2>&1; then
    engine=shell
  fi

  if [ "$engine" = shell ]; then
    command -v flock >/dev/null 2>&1 || {
      printf 'CONFIG TRANSACTION: PHP proc_open is unavailable and the safe shell fallback requires the flock command.\n' >&2
      return 2
    }
    out=$(bash "$PRESSHARDEN_DIR/lib/config-transaction-shell.sh" set "$state" "$site" "$label" "$key" "$kind" "$value" "$wp_bin")
    rc=$?
  else
    out=$(php "$PRESSHARDEN_DIR/lib/config-transaction.php" set "$state" "$site" "$label" "$key" "$kind" "$value" "$wp_bin")
    rc=$?
  fi

  [ "$rc" -eq 0 ] || return 2
  IFS=$'\t' read -r _ok PH_CONFIG_TX_RESULT PH_CONFIG_TX_ID <<< "$out"
  [ "$_ok" = OK ] || { printf 'CONFIG TRANSACTION: invalid helper response.\n' >&2; return 2; }
  case "$PH_CONFIG_TX_RESULT" in NOOP|CHANGED) ;; *) printf 'CONFIG TRANSACTION: invalid helper state.\n' >&2; return 2 ;; esac
  PH_CONFIG_TX_ENGINE="$engine"
  export PH_CONFIG_TX_RESULT PH_CONFIG_TX_ID PH_CONFIG_TX_ENGINE
  return 0
}
