<!-- bucket: framework — single-source, never duplicate. True ONLY in the RSpade
monorepo. CANONICAL HOME of the publish/owned-zone AUTHORING rules; the
downstream operator contract lives in the app framework-updates fragment. -->

## DISTRIBUTION & PUBLISHING

**`bin/publish` packages for production — NOT for testing.** It writes each release's `system/.rspade-release.json` inventory (`{release_id, date, files:{path:sha256}}`) and vendors the published `system/` tree into `rspade_project` as ordinary files. **Byte fidelity is a release requirement**: the inventory hashes exact bytes and `rsx:framework:verify` compares a downstream checkout against them, so any line-ending normalization would false-flag files as tampered.

**We AUTHOR the consumer.** Downstream, `system/` is a vendored tracked tree written only by `rsx:framework:pull` — every rule that tree obeys is a rule written here.

**Owned zones** (hard-synced dirs AND individual owned files) are ONE concept declared in **`OWNED_DIRS`/`OWNED_FILES` in `framework-pull-upstream.sh.dist` AND `Framework_Mutations::OWNED_ZONE_DIRS`/`OWNED_ZONE_FILES` — which must change together**, since the non-owned list DERIVES its exclusions from them and a half-declared zone can never apply.

**The updater self-delivers; edit the `.dist`.** The downstream pull logic lives in `system/bin/framework-pull-upstream.sh`, generated from the AUTHORITATIVE `system/bin/framework-pull-upstream.sh.dist`. `bin/` is a hard-synced owned zone, so a downstream pull overwrites its own updater with the release copy — improvements land automatically, **on the pull AFTER the one that syncs them (one-pull lag)**.

**New durable "make the environment correct" behavior goes in `system/bin/environment_updates/`, NOT in the updater** — editing a running bash script is unsafe and `.dist` edits lag one pull. Each script is self-detecting, idempotent, silent when applied and non-fatal; `post-update.sh` is the single entry point, and in THIS repo its only trigger is a successful `rsx:manifest:build` in development mode. Contract: `system/bin/environment_updates/CLAUDE.md`.

**Do not re-order the pull's commit**: it commits `system/` BEFORE the rebuild, recording the pristine release as synced. **Framework-developer trees never run the downstream `system/` reset** (nor its integrity gate) — here `system/` is authored source.

Skill `rspade:publishing-a-release` (pre-flight gates, changelog assembly, the three repositories, strip/transform rules, inventory generation, failure triage). Details: `rsx:man rsx_upstream`, `rsx:man framework_pull_mechanics`.
