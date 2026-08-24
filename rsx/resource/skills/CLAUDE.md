# rsx/resource/skills - this application's own Claude Code skills

One directory per skill:

```
rsx/resource/skills/
  <name>/
    SKILL.md          required - a directory without one is not a skill and is ignored
    references/       optional supporting files, ONE level deep
```

These are YOUR application's skills. The framework's own skills are a separate
namespace - they arrive as the `rspade:*` plugin and are never authored here.

## Registration

The framework links each skill in as a RELATIVE symlink:

```
.claude/skills/<name> -> ../../rsx/resource/skills/<name>
```

**You never create that link.** `system/bin/environment_updates/070_app_skills.sh`
regenerates it on every environment-update run - a framework pull, `rsx:git pull`,
every development rebuild, container start - so **committing the skill is the whole
distribution mechanism**: a teammate gets it on their next pull with nothing to wire.
Committing the `.claude/skills/<name>` link itself is optional; it regenerates either
way.

**Registering one by hand** (a box whose last build predates the skill):

```bash
php artisan rsx:manifest:build      # a development build runs the environment updates
php artisan rsx:heal claude-skills  # or just the wiring, on its own
```

**Rules the script obeys.** A link it made that no longer resolves is pruned (the
skill was deleted or renamed). Anything it did not make - a foreign symlink, a real
directory sitting on a skill's name - is reported on stderr and left strictly alone;
that skill stays unlinked until you move the obstruction aside. **`rspade` is a
reserved name** (it is the framework plugin link) and a skill by that name is refused.

`php artisan rsx:health` carries a **"Claude Skills"** row: WARN per unlinked skill,
per dangling framework-made link, and per blocked or reserved name.

## SKILL.md shape

YAML frontmatter, then the body. **The `description` IS the trigger** - Claude matches
on it, so it is a gerund summary plus an explicit "Use when ..." naming concrete
symbols, commands and literal error strings, never a vague topic label. Keep the body
under 500 lines and keep `references/` one level deep.

```markdown
---
name: invoice-imports
description: Importing a client invoice batch in this application - the CSV column
  contract, Invoice_Import_Service::stage(), the duplicate-reference rule, and
  reconciling a partially applied batch. Use when adding or changing an import
  format, when a batch is stuck in STATUS_STAGED, or when hitting "invoice
  reference already imported".
---

# Invoice imports

...body: how-to depth, matrices, gotchas.
```

Read any framework skill under `system/app/RSpade/docs/skills/` for a worked example
of the house shape.

## Skill, or CLAUDE.md, or man page?

**The trigger-moment test decides.**

- **`rsx/resource/CLAUDE.md`** (always-on, loaded every session) when there is NO
  reliable triggering moment - the mistake happens incidentally inside some other
  topic, so no skill would fire first. That is: prohibitions, agent-conduct rules, the
  subsystem map (a summary plus the key names that ACT as trigger vocabulary, and a
  pointer to the skill), and cross-layer invariants. It loads on every task, so it
  stays terse.
- **A skill here** for anything with task vocabulary: how-to depth, decision matrices,
  rosters, gotcha catalogs, incident narratives. It loads only when its description
  matches what is being done, so it can afford to be long.
- **A man page in `rsx/resource/man/`** for a CONTRACT - the full, terse, expert-level
  spec, served by `php artisan rsx:man <topic>`. A skill that grows contract tables
  pushes them here and links to them.

Every instruction lives in exactly ONE of the three. If you are about to write the
same statement in two places, it belongs in the most general one and the other links
to it.
