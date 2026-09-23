# WordPress policy dashboard

`wp-settings` gives one compact view of non-secret WordPress policy and selected effective wp-config behavior.

```bash
./pressharden wp-settings example.com
./pressharden wp-settings all
```

A single website shows the full grouped policy. A fleet target shows three compact baseline groups (Security, Updates, Runtime) and only websites that differ from the unique most-common value. A tied fleet value is reported as `MIXED`; PressHarden does not choose an arbitrary winner. `pressharden status` uses this same read-only policy dashboard. Security suites remain in PressWarden; PressHarden does not provide Fast or Full scan suites.

The fleet baseline is a statistical comparison aid, not a security standard or proof that the common value is correct. Policy differences are informational. Use PressWarden separately to investigate security conditions such as debug exposure or suspicious configuration; this policy comparison is not a malware scan.

## What is shown

The dashboard allowlist includes:

- file modifications and dashboard editor
- core/plugin/theme automatic-update policy and global updater blockers
- WP-Cron / alternate cron
- Recovery Mode / fatal-error handler
- environment type and development mode
- debug, debug log/display, query logging and script debug
- FORCE_SSL_ADMIN and WP_CACHE
- revision policy, trash retention, autosave interval and WordPress memory limits
- DB charset/collation posture
- WP_HOME / WP_SITEURL / cookie-domain override presence
- filesystem method
- WP_ALLOW_REPAIR, ALLOW_UNFILTERED_UPLOADS, DISALLOW_UNFILTERED_HTML and WP_HTTP_BLOCK_EXTERNAL

Core/plugin/theme automatic-update entries describe WordPress's configured preferences and recognized global blockers. Plugins, themes, MU plugins, or custom filters can still alter WordPress's final update decision, so the dashboard does not claim that a configured preference guarantees a future update will or will not run.

The collector emits only normalized allowlisted values. It intentionally does **not** emit DB credentials, salts/keys, API tokens, FTP credentials, proxy credentials, arbitrary constants, source code, plugin output, or wp-config bodies.

The policy collector runs once per selected WordPress installation through WP-CLI with plugins/themes/packages skipped. WordPress still boots, and MU plugins or other early bootstrap code are not sandboxed. Malformed or contaminated output makes the check `INCOMPLETE` rather than clean.

## Changing supported settings

Changes use the same website-name / directory / `all` target resolver as `lock`, `unlock`, and `auto-updates`. They require explicit confirmation in an interactive session. PressHarden backs up each `wp-config.php`, changes a private staged copy through WP-CLI, verifies it, and publishes only while the original source still matches. If final verification fails, rollback is attempted only when it can preserve concurrent external changes; otherwise recovery data is retained. See [configuration transactions](CONFIG-TRANSACTIONS.md).

```bash
./pressharden wp-settings set editor disabled example.com
./pressharden wp-settings set editor enabled example.com

./pressharden wp-settings set cron disabled example.com
./pressharden wp-settings set cron enabled example.com

./pressharden wp-settings set recovery enabled example.com
./pressharden wp-settings set recovery disabled example.com

./pressharden wp-settings set environment production example.com
./pressharden wp-settings set environment staging example.com
./pressharden wp-settings set environment development example.com
./pressharden wp-settings set environment local example.com

./pressharden wp-settings set development disabled example.com
./pressharden wp-settings set development core example.com
./pressharden wp-settings set development plugin example.com
./pressharden wp-settings set development theme example.com
./pressharden wp-settings set development all example.com

./pressharden wp-settings set debug disabled example.com
./pressharden wp-settings set debug enabled example.com

./pressharden wp-settings set force-ssl-admin enabled example.com
./pressharden wp-settings set force-ssl-admin disabled example.com

./pressharden wp-settings set alternate-cron disabled example.com
./pressharden wp-settings set alternate-cron enabled example.com
```

Disabling WP-Cron does not create a server cron job; confirm an external scheduler invokes `wp-cron.php` as intended. Alternate WP-Cron is a compatibility workaround rather than a default hardening recommendation. The normal cron and alternate-cron controls deliberately change only their own constants: enabling normal WP-Cron does not silently remove `ALTERNATE_WP_CRON`, and enabling alternate cron does not silently remove `DISABLE_WP_CRON`. The status dashboard shows the resulting effective cron posture.

Enabling the dashboard editor does not override `DISALLOW_FILE_MODS`; a locked site remains effectively unable to use the editor.

`WP_ENVIRONMENT_TYPE=development` or a non-empty `WP_DEVELOPMENT_MODE` can make WordPress enable `WP_DEBUG` when `WP_DEBUG` is not explicitly defined. The dashboard reports the resulting effective debug posture.

## Read-only inventory fields

Cache, revisions, memory limits, DB charset/collation, URL/cookie overrides, filesystem method, repair/upload flags and external-HTTP blocking are intentionally inventory-only in this release. These values can be host-, plugin-, or application-specific, so PressHarden does not offer generic fleet-wide mutation for them.

Core/plugin/theme automatic updates keep their existing dedicated commands:

```bash
./pressharden auto-updates status example.com
./pressharden auto-updates core minor example.com
./pressharden auto-updates plugins enable example.com
./pressharden auto-updates themes disable example.com
```
