<!-- single-source: never duplicate into another fragment. This fragment IS the
filing rule for all framework knowledge. Never loaded downstream. -->

## KNOWLEDGE ROUTING (how framework knowledge is recorded)

**Every instruction lives ONCE**, in `system/app/RSpade/docs/`. When asked to "record this to CLAUDE.md" (or you learn something worth keeping), file it by these two rules — never append to the root `CLAUDE.md` (an import shim) and never write the same statement into two files (if you are about to, it belongs in `shared/`).

**Rule 1 — BUCKET, by where the statement is TRUE** (not who reads it):
- `claude/shared/` — true in this monorepo AND in a downstream app (most API surface; the large bucket).
- `claude/framework/` — true only here (publish, internals, monorepo box facts, framework tests, authoring obligations).
- `claude/app/` — true only downstream (`rsx:framework:pull`, `rsx:git` behavior, `system/`-read-only). This monorepo never loads it; read it when authoring downstream-facing contracts.

**Rule 2 — TIER, by the trigger-moment test**:
- **Always-on fragment** (`claude/<bucket>/NN-*.md`) ONLY when there is NO reliable triggering moment — the mistake happens incidentally inside some other topic, so no skill would fire first. Four categories: prohibitions without trigger moments; agent-conduct rules; the subsystem map (a summary + key API names as trigger vocabulary + the skill pointer); cross-layer invariants. Fragment style: comment header, `## HEADING`, dense house idiom, no emoji, no YAML.
- **Skill** (`skills/{shared,framework,app}/<name>/SKILL.md`) for everything with task vocabulary: how-to depth, matrices, rosters, gotcha catalogs, incident narratives (they live NEXT TO the mandate they justify). Frontmatter description = gerund summary + "Use when …" naming concrete symbols and literal error strings — the description IS the trigger; body <500 lines; references one level deep. A demotion from a fragment is a MOVE: verify-or-append in the destination before removing.
- **Man page** (`../man/*.txt`) = the contract/spec, the source of truth. A skill that grows contract tables pushes them here. Never update a man page autonomously.

**Assembly & delivery**: `claude/framework.md` (this view) and `claude/app.md` (downstream view) are import indexes over `shared/` + their bucket; the root `CLAUDE.md` here imports `framework.md`, and downstream `rsx/resource/CLAUDE.md` imports `app.md` (wired by environment update). Skills load via the `rspade` plugin at `.claude/skills/rspade -> system/app/RSpade/docs/` — the manifest lists `skills/shared/` + `skills/framework/` here; publish rewrites that second entry to `skills/app/` in releases (a one-token manifest flip — Claude Code does not follow a skills-DIR symlink, only skill-entry symlinks). Fragments load every session; skills load on trigger — the assembled always-on view targets ≤80KB, so budget accordingly.

**Six publish-stripped man pages** (`code_quality`, `manifest_api`, `manifest_build`, `ast_sourcecode_parsers`, `vs_code_extension` — and formerly `storage_directories`, now shipped): never point `shared/` or `app/` content at a stripped page.

**Shipping a contract change routes through this tree**: mandate item 2 is "write it ONCE in `claude/shared/`" — see the documentation-obligations fragment and skill `rspade:shipping-a-contract-change`. Epic record: `docs.dev/knowledge_restructure/`.
