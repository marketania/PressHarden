# Collect one complete, selected-site-bound policy snapshot. Provider warnings
# and unrecognized fields never enter the normalized report or terminal.
ph_policy_collect() {
  local site="$1" label="$2" out err rc=0
  out=$(tmpf); err=$(tmpf)
  if ! wp eval-file "$PRESSHARDEN_DIR/lib/wp-policy-runtime.php" "$label" --path="$site" "${WPQ[@]}" > "$out" 2> "$err" || [ -s "$err" ]; then
    rm -f -- "$out" "$err"; return 2
  fi
  php "$PRESSHARDEN_DIR/lib/wp-policy-input.php" record "$out" "$label" 2>/dev/null || rc=2
  rm -f -- "$out" "$err"
  return "$rc"
}
