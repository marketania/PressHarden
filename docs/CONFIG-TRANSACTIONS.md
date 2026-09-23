# Transactional wp-config mutations

PressHarden adapts the original PressWarden transaction layer for commands that change supported `wp-config.php` constants.

Covered commands include:

- `lock` / `unlock`
- `file-mods on|off`
- `wp-settings set ...`
- `auto-updates core minor|major|disabled`

Plugin and theme automatic-update preferences are WordPress option state rather than `wp-config.php` constants. They keep their existing preference snapshot/restore path and are not part of this transaction layer.

## Why this exists

A live `wp-config.php` should not be edited first and treated as safe merely because a later rollback might succeed. PressHarden instead prepares and verifies the requested change away from the live file, then publishes only verified bytes if the original live source is still exactly the snapshot it approved.

## Transaction sequence

For each selected WordPress installation PressHarden:

1. Acquires a private per-site mutation lock using PHP `flock()` or the shell fallback's `flock` command. A competing PressHarden mutation of the same site's config is refused rather than interleaved.
2. Requires `wp-config.php` to be a readable regular, non-symlink, single-link file within the selected WordPress installation and within the transaction size limit.
3. Reads a stable snapshot and records its SHA-256, size, mode and filesystem identity. Where PHP can determine the effective UID, an owner mismatch is refused because atomic replacement could otherwise change ownership semantics.
4. Checks whether the requested constant already has the exact requested value. An exact no-op returns successfully without creating a new backup transaction.
5. Creates a unique private transaction directory under the PressHarden state directory and stores an exact verified backup as `wp-config.php`.
6. Creates a private staged copy and runs WP-CLI `config set` against that copy using `--config-file`. The live site's config is not the WP-CLI mutation target.
7. Reads the requested value back from the staged copy using WP-CLI `config get --config-file` and refuses publication if the value is not exact.
8. Re-reads the live config and requires its identity and bytes to still match the original snapshot. If another process changed it during staging, PressHarden preserves that external change and refuses publication.
9. Writes the verified staged bytes to a new temporary file in the live config directory, requires that the replacement inode can preserve the original mode/owner/group, and atomically renames it over `wp-config.php` only after one final source revalidation.
10. Verifies the published bytes, mode, owner/group and requested WordPress constant.
11. If final live verification fails, automatic rollback is attempted only while the live file is still exactly the bytes PressHarden just published. Otherwise the verified backup is retained and PressHarden refuses to overwrite a potentially newer external change.

## Evidence and privacy

Transactions are stored under:

```text
var/config-transactions/
  locks/
  tx-YYYYMMDDTHHMMSSZ-random/
    wp-config.php
    staged-wp-config.php
    meta.json
```

New transaction directories are private (`0700`); backup, staged config, metadata and lock files are owner-only (`0600`, subject to a stricter hosting policy).

The backup is an exact copy of `wp-config.php` and therefore can contain database credentials, salts and other secrets. Keep the PressHarden state directory private. `meta.json` contains only bounded operational metadata and hashes; it does not copy config contents or secret values beyond the allowlisted requested setting/value itself.

PressHarden does not automatically purge old transaction evidence.

## Allowlisted settings

The transaction helper refuses arbitrary constants. Current callers can mutate only the settings PressHarden explicitly supports, including file modification/editor controls, WP-Cron/recovery/debug/SSL policy, environment/development mode and core automatic-update policy.

## Concurrency and external writers

The per-site lock serializes PressHarden config writers. WordPress, a hosting control panel, deployment tooling or another shell process does not honor that lock. Exact source revalidation immediately before atomic publication prevents PressHarden from knowingly overwriting a config that changed while its staged copy was being prepared, but no userspace tool can make unrelated external writers globally transactional.

## Failure semantics

A failed transaction returns INCOMPLETE/nonzero for that site. Fleet commands may retain successful changes on earlier or later independent sites while returning exit code `2` for the overall command if any site failed.

A staged mutation failure leaves the live config untouched. A source-change race leaves the external live change untouched. A post-publication verification failure rolls back only when doing so is provably safe; otherwise the verified backup and transaction metadata are retained for manual recovery.

## Durability boundary

Atomic same-directory rename protects readers from seeing a half-written config under ordinary filesystem operation. PressHarden does not claim `fsync()`/power-loss durability across hosting filesystems. A host crash or storage failure at exactly the wrong moment remains outside this userspace guarantee.
