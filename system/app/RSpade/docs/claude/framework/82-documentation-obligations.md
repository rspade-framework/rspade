<!-- bucket: framework — single-source, never duplicate. True ONLY in the RSpade
monorepo. CANONICAL HOME of the documentation-authoring obligations. -->

## DOCUMENTATION OBLIGATIONS

**Man pages are the CONTRACT tier** — the full treatment of a feature lives there, not in always-on knowledge. Framework man pages: `system/app/RSpade/man/*.txt`; project man pages: `rsx/resource/man/*.txt`. Terse, complete, expert-level. **Never update a man page autonomously.**

**The prelaunch checklist** (`rsx:man prelaunch_checklist`) is the designated home for launch-gating audits that are impractical to enforce with a lint rule. It is a **sanctioned enforcement channel**, and **the RSpade LLM may proactively suggest "add this to the prelaunch checklist"** to the owner.

### MANDATE: shipping a downstream-facing contract change

**Any framework change that alters the CONTRACT or SYNTAX of how downstream code is written or invoked is not done until all of these are done** — not one of them, all that apply. A change that is only implemented is a change nobody downstream can use correctly.

1. **This monorepo's always-on knowledge** (`docs/claude/framework/`, or `docs/claude/shared/` when true in both environments) — how to USE the feature. **Terse.**
2. **The shared fragment(s)** — **write it ONCE**; the downstream view assembles the same file.
3. **The template app (`/rsx/`)** — make it demonstrate the new way; a template doing it the old way teaches the old way.
4. **The relevant man page(s)** — the full treatment.
5. **`upstream_changes/*.txt`** — the migration: how to find and convert EXISTING downstream code.
6. **`man/prelaunch_checklist.txt`** — ONLY when the change does not self-correct through a thrown exception or failed build (a silent behavior change), and so needs a human eye.

**Keep the audiences separate.** Always-on knowledge describes how to write code with the feature TODAY — audit sweeps, "find your existing call sites" and conversion steps belong in `upstream_changes/`; recurring pre-launch verification belongs in the prelaunch checklist. Mixing migration prose into always-on knowledge bloats what every reader loads on every task.

**An `upstream_changes` document exists ONLY when the end user must MANUALLY act** — it is NOT a changelog, and self-applying behavior (owned-zone sync, environment updates, migrations) needs no document (owner ruling 2026-08-04). A recurring FRAMEWORK-INTERNAL audit goes instead to `docs.dev/audits/framework_internal_audit_checklist.md`.

### External requests

`docs.dev/external_requests/` holds pre-authorized requests from external environments that Brian controls. **MANDATE**: the directory is tracked by a self-maintained `INDEX.md` — one row per request (filename, disposition, the commit that fulfilled it, an absolute-date timestamp), updated whenever status changes. **A FULLY-processed request (`IMPLEMENTED`/`FOLDED`/`SUPERSEDED`/`DECLINED`) is ALSO MOVED into `archive/`** (its INDEX row then carries the `archive/` path); a file still at the top level is OPEN.

**Hierarchy**: development (`docs.dev/`) -> always-on knowledge (`docs/claude/{shared,framework,app}/`) + contract tier (`man/*.txt`, `Core/*/CLAUDE.md`) -> upstream changes. **Editing always-on knowledge**: *"Say only what needs to be said, but say all of it."*

Skill `rspade:shipping-a-contract-change` (working the mandate, audit routing, authoring an upstream_changes doc or checklist entry). Details: `rsx:man upstream_changes`.
