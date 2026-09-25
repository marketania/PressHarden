# Changelog

## 0.1.1 — 2026-09-24

- Explicitly empty or invalid `sites` directory arguments now stop with exit 2 instead of falling back to fleet inventory.
- Directory targets retain exclusions relative to the original fleet root; discovery cache keys include that scope.
- Uninstall refuses update/recovery markers, including dangling symlinks, before changing managed files.
- Plugin/theme update-preference mutations now hold the site writer lock across backup, mutation, readback and recovery.

Add approved README artwork, distribution regressions, supported-PHP CI, pinned Actions and corrected project Codex defaults.

## 0.1.0 — initial Press family extraction

- Fix downloaded portable/user installation: validate the single-root archive, extract its contents without assuming validator output, and ignore inherited tar/gzip options. Add offline download-path, identity, archive/link and transport-failure regression tests.
- Independent wordpress hardening and security policy management product extracted from pinned PressWarden 1.1.24.
- Preserved relevant feature implementation and regression fixtures.
- Isolated all operational namespaces, configuration, state and lifecycle paths.
- Added fail-closed target/mutation boundaries, private recovery information and product-specific reporting.
- See README for deliberate safety changes and unsupported capabilities; this snapshot is not a claim of production-host validation.
