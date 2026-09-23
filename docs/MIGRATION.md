# Migration from PressWarden 1.1.24

Upstream source: `63adec182b4d3a20dbd5e24daa9b9fa0e98510b8`. Every source file was assigned an owner before extraction; the delivery's audit contains the full file/dependency inventory.

Move `lock`, `unlock`, `lock-status`, `file-mods`, `wp-settings`, and `auto-updates` to `pressharden` with the same meaningful operands. The old embedded configuration-debug changes are now explicit wp-settings setters. Old salt-rotation prompts become `pressharden salts rotate auth|cache`. Runtime/provider inspection is `pressharden php inspect`; scoped .user.ini operations are `pressharden php status|set`.

Rename relevant configuration to `PRESSHARDEN_*`; do not copy the entire old security configuration. Existing source data is never automatically moved. Secret-bearing backups and saved policy preferences remain in their original state location until an operator deliberately migrates them. The new tool never reads another application's state implicitly.

No deprecated PressWarden command transparently runs a sibling. The prepared security-only version prints migration guidance for moved commands and returns an error code. Publish and verify independent sibling distributions before merging that removal.

Deliberate tightening: explicit empty/failed targets are refused, no-terminal mutations need intentional automation, staged PHP syntax is checked, old automatic maintenance flags are ignored, risky database writes require SQL backups, and an on-disk PHP setting is not called web-effective. Some original low-confidence cleanup opportunities (live logs, VCS metadata or unknown files) are intentionally not deleted.
