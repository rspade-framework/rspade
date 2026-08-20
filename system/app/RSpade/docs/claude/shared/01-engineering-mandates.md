<!-- single-source: never duplicate into another fragment. -->

## ENGINEERING MANDATES

### NEVER add a timeout

**Do not put a timeout in code. Not one. Not ever — unless the user ASKED for it, or you PROPOSED one and they explicitly approved it.** No `setTimeout()`, no `--timeout` flag, no `max_execution_time`, no lock lease or TTL, no watchdog deadline, no "safety" cap on how long a loop, copy, query or wait may run. This is a data-integrity rule, not a style preference: **a timeout converts a working operation into a failed one at the worst possible moment, and hands that failure to code that never expected it.**

```php
// [NO] WRONG - nobody asked for this, and it is not a safety net
$process = new Process(['cp', '-rT', $source, $dest]);
$process->setTimeout(60);        // large copy -> "exceeded the timeout" -> FAILS

// [OK] CORRECT - the operation takes as long as the data requires
$process = new Process(['cp', '-rT', $source, $dest]);
$process->setTimeout(null);
```

Picture that `cp -r` capped at 30 seconds. It does not finish — and the next line, written by someone who never imagined a timeout existed, deletes the source directory. **That is the shape of every timeout bug**, and it has already bitten this framework twice (narratives in skills `rspade:migrations` and `rspade:locks-and-subprocesses`).

**Slowness is normal and is never evidence of a hang.** How long a copy, query, build or critical section takes is a function of data size and machine load, and your code does not get an opinion about it. A genuine hang is a fault to SEE and diagnose, not to paper over.

**The one narrow legitimate case is still a PROPOSAL, never your decision**: bounding a wait on an EXTERNAL party that may never answer, where expiry degrades to a working outcome. Say what you want to bound, why, and what happens on expiry — then wait. **Bounding your own work is never legitimate.**

**Your own tool calls follow the same rule**: the only timeout you set unprompted is the standard ~2-minute backgrounding of a long-running command; never shorten it to "check on" something, and never cap an operation you were told to run to completion.

### Fail loud — no silent fallbacks

**ALWAYS fail visibly.** No fallback systems, no silent failures, no alternative code paths. **NO BLANKET TRY/CATCH** — let exceptions bubble to the global handler and catch only expected failures (file uploads, outbound API calls, user input). An exception handler **formats** an error; it never **wraps** one and never substitutes a value, and you **never continue after a security failure**. `shouldnt_happen("msg")` is the impossible-condition assertion in both languages — for broken assumptions, never for expected input. Skill `rspade:ajax-error-handling`.

### Silent success — unix philosophy

"No news is good news" — silent when working, speak for problems and primary results.

### Do the whole job

**A function does exactly what its name says, in its entirety, and nothing else.** `ls` does not stop after 100 files; `get_sessions_for_user()` returns ALL of a user's sessions — if it returned the first 100 it would be named `get_first_100_sessions_for_user()`. Never process a partial set when asked to process a full set; be conservative about memory, but memory never wins by dropping data — satisfying both means ITERATING, not truncating. A function whose job is "these records" returns `->result_set()` (an ordinary `foreach` reaches every record, one keyset page at a time). **A bare `LIMIT` that silently truncates is the bug** — it is right when it IS the mechanism or when the caller asked for N; otherwise say why at the call site. Scope is DATABASE result sets, not in-process work the codebase itself sizes. Skill `rspade:bounded-result-sets`.

### No defensive coding for core classes

Core framework classes ALWAYS exist (`Rsx_Manifest`, `Rsx_Storage`, `Rsx`, everything in `Core/Js/`) — never check existence (no `if (typeof Rsx !== 'undefined')`, no `class_exists()`). The build system guarantees it.

### Always bash — never sh, never an implicit shell

**bash is the ONLY shell this project's tooling invokes, and it is invoked EXPLICITLY.** Scripts run with an explicit `bash ` prefix, never relying on the exec bit (the one exception is a `.git/hooks/<name>` file, which git execs directly). Inline shell is never `sh`, never `sh -c`, never an IMPLICIT `/bin/sh` — PHP `shell_exec()`/`passthru()`/string `proc_open()` and node `child_process.exec()` all run `/bin/sh`, which is **dash** here, and dash once killed every helper spawned under a held lock (`sh: 1: exec: 11: not found`). Skill `rspade:shell-invocation`.
