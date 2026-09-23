# WordPress automatic-update policy

PressHarden can inspect and deliberately set WordPress core, plugin, and theme auto-update preferences using the same website-name targeting as scan and lock/unlock.

```bash
./pressharden auto-updates status example.com
./pressharden auto-updates status all

./pressharden auto-updates core minor example.com
./pressharden auto-updates core major example.com
./pressharden auto-updates core disabled example.com

./pressharden auto-updates plugins enable example.com
./pressharden auto-updates plugins disable all
./pressharden auto-updates themes enable example.com
./pressharden auto-updates themes disable all
```

`core minor` writes `WP_AUTO_UPDATE_CORE='minor'`; `major` writes `true`; `disabled` writes `false`. The constant overrides the normal core auto-update choice in wp-admin. PressHarden does not change `AUTOMATIC_UPDATER_DISABLED` or `DISALLOW_FILE_MODS` when setting a policy. If either blocks the automatic updater, status and set commands say the preference is configured but blocked.

Plugin/theme enable and disable use the WordPress/WP-CLI per-item auto-update preference for all installed items in the selected installation. Status reports ENABLED when all installed items are enabled, DISABLED when none are enabled, PARTIAL when only some are enabled, and N/A when none are installed. These preferences can still be overridden by WordPress filters or emergency-update responses; PressHarden reports the stored preference and known global config blockers, not a proof that a future update will execute.

Core changes back up `wp-config.php` before editing and verify the resulting policy. Plugin/theme changes save the previously enabled item list privately, verify the new count, and attempt to restore the prior list if the operation or verification fails. No command automatically unlocks file modifications or removes a global updater blocker.

The read-only `wp-auto-updates` check is part of Fast and Full. Fleet scans use compact counts rather than printing every site; per-site state is retained in the private detail report. A one-site `auto-updates status example.com` prints the exact core/plugin/theme state directly. The check is informational and does not create a security finding solely because an operator chose minor, major, disabled, enabled, partial, or disabled plugin/theme updates.

Core semantics follow WordPress: `WP_AUTO_UPDATE_CORE=true` enables development/minor/major core updates, `false` disables them, and `'minor'` enables minor updates only. WordPress can also disable the entire background updater when file modifications are disallowed or `AUTOMATIC_UPDATER_DISABLED` is true.
