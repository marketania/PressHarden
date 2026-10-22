# Lightweight environment and terminal helpers; no discovery or state writes.

# Save exported names separately from their values. Line-oriented `env` parsing
# truncates multiline roots and exclusion lists before scope validation.
ph_cli_save_environment() {
  local key names
  PH_CLI_ENV_KEYS=(); PH_CLI_ENV_VALUES=()
  names=$(compgen -e || true)
  while IFS= read -r key; do
    [[ "$key" =~ ^PRESSHARDEN_[A-Za-z0-9_]*$ ]] || continue
    [ "${!key+x}" = x ] || continue
    PH_CLI_ENV_KEYS+=("$key"); PH_CLI_ENV_VALUES+=("${!key}")
  done <<< "$names"
}

ph_cli_restore_environment() {
  local i
  for i in "${!PH_CLI_ENV_KEYS[@]}"; do
    if ! printf -v "${PH_CLI_ENV_KEYS[$i]}" '%s' "${PH_CLI_ENV_VALUES[$i]}" 2>/dev/null || ! export "${PH_CLI_ENV_KEYS[$i]}"; then
      printf 'Configuration override could not be restored: %s. No operation started.\n' "${PH_CLI_ENV_KEYS[$i]}" >&2
      return 2
    fi
  done
  unset PH_CLI_ENV_KEYS PH_CLI_ENV_VALUES
}

ph_ui_init() {
  local mode="${PRESSHARDEN_COLOR:-auto}"
  case "$mode" in auto|always|never) ;; *) printf 'Color must be auto, always, or never.\n' >&2; return 2 ;; esac
  B=''; D=''; U=''; R=''; G=''; Y=''; BL=''; M=''; C=''; WHT=''; X=''
  [ -z "${NO_COLOR:-}" ] && [ -z "${PRESSHARDEN_NOCOLOR:-}" ] && [ "$mode" != never ] || return 0
  if [ "$mode" = always ] || { [ "$mode" = auto ] && [ "${TERM:-dumb}" != dumb ] && { [ -t 1 ] || { [ -n "${PRESSHARDEN_FORCE_COLOR:-}" ] && [ "$PRESSHARDEN_FORCE_COLOR" != 0 ]; }; }; }; then
    B=$'\033[1m'; D=$'\033[2m'; U=$'\033[4m'; R=$'\033[31m'; G=$'\033[32m'; Y=$'\033[33m'; BL=$'\033[34m'; M=$'\033[35m'; C=$'\033[36m'; WHT=$'\033[37m'; X=$'\033[0m'
  fi
}

ph_ui_heading() { printf '\n%s%s%s%s\n' "$B" "$C" "$1" "$X"; }
ph_ui_badge() {
  local color="$C"
  case "$1" in INCOMPLETE|FAILED) color="$R" ;; REVIEW|UNVERIFIED|UNLOCKED) color="$Y" ;; OBSERVED|LOCKED) color="$G" ;; esac
  printf '%s%s%-10s%s' "$B" "$color" "$1" "$X"
}
