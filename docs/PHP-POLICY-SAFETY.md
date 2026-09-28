# PHP policy semantics, preview and recovery

This checkpoint concerns local `.user.ini` policy only. It does not change
WordPress update preferences, authentication salts, the wp-config transaction
engines, server configuration or other Press tools.

## Safe first use

```bash
./pressharden help
./pressharden sites /absolute/path/to/staging-wordpress
./pressharden php status /absolute/path/to/staging-wordpress
```

The local status and `.user.ini` writer do not invoke WP-CLI or bootstrap
WordPress. Other WordPress policy commands still require WP-CLI. Status continues
to label web-effective values UNKNOWN. Toolkit-owned discovery/private state may
be created; read-only status never writes `.user.ini`.

## Correct literal values

PHP interprets an unquoted INI `None` as empty, even though RAW parsing preserves
the spelling. SameSite string values are now emitted quoted. The regression uses
a freshly generated, inert INI file with the actual PHP CLI configuration loader
to confirm that `None` survives parsing. That is an INI-language test, not an FPM,
browser or end-to-end session test. An existing unquoted `None` is rewritten
instead of incorrectly being considered unchanged.

Keep RAW parsing for the site's existing file: do not expand constants or
sensitive environment values. Normal parsing is used only on the newly generated
allowlisted literal. Arrays, duplicate target directives and environment-driven
or unsupported current target expressions require manual review instead of a
fragile replacement. An explicitly empty `PRESSHARDEN_PHP_USER_INI_FILENAME`
means disabled, not the default filename. Nondefault names are also refused.
The operator's SAPI/filename attestation is not a measured web-runtime value.

## Preview and safe publication

For a change, the helper shows the canonical configuration path, current literal,
proposed value and contextual recommendation before requesting approval for that
site. Default approval is no. `PRESSHARDEN_INTERACTIVE=0` remains deliberate
unattended authorization; it does not bypass scope, private backups or readback.
A changed source after approval invalidates the plan before staging begins.

Reads are bounded to 1 MiB. Descriptor and path identity are compared, along with
size, timestamps, ownership, permissions and link count. Recovery copies are
exclusive private files with verified contents. Metadata is checked and atomically
published before replacing the live file. Publication retains existing permissions
and ownership, checks resulting content and records the resulting file identity.
The existing PHP-policy lock now also verifies mode/owner and descriptor identity.

A configured or unchanged policy still returns **1 awaiting web verification**,
not 0 claiming enforcement. Declined or terminal-less changes also return 1,
with no configuration mutation; the fleet summary labels those as review.
`PREPARED` metadata is not proof publication occurred;
`REFUSED_BACKUP_RETAINED` or `LIVE_UNVERIFIED_BACKUP_RETAINED` requires inspection.
The original bytes, existence marker, metadata and hashes are retained for manual
recovery. Do not overwrite a newer file with a backup. This workflow does not
promise automatic rollback, distributed locks or power-loss-proof storage.

## Evidence and limits

Baseline main `aa7817961f5cfc8b06d8beef8d7051040f059616` passed 24 local scripts.
Additional tests exposed incorrect SameSite serialization/no-op, disabled filename
fallback, replacement of target environment expressions, array warning output and
an unnecessary WP-CLI dependency for static status. New tests also cover recovery
metadata, backup collisions, repeated application, real competing locks and a
PTY approval after an external edit. Original credits, links, license, provenance
and artwork have exact preservation checks.

Use `bash tests/run.sh` and exact-head PR CI for results. No client files, live
WordPress bootstrap, web probe or credentials were used in these local tests.
Host overrides, nested `.user.ini`, FPM caching, PHP version differences, HTTPS
and session compatibility still need deployment-specific validation. The broader
refinement brief is not fully certified by this focused repair.

## Primary references and decisions

- [PHP INI parsing](https://www.php.net/manual/en/function.parse-ini-string.php): reserved values and RAW parsing for untrusted input; avoid constant/environment expansion.
- [Per-directory INI](https://www.php.net/manual/en/configuration.file.per-user.php): CGI/FastCGI scope, filename and caching restrictions; do not claim CLI equals web.
- [PHP file metadata](https://www.php.net/manual/en/function.clearstatcache.php): refresh metadata before source-identity comparisons.
