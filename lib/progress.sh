# Optional progress for quiet scan phases. Writes only to the controlling
# terminal, never to findings, JSON, or tee-captured console reports.
# Remember the original terminal before the suite/check logging pipelines.
if [ "${PH_PROGRESS_TERMINAL:-}" != 0 ] && [ "${PH_PROGRESS_TERMINAL:-}" != 1 ]; then
  PH_PROGRESS_TERMINAL=0
  { [ -t 1 ] || [ -t 2 ]; } && PH_PROGRESS_TERMINAL=1
  export PH_PROGRESS_TERMINAL
fi

ph_progress_init() {
  PH_PROGRESS_ACTIVE=0; PH_PROGRESS_TOTAL=0; PH_PROGRESS_WIDTH=79
  PH_PROGRESS_STARTED=$SECONDS; PH_PROGRESS_LAST=-2; PH_PROGRESS_DRAWN=0
  export PH_PROGRESS_ACTIVE PH_PROGRESS_TOTAL PH_PROGRESS_WIDTH
  case "${PRESSHARDEN_PROGRESS:-auto}" in
    0|off|false) return 0 ;;
    1|on|true) : ;;
    auto|'') [ "${PH_PROGRESS_TERMINAL:-0}" = 1 ] || return 0 ;;
    *) return 0 ;;
  esac
  # No /dev/fd, process substitution, persistent background jobs, or alternate
  # output files. A restricted/missing terminal silently disables this UX only.
  [ "${TERM:-dumb}" != dumb ] || return 0
  (exec 9>/dev/tty && [ -t 9 ]) 2>/dev/null || return 0
  local width
  width=$(tput cols 2>/dev/null) || width=80
  case "$width" in ''|*[!0-9]*) width=80 ;; esac
  [ "${#width}" -le 3 ] || width=80
  [ "$width" -ge 20 ] && [ "$width" -le 240 ] || width=80
  PH_PROGRESS_WIDTH=$((width-1)); PH_PROGRESS_ACTIVE=1
  export PH_PROGRESS_ACTIVE PH_PROGRESS_WIDTH
  return 0
}

ph_progress_draw() {
  [ "${PH_PROGRESS_ACTIVE:-0}" = 1 ] || return 0
  local text="$1" force="${2:-0}" elapsed=$((SECONDS-PH_PROGRESS_STARTED))
  if [ "$force" != 1 ] && [ "$((SECONDS-PH_PROGRESS_LAST))" -lt 2 ]; then return 0; fi
  PH_PROGRESS_LAST=$SECONDS
  # Only fixed phase labels, counts and a site label are supplied by callers.
  # Strip controls/non-ASCII before truncating so names cannot control a terminal.
  text=$(printf '%s' "$text" | LC_ALL=C tr -c ' -~' '?')
  text="    $text | $((elapsed/60))m $((elapsed%60))s"
  text=${text:0:PH_PROGRESS_WIDTH}
  if ! { printf '\r%-*s' "$PH_PROGRESS_WIDTH" "$text" > /dev/tty; } 2>/dev/null; then
    PH_PROGRESS_ACTIVE=0; export PH_PROGRESS_ACTIVE
  else PH_PROGRESS_DRAWN=1
  fi
  return 0
}

ph_progress_collect() {
  [ "${PH_PROGRESS_ACTIVE:-0}" = 1 ] || return 0
  ph_progress_draw "Collecting files | roots visited: $1/$2 | $3" "${4:-0}"
}

ph_progress_count_paths() {
  PH_PROGRESS_TOTAL=0; export PH_PROGRESS_TOTAL
  [ "${PH_PROGRESS_ACTIVE:-0}" = 1 ] || return 0
  local n
  # Count the already-created NUL-delimited list, not the directory tree or
  # source bodies. Newlines in a filename cannot inflate the denominator.
  n=$(LC_ALL=C tr -cd '\000' < "$1" | wc -c | tr -d '[:space:]') || return 0
  case "$n" in ''|*[!0-9]*) return 0 ;; esac
  [ "${#n}" -le 9 ] || return 0
  PH_PROGRESS_TOTAL="$n"; export PH_PROGRESS_TOTAL
  return 0
}

ph_progress_sites() {
  [ "${PH_PROGRESS_ACTIVE:-0}" = 1 ] || return 0
  local done="$1" total="$2" site="$3" percent=0
  [ "$total" -gt 0 ] && percent=$((done*100/total))
  # 'Processed' includes attempts which returned incomplete. The final scan
  # verdict, not this activity counter, determines coverage/success.
  ph_progress_draw "Database: $percent% | $done/$total sites processed | $site" "${4:-0}"
}

ph_progress_clear() {
  [ "${PH_PROGRESS_ACTIVE:-0}" = 1 ] || return 0
  { printf '\r%*s\r' "$PH_PROGRESS_WIDTH" '' > /dev/tty; } 2>/dev/null || true
  return 0
}

ph_progress_end() {
  ph_progress_clear
  PH_PROGRESS_ACTIVE=0; PH_PROGRESS_TOTAL=0
  export PH_PROGRESS_ACTIVE PH_PROGRESS_TOTAL
  return 0
}
