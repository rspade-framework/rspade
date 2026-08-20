---
name: shipping-a-contract-change
description: Finishing a framework change that alters how downstream code is written or invoked - working the six-artifact mandate (always-on knowledge, shared fragment, template app, man page, upstream_changes, prelaunch checklist), deciding which audit artifact a new obligation belongs in, authoring an upstream_changes document against the manual-action gate, writing a prelaunch checklist entry, and the external-requests INDEX/archive workflow. Use whenever a framework change touches a downstream-facing contract, syntax, default, or behavior.
---

# Shipping a downstream-facing contract change

**A change that is only implemented is a change nobody downstream can use correctly.** The mandate: any framework change that alters the CONTRACT or SYNTAX of how downstream code is written or invoked **is not done until all of these are done** - not one of them, all that apply.

Work the list in order; each step tells you where the knowledge belongs and how terse it must be.

---

## 1. Always-on knowledge (monorepo view)

`system/app/RSpade/docs/claude/framework/*.md` if the fact is true ONLY here (publishing, box facts, monorepo git rules). **Terse.** This is how to USE/WRITE the feature today - never a migration guide.

## 2. The shared fragment — write it ONCE

If the fact is true in both environments, it goes in `system/app/RSpade/docs/claude/shared/*.md` and **both** views assemble it. **There is no second copy to keep in sync** - the old "also update `docs.dist/CLAUDE.dist.md`" step is gone, and re-introducing a parallel downstream copy of the same paragraph is the failure mode this structure exists to end.

Rules of thumb:
- Downstream-only truth (`system/` is read-only, `rsx:git`, the pull) → `docs/claude/app/`.
- One canonical home per topic. If your paragraph belongs next to an existing one, EDIT that fragment; do not add a second.
- The fragment header comment names its bucket and its single-source rule - keep it accurate.
- Deep how-to does not belong in a fragment. It belongs in a skill (`docs/skills/{shared,app,framework}/<name>/SKILL.md`) or a man page.

## 3. The template app (`/rsx/`)

**Make it demonstrate the new way.** The template is the worked example downstream reads; a template still doing it the old way teaches the old way. Grep `/rsx/` for the old spelling and convert every instance, including the reference forms and the CRUD module the docs point at.

## 4. The man page(s)

`system/app/RSpade/man/*.txt` - **the full treatment lives here**, not in always-on knowledge. Terse, complete, expert-level. Note the framework-developer-only pages are stripped at publish (`code_quality`, `manifest_api`, `manifest_build`, `ast_sourcecode_parsers`, `storage_directories`, `vs_code_extension`) - never point shipped knowledge at one.

## 5. `upstream_changes/*.txt`

The migration: how a downstream developer FINDS and CONVERTS existing code. See the gate below before writing one.

## 6. `man/prelaunch_checklist.txt`

**ONLY if the change does not self-correct** through a thrown exception or failed build, and so needs a human eye to confirm it was applied correctly.

**When #6 applies:** a signature change that fatals, a manifest-build validation, a lint rule - all self-correct; no entry. A silent behavior change (an ordering that quietly stopped being applied, a new declaration nobody is forced to add, a default that changed) does NOT self-correct - entry required.

**Keep the audiences separate.** Always-on knowledge describes how to write code with the feature TODAY. Audit sweeps, "find your existing call sites", grep recipes and conversion steps belong in `upstream_changes/`; recurring pre-launch verification belongs in the prelaunch checklist. Mixing migration prose into always-on knowledge bloats what every reader loads on every task.

---

## Which audit artifact? A decision tree

```
Is the obligation RECURRING (must be re-checked by every app, forever)?
├─ yes, by DOWNSTREAM apps        -> an ENTRY in man/prelaunch_checklist.txt
│                                    (distributes via framework pull; NO upstream_changes doc)
├─ yes, by RSpade CORE on itself  -> a row in docs.dev/audits/framework_internal_audit_checklist.md
│                                    (a living discipline, not a gate)
└─ no - it is ONE-TIME, triggered by this change
    └─ does the downstream developer have to DO something by hand?
        ├─ yes -> an upstream_changes document
        └─ no  -> NOTHING. Do not write a document.
```

A downstream app also keeps its OWN pre-launch items in `rsx/resource/audits/prelaunch_checklist.md`; framework-required audits never go there.

---

## Authoring an upstream_changes document

**THE GATE (owner ruling 2026-08-04): manual action required, or no document.**

A document exists ONLY when the end user must DO something to their environment or their own code BY HAND. **It is NOT a changelog** - "what changed upstream" is already carried by the framework-update commit body / `git log -- system/` / `framework_update_history.dat`. Self-applying behavior needs no document, however large the change.

Before authoring, ask: *"with this release applied, is there anything left that only the downstream developer can do?"* If the honest answer is no - FYIs, precautionary backups and "be aware that..." notes do not count - **do not create the document**.

Worked examples:

| Change | Document? | Why |
|---|---|---|
| A new framework migration ships | **No** | `migrate` applies it. Nothing left for the developer. |
| A new environment update script | **No** | `post-update.sh` self-applies it, silently. |
| An owned-zone file changed | **No** | Hard-synced by the pull. |
| A method signature changed (fatals on the old call) | **Usually no** for the fragment/man tier alone — **yes** if call sites must be FOUND and converted across an app that will otherwise fatal in production paths nobody exercises locally |
| A DEFAULT changed silently (old code keeps running, differently) | **Yes** | Nothing fails; only an audit finds it. Also a prelaunch-checklist candidate. |
| A `/rsx/` template file improved | **Yes** (Category 1) | Their `/rsx/` is their own fork - they get nothing automatically. |
| An exposed dependency retired or majored | **Yes** (Category 2) | Must include the adopt/re-record remedy. |

**Format, naming and the two category structures are defined by the charter** - `system/app/RSpade/upstream_changes/CLAUDE.md`. Read it before writing; do not invent a shape. In short: `{feature}_{MM}_{DD}.txt`, Category 1 = `/rsx/` template diff with exact copy-paste code, Category 2 = `/system/` contract change whose body is **ACTION REQUIRED** (what downstream must audit in their OWN code), never a description of the core fix. **Show the code** - a document a developer has to diff against is a document that failed.

Downstream consumption (what your document will be read through): `rsx:framework:upstream_changes` / `:show <name>` / `:mark <name> --fulfilled`. Full detail: `php artisan rsx:man upstream_changes`.

---

## Authoring a prelaunch checklist entry

`man/prelaunch_checklist.txt` is a **sanctioned enforcement channel**: when a lint rule is impractical, "add this to the prelaunch checklist" is a good answer - and the RSpade LLM may proactively suggest it to the owner. It is ADVISORY (nothing blocks a build) and man pages are never updated autonomously, so **propose the entry and get the owner's go-ahead**.

An entry follows the house shape of the existing ones:

```
ENTRY N: SHORT TITLE (what it is about)

    What to audit
        The exact property to verify, and a pointer to the man page that holds
        the patterns. Do NOT restate the patterns here - direct the reader.

    Why it matters
        The failure this catches, concretely.

    Guidelines
        How to perform the audit: what to enumerate, what to check per item.

    Dos and don'ts
        The specific right and wrong spellings.
```

Entry 1 (login-redirect wiring) is the model: enumeration-driven, pointing at `rsx:man login_redirect` for the patterns rather than duplicating them.

---

## External requests: INDEX and archive

`docs.dev/external_requests/` holds pre-authorized requests from external environments Brian controls. The directory is tracked by a self-maintained **`INDEX.md`**.

Workflow, every time you touch one:

1. **Handle the request** (implement, partially implement, fold into another effort, supersede, or decline).
2. **Record it in `INDEX.md`** - one row: filename, disposition, the commit/artifact that fulfilled it, and an **absolute-date** timestamp. Update the row whenever the status changes.
3. **If it is FULLY processed** (`IMPLEMENTED` / `FOLDED` / `SUPERSEDED` / `DECLINED`), **MOVE the file into `docs.dev/external_requests/archive/`** and make its INDEX row carry the `archive/` path.

So `archive/` is the done pile; a file still at the top level is OPEN (`PARTIAL` / `NOT DONE` / awaiting review) and stays there until finished.

---

## Final check

Before calling the change shipped, re-read the six items and name, out loud, which ones applied and which did not and why. **"I updated the code and the man page" is not shipping** if the template still teaches the old way or a silent behavior change went out with no checklist entry.

Details: `system/app/RSpade/upstream_changes/CLAUDE.md`, `php artisan rsx:man upstream_changes`, `rsx:man prelaunch_checklist`. Related: `rspade:publishing-a-release`.
