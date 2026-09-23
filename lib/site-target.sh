#!/usr/bin/env bash
# Discovery-only subprocess. Do not invoke WP-CLI or expose config values.
set -uo pipefail
ROOT="$1"
export ROOT PRESSHARDEN_DISCOVERY_REFRESH=1
. "$(cd "$(dirname "$0")" && pwd)/env-discovery.sh"
[ "${PH_DISCOVERY_FAILED:-0}" -eq 0 ] || { printf 'INCOMPLETE: local website discovery failed; no target selected.\n' >&2; exit 2; }
command -v php >/dev/null 2>&1 || { printf 'Website-name lookup requires PHP CLI; directory arguments still work.\n' >&2; exit 2; }
export PRESSHARDEN_SITE_ALIASES_FILE="${PRESSHARDEN_SITE_ALIASES_FILE:-}" PRESSHARDEN_EXCLUDE
{
  for site in "${SCAN_ROOTS[@]}"; do printf 'S\t%s\0' "$site"; done
  for site in "${MANUAL_EXCLUDED_ROOTS[@]}"; do printf 'E\t%s\0' "$site"; done
} | php "$PRESSHARDEN_DIR/lib/site-target.php" "$2" "$ROOT"
