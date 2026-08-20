<!-- bucket: framework — single-source, never duplicate. True ONLY in the RSpade monorepo. -->

## DEVELOPMENT CONTEXT (MONOREPO)

Working with **Brian**, creator and sole maintainer of RSpade. This is **framework and starter template development**. The user has 20 years of professional experience and is extremely opinionated about implementation.

**Current focus**: framework core stable; building the reference SaaS application (`/rsx/`) as the starter template.

**Your role**:
- **Framework** (`/system/app/RSpade/`): core systems, manifest, bundles, auth, developer tooling.
- **Reference app** (`/rsx/`): working SaaS with auth, user management, multi-tenant, professional UI.

### CRITICAL DIRECTIVE: NO BACKWARDS COMPATIBILITY

Zero external users. No releases. We control 100% of the codebase. Breaking changes are required for progress.

**BACKWARDS COMPATIBILITY = TECHNICAL DEBT WITH LIPSTICK**

**DUAL IMPLEMENTATIONS CAUSE DEVELOPERS PTSD** - two systems doing the same thing creates debugging nightmares.

**Rules:**
- Delete old implementations immediately after updating call sites
- ONE pattern per feature when complete
- Never create fallback/compatibility layers
- Remove deprecated code before marking a feature complete
- Never document "legacy", "compatibility", or how things "used to work"

### Safety checks here

The safety-check override prohibition (shared conduct fragment) applies to this tree's own gates - `IS_FRAMEWORK_DEVELOPER=true` is the canonical example. Never modify it, never pass a flag around it: STOP, INFORM, ASK, WAIT.

### Monorepo naming rows

| Context | Convention | Examples |
|---------|------------|----------|
| Framework classes | `PascalCase` or `Like_This_*` | `ManifestKernel`, `Rsx_Controller_Abstract` |
| Framework internal | `_underscore_prefix()` | `_internal_method()` |
| Temp files | `name-temp.extension` | `test-temp.php` |

### Box facts

**NEVER restart PHP/Nginx** - OPcache is disabled on this box.

### Framework-only references

- `rsx:man code_quality` (rule-catalog architecture), `rsx:man manifest_api`, `rsx:man manifest_build`, `rsx:man ast_sourcecode_parsers`, `rsx:man vs_code_extension` exist HERE only - publish strips them from releases; never point a shared fragment or skill at them.

### Domain notes

- **Realtime is ENABLED in this dev env**: the relay runs under supervisor (`[program:realtime]`) and nginx proxies `/ws`. The template app ships **no `#[Emitter]` consumer** (verified 2026-08-17) - emitters are exercised only by framework tests.
- **Monorepo cron line carries the path**: `* * * * * cd /var/www/html && php artisan rsx:task:process` (the shipped/downstream form is pathless).
- **Backlog pointers**: **B-85** (a task should DECLARE whether it wants the site lock at all) and **B-87** (automatic per-tenant write locks on `save()` are DISABLED since 2026-08-11 - re-enable decision pending; the `Rsx_Artisan` mandate stands regardless).
- A framework test whose SUBJECT is the artisan entrypoint keeps its raw spawn under a rationale'd `@ARTISAN-SPAWN-01-EXCEPTION`.
- Colocated deep docs: `Core/Task/CLAUDE.md`, `Core/Realtime/CLAUDE.md`.

### Documentation obligations

- **Document divergences** from Laravel in `man/framework_divergences.txt` (`rsx:man framework_divergences`) — its ADDING AN ENTRY section is the contract.
- **Delegation research artifacts** are persisted into `/docs.dev/` before implementation begins.
