<!-- bucket: framework — single-source, never duplicate. True ONLY in this monorepo. -->

## IDE TOOLING

**VS Code extension** (alpha): `/system/app/RSpade/resource/vscode_extension/` - LLMDIRECTIVE folding, RSX:USE protection, namespace updates.

**IDE helper endpoints**: `/_ide/service/*` (resolve_class, format, git, git/diff, refactor) back the extension. They run pre-Laravel-boot in `system/public/index.php`; the standalone handlers live in `Ide/Services/`.

**Auth = local-file grant** (no network token minting): the framework writes ONE unguessable `storage/rsx-ide-bridge/ide-grant-<hex>.token` (dev only, outside the docroot); the IDE reads it from local disk and sends `X-Ide-Token`, verified constant-time. Possession = proof of local read access; a strict loopback bypass remains as defense-in-depth only. Hard-off in production unless `RSX_IDE_SERVICES_ENABLED=true`, and `config('rsx.ide_integration.enabled')` is the dev-default master switch.

**No arbitrary exec**: the only command surface is `/refactor` — an EXACT-match allowlist of the three `rsx:refactor:*` commands with escaped args. There is no general exec endpoint, and trust does NOT rely on file permissions (unguessable name+content, not-web-served storage, plus the `rsx:health` "Web Exposure" probe that FAILs loud if `.env`/`.git`/the bridge dir is served).
