@../../system/app/RSpade/docs/claude/app.md

# Application-Specific Instructions

**PURPOSE**: This file contains instructions specific to THIS RSpade application project. This is separate from the framework documentation.

## Documentation Structure

**Framework documentation** (immutable, replaced on updates):
- Located at: `~/.claude/CLAUDE.md` or `/var/www/html/system/docs/CLAUDE.dist.md`
- Contains: Complete RSpade framework architecture, conventions, and patterns
- **DO NOT modify** - this file is replaced during framework updates

**Application documentation** (THIS FILE - mutable, project-specific):
- Located at: `/var/www/html/CLAUDE.md` (symlink to /var/www/html/rsx/resource/CLAUDE.md)
- Contains: Project-specific rules, conventions, and development guidelines
- **SHOULD be modified** to reflect your project's evolving needs
- This is a starter template - revise and replace as necessary for your project

---

## FRAMEWORK IS BETA

RSpade is in active development. You may encounter bugs, unexpected behavior, or incomplete features.

**Context**: While this document is written from the perspective of a general RSpade user, you (the AI assistant) are actually working directly with Brian, the author and sole maintainer of RSpade. Currently, RSpade is a personal internal framework created for rapid development of contract and personal projects. The name stands for **R**apid **S**ingle **P**age **A**pplication **D**evelopment **E**nvironment - it exists to help Brian build web applications faster, more uniformly, and exactly to his preferences.

This dual context is important: the framework is designed to eventually serve a broader audience, but right now every decision, convention, and workflow optimization is tailored to Brian's development style and needs.

**Report issues when:**
- Commands behave unexpectedly or produce confusing error messages
- Workflows feel unintuitive or create chicken-and-egg problems
- Pre-commit hooks or safety mechanisms block legitimate work
- Framework assumptions conflict with real-world usage patterns
- Git operations from wrong directory produce confusing results
- Update mechanisms touch user code when they shouldn't

Since you're working with the framework author, feedback is immediate and direct. Clear, actionable observations help improve RSpade for current and future use.

## APPLICATION CODE LOCATION

**All your application code lives in `/var/www/html/rsx/`**

This is where you build your application:
- `/var/www/html/rsx/app/{module}/` - Your modules, controllers, views
- `/var/www/html/rsx/models/` - Your database models
- `/var/www/html/rsx/resource/` - Non 'rsx' files - any files in this directory will not be loaded by the framework.  Helper php files, external files for reference, and documentation should all go here
- `/var/www/html/rsx/resource/config/` - Your configuration overrides
- `/var/www/html/rsx/public/` - Your static files.  Anything in any directory named public will not be loaded by the framework directly, and are exposed and downloadable by accessing the path in a web browser
- `/var/www/html/rsx/theme/` - Your application themes and components

app, models, and theme are merely convention - all files in rsx, excluding files under a directory named resource or public, are path agnostic and can be placed anywhere or named anything.

Everything outside `/var/www/html/rsx/` is framework code managed by the RSpade team.

## GIT WORKFLOW

### 🔴 CRITICAL: Framework Code is READ-ONLY

**AI AGENTS: You must NEVER modify files in `/var/www/html/system/` or commit to `/var/www/html/.git`**

The framework code in `/var/www/html/system/` is managed by the RSpade team. It's equivalent to the Linux kernel or node_modules - external code that you don't modify directly.

**Forbidden actions in `/var/www/html/system/`:**
- ❌ NEVER edit framework files
- ❌ NEVER run `git add`, `git commit`, `git rm` in `/var/www/html`
- ❌ NEVER remove framework files from git tracking
- ❌ NEVER stage framework changes
- ❌ NEVER fix issues in framework code (report them instead)

**Only exception:** Updating framework via `php artisan rsx:framework:pull` (this is automated and safe)

### Git Repository Structure

**Application repo:** `/var/www/html/.git` (your code, you control)
**Framework submodule:** `/var/www/html/system/` (read-only, managed by RSpade team - DO NOT TOUCH)

### Working Directory Rules

**All code changes shall be made in `/var/www/html/rsx` for application code.  Do not make any changes outside of /var/www/html/rsx except CLAUDE.md (which is just a symlink to rsx/resource/CLAUDE.md)**

**run artisan commands from `/var/www/html`:**

```bash
cd /var/www/html
php artisan rsx:check   # ✅ Framework commands run from here
```

**commit from `/var/www/html`:**

```bash
cd /var/www/html    # ✅ CORRECT
git add -A
git commit -m "Snapshot: description"
git push origin master
```


### Snapshotting Philosophy

**Git is used for snapshotting, not code collaboration** in this project.

- **Always use `git add -A`** - Stage all changes
- **All git operations from `/var/www/html`** - Project root is the git root
- **All code changes in `/var/www/html/rsx`** - Though you commit from project root
- **No complex branching** - Git is a history tool, not a collaboration tool
- **Only commit when explicitly requested** - Wait for user instruction to commit

### Protection Mechanisms

**`.gitattributes` merge protection:**
The framework repo has `/rsx/** merge=ours` configured, which tells git to ALWAYS keep your version of `/rsx/` files during framework updates, completely ignoring upstream changes.

**Git submodule:**
The framework at `/var/www/html/system/` is a git submodule pointing to the RSpade framework repository. Git automatically prevents you from accidentally modifying submodule contents.

## FRAMEWORK DOCUMENTATION

For complete RSpade framework documentation, see `~/.claude/CLAUDE.md` or `/var/www/html/system/docs/CLAUDE.dist.md`

That file contains:
- Complete framework architecture and conventions
- Module creation patterns and best practices
- JavaScript/jqhtml component system
- Database patterns and migrations
- Routing and authentication
- All framework commands and utilities


## This File (CLAUDE.md)

Use this file to document:
- **Project-specific context** - Business domain, purpose, architectural decisions
- **Custom conventions** - Team-specific patterns, naming conventions, coding standards
- **Development notes** - Setup instructions, deployment procedures, known issues
- **Module documentation** - Descriptions of your custom modules and their purposes
- **API integrations** - Third-party services, authentication details, endpoints
- **Database schema notes** - Important relationships, migration strategies, data models

This file is **NOT managed by the framework** and will persist across framework updates.

---

## Example Documentation Structure

### Project Overview
[Describe what this application does and who it's for]

### Architecture Decisions
[Document key architectural choices and why they were made]

### Custom Modules
[List and describe your RSX modules in /rsx/app/]

### Development Workflow
[Team-specific practices, git workflow, deployment process]

### Environment Setup
[Project-specific setup requirements beyond standard RSpade installation]

## UI STYLING PREFERENCES

### Bootstrap Card Styling

**Avoid `shadow` and `border-0` classes:**

We prefer clean, simple card styling without shadows or border removal. When using Bootstrap cards, apply only the base classes needed:

```html
<!-- ✅ GOOD -->
<div class="card card-body mb-4">
  <h2 class="h5 mb-4">Section Title</h2>
  <!-- content -->
</div>

<!-- ❌ BAD -->
<div class="card card-body border-0 shadow mb-4">
  <h2 class="h5 mb-4">Section Title</h2>
  <!-- content -->
</div>
```
**Rationale:** Keep visual styling minimal and consistent. The default card styling is sufficient - no need for additional shadow effects or border manipulation.

