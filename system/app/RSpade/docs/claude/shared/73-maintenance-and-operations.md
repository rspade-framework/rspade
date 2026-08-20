<!-- single-source: never duplicate into another fragment. THIS FRAGMENT IS THE CANONICAL HOME of maintenance mode; the locks and realtime fragments/skills point here. -->

## MAINTENANCE MODE & HEALTH

`php artisan rsx:maintenance:enable --reason="database surgery"` takes the app down; `rsx:maintenance:disable` brings it back. **ONE flag file** (its CONTENT being the operator reason), raised by these two commands and by `rsx:framework:pull`. Both are intercepted PRE-BOOT and shell out, so they are **immune to their own gate and work on a broken tree** — a flag left stuck by a crash is cleared by simply running disable. Enable stops services in a load-bearing order (flag first, then task workers, then realtime/fpc/php-fpm/rsx-lockd, redis LAST); disable reverses it.

**The gate is allow-most-deny-some — it stops AUTOMATION, not humans.** Blocked: `rsx:task:process`/`rsx:task:worker`; blocked without `--force`: `rsx:task:run`. **Everything else runs normally**, `migrate` included. Web requests get **503 + Retry-After quoting the reason** while PHP still runs; **once php-fpm is stopped the web server answers its own 502** — both mean "down".

**Code you run inside the window sees degraded services, deliberately and silently**: cluster locks are granted as no-ops (every writer they would exclude is already gone), `RsxCache` reads miss and writes drop, realtime frames are discarded with an empty registry. **System (flock) locks are untouched** — their contenders are exactly what maintenance does NOT stop. A connect failure with **no** flag on disk stays as loud as ever.

**Health**: `php artisan rsx:health [--json]` verifies dependencies, services and environment. **Exit 1 iff at least one FAIL row; WARN and INFO never flip the exit code.**

Skill `rspade:maintenance-mode` (exact service order and reasoning, the full tolerance list, stuck-flag recovery, how the pull and `rsx:git` raise the same window). Details: `rsx:man maintenance_mode`.
