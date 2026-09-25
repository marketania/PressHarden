# PressHarden

<p align="center">
  <img src="docs/assets/press-tool-family.webp" alt="PressWarden blue security shield, PressHarden green policy shield, and PressGarden gold maintenance shield" width="700">
</p>

Fleet-scale **WordPress hardening and security policy management** from the shell.

Owns desired-state configuration, not malware scans, vulnerability feeds, cache management or database cleanup.

PressHarden is a standalone MIT-licensed Linux/Bash/PHP application. It contains its own discovery, targeting, lifecycle and relevant operation code. Neither sibling repository is required. It is not a wrapper around PressWarden.

## Other Press tools

PressHarden is part of the **Press Tool Family**. Other standalone tools are available for related WordPress administration tasks:

- **[PressWarden](https://github.com/marketania/PressWarden)** — WordPress security auditing, malware and vulnerability detection, compromise investigation, integrity checks, and incident response.
- **[PressGarden](https://github.com/marketania/PressGarden)** — WordPress maintenance, database health, cleanup, caching, LiteSpeed management, and performance operations.

Use PressHarden when you need to **configure, harden, and enforce a desired secure WordPress/PHP posture**. The sibling tools are independent applications, not required dependencies.

## Install this distribution

**Production runtime:** use an upstream-supported, security-patched PHP version. PHP 8.2–8.5 are supported at the September 2026 audit date; retained PHP 7.4 syntax tests are not a recommendation to deploy end-of-life PHP.

Read the [public-readiness audit and rollout checklist](docs/PUBLIC-READINESS.md) before fleet-wide use. Start on one staging site, verify recovery, and run as the site owner rather than root.

Requirements: Linux, Bash 4+, PHP CLI 7.4+, coreutils/find/tar; WP-CLI and its database clients for WordPress/database operations. Some operations need `flock`. Use only the installer published in this tool’s own repository. For a reviewed release, `curl -fsSLo install.sh https://raw.githubusercontent.com/marketania/PressHarden/main/install.sh` followed by `bash install.sh` installs this tool alone.

From this extracted tree:

```bash
PRESSHARDEN_INSTALL_SOURCE="$PWD" \
PRESSHARDEN_INSTALL_PREFIX="$HOME/tools/PressHarden" \
bash ./install.sh
```

Remote installer example above replaces only your downloaded installer file; do not run it from an existing source tree when reviewing that tree.

For a user-wide symlink, set `PRESSHARDEN_INSTALL_MODE=user` instead of a portable prefix. Existing installation destinations are refused; use their `update` command. The independent installer validates archive paths, identity and syntax; it never downloads the sibling tools.

## Commands

```bash
./pressharden status example.com
./pressharden lock example.com
./pressharden unlock example.com
./pressharden lock-status example.com
./pressharden wp-settings set editor disabled example.com
./pressharden wp-settings set debug-display disabled example.com
./pressharden auto-updates core disabled example.com
./pressharden auto-updates plugins disable example.com
./pressharden auto-updates themes disable example.com
./pressharden php status example.com
./pressharden php inspect example.com --details
./pressharden salts rotate auth example.com
./pressharden sites
./pressharden doctor example.com
./pressharden config
./pressharden help
./pressharden --version
```

Use `example.com`, `example.com/shop`, an absolute directory, or explicit `all`. A named parent includes intended discovered nested installations. Exclusions are preserved after narrowing. Omitted targets use the configured fleet; **unknown, empty, excluded or ambiguous targets do not fall back to all**. Always inspect `sites` before fleet mutations.

## State and configuration

Portable: `config/config`, `var/`, `var/cache/`, `var/reports/` beneath this product's installation. User-wide: `$XDG_CONFIG_HOME/pressharden/config`, `$XDG_STATE_HOME/pressharden/`, `$XDG_CACHE_HOME/pressharden/` with standard home-directory fallbacks. Override with `PRESSHARDEN_CONFIG_FILE`, `PRESSHARDEN_STATE_DIR`, `PRESSHARDEN_CACHE_DIR`, `PRESSHARDEN_REPORTS_DIR`. State/backups must be outside selected WordPress roots. No `PRESSWARDEN_*` compatibility variables are consumed by the new product.

Configuration is trusted shell input. Keep it administrator-owned and mode 600. Discovery and interaction options are in `config/config.example`; only product-relevant settings are included. Backups are never automatically pruned.

## Configuration policies

`status` and `wp-settings` collect the existing allowlisted effective WordPress policy dashboard; the fleet baseline is descriptive, not a universal secure recommendation. `lock`/`unlock` set `DISALLOW_FILE_MODS`; they do not change the separately configured dashboard editor policy. `file-mods status|on|off` is the explicit advanced equivalent.

`wp-settings set` accepts `editor`, `cron`, `recovery`, `debug`, `debug-display`, `debug-log`, `script-debug`, `savequeries`, `force-ssl-admin`, and `alternate-cron` with `enabled|disabled`; `environment` with `production|staging|development|local`; `development` with `disabled|core|plugin|theme|all`.

Both the original PHP configuration transaction engine and the shell fallback are retained. Private staging, value and PHP syntax validation, source identity checks, conditional atomic publication, readback and safe rollback protect `wp-config.php`. No live `sed` editing is used. Backups/metadata are in `state/config-transactions/`. If `proc_open` is unavailable, the shell engine requires `flock`. Read metadata before recovery; do not blindly overwrite a concurrently edited config.

Automatic-update policy manages preferences rather than immediately updating WordPress. Core values are `minor|major|disabled`; plugin/theme values are `enable|disable`. Global blockers such as `DISALLOW_FILE_MODS` are reported separately. Plugin/theme preference backups are in `state/backups/auto-updates/`.

`salts rotate auth` refreshes the complete eight-key authentication group in a staged config and invalidates current login cookies/nonces. `salts rotate cache` operates only when a regular object-cache drop-in references `WP_CACHE_KEY_SALT`. Every generated key is checked for sufficient length, uniqueness and change; only digests are used in verification output. Original secret-bearing config backups remain private.

## PHP configuration: configured is not effective

`php status` distinguishes CLI values, root `.user.ini` values, contextual recommendations, and **UNKNOWN web-effective values**. `php inspect` preserves the existing detailed PHP/optional hosting-provider comparison. Neither automatically applies recommendations.

`php set DIRECTIVE VALUE [target]` only writes a bounded, unambiguous, supported root `.user.ini`. First verify the actual web SAPI and export `PRESSHARDEN_PHP_WEB_SAPI=fpm-fcgi` or `cgi-fcgi`. Nondefault/disabled user-ini filenames and INI_SYSTEM-only directives are refused, not simulated. The operator attestation is not a measurement.

Example, only after confirming the hosting runtime:

```bash
PRESSHARDEN_PHP_WEB_SAPI=fpm-fcgi ./pressharden php set display_errors Off example.com
```

Supported directive families include error display/logging, upload/resource limits and native PHP session cookie settings. `expose_php`, `allow_url_fopen`, `allow_url_include` and other system-only settings are inspectable but may require the host to change them. There is no arbitrary `php.ini`/`.htaccess` editor or blanket dangerous-function disabling.

An on-disk change exits **1 pending web verification**, not 0 claiming effectiveness. Hosting `php_admin_value`, nested overrides, caching and web PHP version differences still apply. No publicly accessible phpinfo/probe file is deployed. PHP policy recovery copies and metadata are in `state/backups/php-policy/`.

## Safety and automation

Mutations confirm on a terminal by default. `PRESSHARDEN_INTERACTIVE=0` is an explicit authorization for unattended operations—not a recommendation to apply changes fleet-wide. Backups, scope checks and verification are not bypassed. A failed site does not become a successful fleet; partial failures are reported.

WP-CLI operations run as the current account. Plugin skip flags do not suppress MU-plugins; loading WordPress is not a sandbox. Do not run maintenance/policy changes as incident remediation. Preserve evidence and investigate a suspected compromise first. Stop other administrative processes before updates or mutations; private state/operation locks are product-specific, not a cross-product distributed lock.

## Exit codes

`0`: requested operation completed under its documented semantics (or no eligible change). `1`: declined operation, review/partial work, or PHP configuration awaiting web verification as applicable. `2+`: invalid request, incomplete discovery/verification, dependency or execution failure. Provider COMMAND COMPLETED means command success, not independently measured service effectiveness. Status findings and unsupported capabilities are labeled explicitly.

## Update and uninstall

`./pressharden update` targets `marketania/PressHarden`, never a sibling and never threat feeds. For isolated validation or recovery, test/update with a local validated distribution archive:

```bash
PRESSHARDEN_UPDATE_ARCHIVE=/absolute/path/PressHarden.tar.gz ./pressharden update
```

The updater preserves private configuration and state; a sibling archive fails identity validation. See [update and recovery details](docs/UPDATING.md). `./uninstall.sh` confirms removal of managed program files only. It preserves saved config, backups and state. `--yes` is the explicit noninteractive alternative; it does not purge private data.

## Development and provenance

For AI-assisted repository work, see [AI-assisted development](docs/AI-DEVELOPMENT.md). The development configuration defaults trusted Codex projects to GPT-6 Astra; the CLI tool itself has no OpenAI runtime dependency.

Run `bash tests/run.sh`. Tests use bounded temporary fixtures and mock external commands. Live fixture integration, when provided, must use an explicitly isolated test database; no production websites are used.

Separated from PressWarden 1.1.24 at `63adec182b4d3a20dbd5e24daa9b9fa0e98510b8`. See [provenance](PROVENANCE.md), [migration mapping](docs/MIGRATION.md), and [MIT license](LICENSE). PressWarden remains the security investigation tool; PressGarden handles routine maintenance and performance. Siblings are optional alternatives for other responsibilities, not dependencies.
