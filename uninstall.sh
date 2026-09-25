#!/usr/bin/env bash
set -euo pipefail
DIR=$(cd -P "$(dirname "${BASH_SOURCE[0]}")" && pwd -P)
[ "$(cat "$DIR/PRODUCT" 2>/dev/null)" = PressHarden ] || { echo 'Product identity mismatch; refused.' >&2; exit 2; }
[ ! -d "$DIR/.git" ] || { echo 'Refusing to uninstall a Git working tree.' >&2; exit 2; }
[ "$#" -le 1 ] && { [ "$#" -eq 0 ] || [ "$1" = --yes ]; } || { echo 'Usage: uninstall.sh [--yes]' >&2; exit 2; }
# Do not remove managed code while update/recovery depends on it.
if [ -e "$DIR/.pressharden-update.lock" ] || [ -L "$DIR/.pressharden-update.lock" ]; then
  echo 'Update/recovery lock is present; resolve the update before uninstalling.' >&2
  exit 2
fi
if [ "${1:-}" != --yes ]; then
  answer=''; { exec 9<>/dev/tty; } 2>/dev/null || { echo 'Confirmation terminal required; or pass --yes.' >&2; exit 2; }
  printf 'Remove PressHarden program files from %s, keeping configuration and state? [y/N]: ' "$DIR" >&9
  IFS= read -r answer <&9 || true
  case "$answer" in y|Y|yes|YES) :;; *) echo 'Cancelled.'; exit 1;; esac
fi
# Never remove the installation directory, var/, saved config, or external state.
owned=(.github .gitignore CHANGELOG.md CONTRIBUTING.md LICENSE README.md SECURITY.md VERSION PRODUCT PROVENANCE.md checks docs lib tests config/config.example install.sh uninstall.sh pressharden)
for item in "${owned[@]}"; do [ ! -L "$DIR/$item" ] || { echo 'Symlinked managed path refused.' >&2; exit 2; }; done
# Source trusted local config exactly as the CLI does, then reject private data
# under a program-owned directory. Preserve explicit caller overrides.
if [ -f "$DIR/.pressharden-portable" ]; then default_config="$DIR/config/config"; default_state="$DIR/var"; default_cache="$DIR/var/cache"
else default_config="${XDG_CONFIG_HOME:-$HOME/.config}/pressharden/config"; default_state="${XDG_STATE_HOME:-$HOME/.local/state}/pressharden"; default_cache="${XDG_CACHE_HOME:-$HOME/.cache}/pressharden"; fi
saved_config="${PRESSHARDEN_CONFIG_FILE:-$default_config}"
original_state="${PRESSHARDEN_STATE_DIR:-}"; original_cache="${PRESSHARDEN_CACHE_DIR:-}"; original_reports="${PRESSHARDEN_REPORTS_DIR:-}"
[ ! -r "$saved_config" ] || . "$saved_config"
private_state="${original_state:-${PRESSHARDEN_STATE_DIR:-$default_state}}"
private_cache="${original_cache:-${PRESSHARDEN_CACHE_DIR:-$default_cache}}"
private_reports="${original_reports:-${PRESSHARDEN_REPORTS_DIR:-$private_state/reports}}"
php "$DIR/lib/update-guard.php" layout "$DIR" "${owned[@]}" --private "$saved_config" "$private_state" "$private_cache" "$private_reports" "${PRESSHARDEN_BACKUPS_DIR:-$private_state/backups}" || exit 2
BIN="${PRESSHARDEN_BIN_DIR:-$HOME/.local/bin}"
if [ -L "$BIN/pressharden" ] && [ "$(readlink "$BIN/pressharden")" = "$DIR/pressharden" ]; then rm -- "$BIN/pressharden"; fi
for item in "${owned[@]}"; do rm -rf -- "$DIR/$item"; done
printf 'PressHarden program removed. Configuration, portable marker, and runtime state remain at %s and any configured external paths.\n' "$DIR"
