<!--
DOWNSTREAM-APP always-on knowledge index. Loaded by the app's
rsx/resource/CLAUDE.md via @../../system/app/RSpade/docs/claude/app.md (the
import line is maintained by environment update 060). Import paths in this file
are relative to THIS file's real location.

This file is an IMPORT INDEX: it lists fragments, it does not carry content.
Fragments live in shared/ (true in both environments) and app/ (true only in a
downstream application). Populated during Phase 2 of the knowledge restructure.

FRAMEWORK-OWNED: this file and everything it imports are replaced wholesale by
rsx:framework:pull. Application knowledge belongs in rsx/resource/CLAUDE.md (the
importer) and the app's own .claude/skills/ - never here.
-->

# RSpade Application Development — Always-On Knowledge (downstream view)

**This file is framework-owned and replaced by `php artisan rsx:framework:pull` — never edit it.** Your knowledge lives in `rsx/resource/CLAUDE.md` (this file's importer) and your own `.claude/skills/`.

When you write there, match this tone: **terse, not verbose** (no filler words or redundant explanations), **complete, not partial** (include all critical information), **patterns over prose** (code examples beat paragraphs). The assembled view targets **≤50KB**; every line must justify its existence.

## Imports

@shared/00-agent-conduct.md
@shared/01-engineering-mandates.md
@shared/02-code-conventions.md
@shared/03-what-is-rspade.md
@shared/04-delegation.md
@shared/10-developer-workflow.md
@shared/11-endpoints.md
@shared/12-stdlib-and-time.md
@shared/20-models-orm.md
@shared/21-schema-audit-polymorphic.md
@shared/30-jqhtml-components.md
@shared/31-spa-and-pages.md
@shared/32-ui-and-styling.md
@shared/40-forms-and-modals.md
@shared/45-auth-gates.md
@shared/46-sessions-and-login.md
@shared/47-portal.md
@shared/55-background-work.md
@shared/56-realtime.md
@shared/57-events-and-messaging.md
@shared/65-files-and-documents.md
@shared/70-build-and-modes.md
@shared/72-paths-config-environment.md
@shared/73-maintenance-and-operations.md
@shared/80-git-and-conflicts.md
@app/00-app-context.md
@app/70-app-operations.md
@app/80-framework-updates.md
@app/81-git-and-system-readonly.md
@app/90-knowledge-routing.md
