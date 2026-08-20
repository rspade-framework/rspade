<!-- single-source: never duplicate into another fragment. THIS FRAGMENT IS THE CANONICAL HOME of the merge-conflict mandate and the class-override pattern. The per-environment git rules (what `system/` means, who may commit it) live in the framework and app git fragments and must never be restated here. -->

## GIT DISCIPLINE

**Commits = MAJOR MILESTONES, and only when the user requests one.** Individual changes are not commits. **The sole exception**: checkpoint commits between reviewed implementation agents during a delegation epic — see the delegation fragment.

## MANDATE: RESOLVING MERGE CONFLICTS

**A merge conflict is a code-review task, not a cleanup chore.** Both sides are work someone intended to keep. Discarding a side is a deliberate, justified decision — never a default, and never a batch operation.

**FORBIDDEN unless the user explicitly directs it for that specific conflict:** `git checkout --ours/--theirs <path>`, `git merge -X ours/theirs` or `git checkout HEAD -- <dir>` over a conflict set you have not read hunk by hunk; resolving by file *category* ("just build artifacts / generated files / machine churn" — **category is a hypothesis, verify it per file**); `git stash` or `git reset --hard` to make a conflict "go away".

**Required procedure:**
1. **Enumerate** — `git status`, the full conflicted-path list, and state the count.
2. **Learn the other side's intent BEFORE resolving** — `git log -p -n 3 <other-branch> -- <path>`. You cannot judge a hunk without knowing what the incoming change was FOR.
3. **Resolve per hunk, with a reason.** Default posture is **keep both**; take one side only when they are genuinely mutually exclusive, and say why.
4. **Escalate rather than guess.** One question costs less than a silent rollback.
5. **Volume is a reason for MORE care, not less.** If the set is too big to review honestly, say so and ask.
6. **Verify before committing** — build, run the relevant tests, confirm the incoming feature still works.
7. **Report** what you kept from each side and anything you dropped.

**Why this is a mandate and not advice**: a blanket resolution that looks like tidying can silently revert an entire framework release. `system/` is a vendored tree of thousands of machine-owned files producing large, mechanical-looking conflict sets; taking the local side across one rolls the framework back a release while the app keeps the code written against the newer one — broken behavior, no error.

**`system/` conflicts are the one place with a per-environment rule** — in a downstream app `rsx:git` settles them itself, by RELEASE, before the merge runs (see the app git fragment), and the monorepo has no such rule (framework git fragment). Everywhere else `--ours`/`--theirs` stays forbidden without explicit user direction.

## CLASS OVERRIDES

To customize a framework class without modifying `system/`, **copy it into `rsx/` under the same class name** — copy-and-replace, not a subclass. The manifest uses your version, renames the framework file to `.upstream`, and keeps every reference to the old FQCN resolving. That `.php` <-> `.php.upstream` churn is always-local state and is never committed. Details: `rsx:man class_override`, `rsx:man rsx_git`.
