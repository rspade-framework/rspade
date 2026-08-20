---
name: shell-invocation
description: Invoking shell commands and repo scripts correctly in RSpade - always explicit bash, never sh or an implicit /bin/sh. Use when writing shell_exec/passthru/proc_open/exec_safe from PHP, child_process from node, registering a Claude Code hook or statusline, writing a git hook body, adding a supervisor or cron command line, calling one script from another, or diagnosing "Permission denied" on a script or "sh: 1: exec: 11: not found".
---

# Shell Invocation

**bash is the only shell this project's tooling invokes, and it is invoked EXPLICITLY.** Two halves, one rule.

## (a) Scripts: an explicit `bash ` prefix - never rely on +x

Every place our tooling executes a repo shell script gets the prefix:

- Claude Code hook / statusline registrations
- git hooks' bodies
- supervisor / cron command lines
- subprocess calls from PHP or node
- scripts calling scripts

**Why**: downstream, `core.fileMode false` plus the framework pull's rsync make the exec bit unreliable, so a bare path dies with "Permission denied" - observed live on a registered hook script, 2026-08-05.

```bash
# [NO] WRONG - depends on an exec bit that downstream cannot keep
/var/www/html/system/bin/statusline.sh

# [OK] CORRECT
bash /var/www/html/system/bin/statusline.sh
```

**The one unavoidable exception** is a file the OS/git execs directly - a `.git/hooks/<name>` entry file. Those carry a `#!/bin/bash` shebang, and **their installer must `chmod +x` on install AND heal a lost bit on ours-marked hooks.**

**Owner ruling (2026-08-05): installers that registered a bare-path or `sh` spelling in the past must RECOGNIZE and UPGRADE it.** Writing the new spelling for fresh installs is not enough - an environment already carrying the old registration stays broken until the installer rewrites it.

## (b) Inline shell: never `sh`, never `sh -c`, never an IMPLICIT `/bin/sh`

PHP `shell_exec()` / `passthru()` / string-form `proc_open()` and node `child_process.exec()` all run `/bin/sh`, which is **dash** on our platforms.

### PHP

```php
// [NO] WRONG - runs under /bin/sh (dash)
shell_exec('cd ' . escapeshellarg($dir) . ' && git rev-parse HEAD');

// [OK] CORRECT - explicit bash
shell_exec('bash -c ' . escapeshellarg('cd ' . escapeshellarg($dir) . ' && git rev-parse HEAD'));

// [BEST] exec_safe() already runs bash internally, and returns a real exit code
exec_safe('cd ' . escapeshellarg($dir) . ' && git rev-parse HEAD', $output, $return_var);
```

`exec_safe()` does it internally via array-form `proc_open(['bash','-c',$cmd])`, so **every caller of it is already compliant** - prefer it.

### node

Use `execFile` / `spawn` with an argv array, or pass `{ shell: '/bin/bash' }`:

```javascript
// [NO] WRONG - exec() runs /bin/sh
child_process.exec(`cd ${dir} && git rev-parse HEAD`, cb);

// [OK] CORRECT - argv, no shell at all
child_process.execFile('git', ['-C', dir, 'rev-parse', 'HEAD'], cb);

// [OK] CORRECT - a shell string, but explicitly bash
child_process.exec(cmd, { shell: '/bin/bash' }, cb);
```

## Why it is a rule and not a preference

POSIX sh guarantees only **SINGLE-DIGIT file descriptors** in redirections, and dash enforces that. The framework's lock-fd-close wrapper emits `exec 11>&-` once a lock descriptor lands at 10 or above; dash parses that as a command named `11`, and every long-lived helper spawned under a held lock died with:

```
sh: 1: exec: 11: not found
```

Observed in the field, 2026-08-13. The symptom appeared far from the cause: helpers spawned under a held lock, all failing, with nothing in the calling code mentioning a shell at all.

## Diagnosing

| Symptom | Likely cause |
|---|---|
| "Permission denied" running a repo script | missing `bash ` prefix; exec bit lost to `core.fileMode false` / rsync |
| `sh: 1: exec: NN: not found` | an implicit `/bin/sh` (dash) executing bash-only syntax - usually a multi-digit fd redirection |
| Works locally, fails downstream only | the environment that lost the exec bit, or an installer that never upgraded an old registration |

## See also

The always-on rule and the dash root cause live in the engineering-mandates fragment. Artisan subprocesses have their own mandate (`Rsx_Artisan`) - never spawn `php artisan` through any of the shell helpers above.
