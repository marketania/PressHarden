# Changelog

## Unreleased — PHP policy semantics and recovery

- Quote SameSite string values so INI `None` is not interpreted as empty; fix the corresponding incorrect no-op.
- Respect explicitly disabled/nondefault user-INI filenames and refuse array, duplicate or environment-driven target directives.
- Remove WP-CLI dependency from the inert local PHP workflow; preview validated current/proposed values before per-site approval.
- Bound reads, strengthen file/lock identity checks and verify private recovery metadata before publication. Preserve web-effect uncertainty.
- Add real INI, filesystem, PTY, lock and attribution regressions without changing update policies or publishing a release.

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
