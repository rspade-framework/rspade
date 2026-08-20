# Upstream Changes Log

## THE GATE (owner ruling 2026-08-04): manual action required, or no document

An upstream_changes document exists ONLY when the end user must DO something to their
environment or their own code BY HAND. It is **NOT a changelog** - "what changed upstream"
is already carried by the framework-update commit body / `git log -- system/` /
`framework_update_history.dat`, and self-applying behavior (owned-zone sync, environment
updates via `system/bin/environment_updates/`, migrations) needs NO document, however large
the change. Before authoring, ask: *"with this release applied, is there anything left that
only the downstream developer can do?"* If the honest answer is no - FYIs, precautionary
backups, and "be aware that..." notes do not count - do not create the document.

## Critical: Understanding the Purpose

**The `/rsx/` directory is the starter template that end users fork to create their applications.**

When we make changes to files in `/rsx/`, users who previously forked the template do NOT automatically receive those changes. They must manually update their own copies.

**Upstream changes documents tell users EXACTLY what to change in their forked `/rsx/` code to match the upstream template.**

## Audience

The audience is a developer who:
- Forked the `/rsx/` starter template weeks or months ago
- Has been building their own application on top of it
- Wants to incorporate improvements we've made to the starter template
- Needs to know the EXACT file paths and code changes to make

## Consumption Side (Downstream Tracking)

Each downstream app tracks which of these documents it has already fulfilled
versus which are still pending, in a local manifest managed via the
`rsx:framework:upstream_changes` commands (list / show / mark). That is the
CONSUMER's side of this system; it is documented for downstream developers in
`php artisan rsx:man upstream_changes`.

**THIS file is the AUTHOR's side** - the format and charter for writing the
documents a downstream developer will later consume. When the maintainer asks
to "publish a change that requires downstream code fixes", this file defines
what that document looks like.

## When to Create a Document

There are two categories of upstream change that warrant a document. Both
coexist - any given document belongs to one category or the other.

### Category 1: `/rsx/` template diffs

Create a document when **ANY file in `/rsx/` is modified** that users would want
to replicate into their fork:
- New features are added to `/rsx/` files
- Bug fixes are made to `/rsx/` files
- Patterns or APIs change in `/rsx/` files

The user gets nothing automatically here (their `/rsx/` is their own fork), so
the document carries the exact code to copy.

### Category 2: `/system/` core behavior / API-contract changes

Create a document when a framework-core (`/system/`) change alters behavior or an
API contract in a way that downstream app code might rely on the old behavior,
need to re-verify its assumptions against, or must audit/remove compensating
workarounds for. Examples:
- A core function whose buggy return value (e.g. a wrong sign) is now fixed -
  downstream code may have grown compensating logic around the old broken
  behavior that must now be found and removed, or simply re-verified now that the
  contract is correct.
- A changed method signature, default, or semantic that downstream callers must
  re-check their assumptions against.
- A breaking change to, or the retirement of, a package on the exposed
  dependency lists (`config/rsx.php` `dependencies.exposed_composer` /
  `dependencies.exposed_npm`). Exposing a package is a standing forever
  commitment, so this is rare - but if it happens it is Category 2. The
  post-update dependency reconciler already surfaces affected downstream apps
  automatically (it checks each recorded provided package after every pull), so
  the document exists to explain the change and MUST include the adopt/re-record
  remedy: `php artisan rsx:composer require <pkg>` (or
  `php artisan rsx:npm install <pkg>`) to adopt a dropped package into the app
  layer, or to re-record against the new major after verifying usage.

The core fix itself ships automatically via the submodule merge - that part is
NOT what the document is about. The document exists because downstream may need
to ACT on their OWN code (audit / fix / re-verify), exactly like a `/rsx/`
template diff requires action.

Do NOT create documents for:
- Routine internal `/system/` changes with no downstream-actionable impact (users
  get these automatically via the submodule and simply benefit - an ordinary core
  bug fix that downstream silently inherits needs nothing).
- Internal refactoring that doesn't change functionality users would want.

## What the Document Must Contain

The document must provide everything needed to replicate the change:

1. **AFFECTED FILES** - Exact file paths in `/rsx/` that were changed
2. **WHAT CHANGED** - The specific code additions, modifications, or deletions
3. **HOW TO APPLY** - Step-by-step instructions or copy-paste code blocks

The goal is: a user can read the document and make the exact same changes to their fork without needing to diff files or guess what changed.

## File Naming Convention

```
{feature}_{month}_{day}.txt
```

Examples:
- `modal_events_01_28.txt` - Modal event changes on January 28
- `responsive_12_18.txt` - Responsive system changes on December 18

Use lowercase with underscores. Date is MM_DD format.

## Document Structure

Pick the structure matching the document's category.

### Category 1 structure (`/rsx/` template diff)

```
FEATURE NAME
Date: YYYY-MM-DD

SUMMARY
    Brief description of what changed and why users might want it.

AFFECTED FILES
    /rsx/path/to/file.js
    /rsx/path/to/other_file.php

CHANGES REQUIRED

    File: /rsx/path/to/file.js
    -------------------------------------------------------------------------
    [Exact code to add/change, with enough context to locate where]

    File: /rsx/path/to/other_file.php
    -------------------------------------------------------------------------
    [Exact code to add/change]

VERIFICATION
    How to verify the change works after applying it.
```

### Category 2 structure (`/system/` core behavior change)

An `AFFECTED FILES: /rsx/...` list makes no sense here - the changed file lives in
`/system/` and arrives automatically via the submodule. Use this shape instead:

```
FEATURE NAME
Date: YYYY-MM-DD

SUMMARY
    Brief description of the core change and who it affects.

THE BUG / THE CHANGE
    What was wrong and why (for a fix), or what changed and why (for a contract
    change).

THE FIX
    What changed in /system/ core. Informational only - downstream receives this
    automatically via the submodule, so there is nothing here to copy.

ACTION REQUIRED
    What downstream must actively check / audit / fix in THEIR OWN code. This is
    the part that makes the document mandatory - not the core fix itself.

VERIFICATION
    How to confirm downstream code is correct against the new contract.
```

## Key Principle

**Show the code.** Don't just describe what changed - show the exact lines to add or modify. Users should be able to copy-paste from the document into their files.
