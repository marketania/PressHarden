# PressHarden runtime. No sibling application is required.
_PRESSHARDEN_LIB_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
. "$_PRESSHARDEN_LIB_DIR/env-discovery.sh"
. "$_PRESSHARDEN_LIB_DIR/progress.sh"
. "$_PRESSHARDEN_LIB_DIR/reports.sh"
. "$_PRESSHARDEN_LIB_DIR/ui.sh"
. "$_PRESSHARDEN_LIB_DIR/wp.sh"
. "$_PRESSHARDEN_LIB_DIR/operations.sh"
. "$_PRESSHARDEN_LIB_DIR/config-transaction.sh"
unset _PRESSHARDEN_LIB_DIR
