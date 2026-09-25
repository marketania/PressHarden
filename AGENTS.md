# AGENTS.md

## Product mission

Desired-state WordPress and PHP hardening, wp-config policy, lock state, update preferences, guarded configuration changes, and policy reporting.

## Engineering rules

- Read only the documentation relevant to the task. Use `docs/AI-DEVELOPMENT.md` for model/effort guidance and the repository architecture/migration docs when a change touches those boundaries.
- Preserve staged configuration transactions, private backups, target isolation, readback, and conditional rollback. Never replace transaction logic with direct live-file editing. Distinguish configured values from effective web-runtime values.
- Treat repository edits, pull requests, releases, and production website operations as distinct actions. Report only actions actually confirmed by tools.
- Keep this repository independently installable and runnable. Do not add a runtime dependency on either sibling tool.
- Keep secrets, client data, SQL dumps, evidence, and private state out of prompts, commits, issues, and test fixtures.
- Prefer small, reviewable changes. Preserve historical examples and changelogs unless the task explicitly updates history.

## Validation

Use `bash tests/run.sh` for local benign-fixture coverage. Configuration mutations require focused transaction, rollback, target-isolation, and real isolated WordPress integration checks before merge.

For reversible documentation-only changes, use focused validation rather than unrelated exhaustive testing unless repository policy or CI requires more.
