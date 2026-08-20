# The RSpade container

The image definition for the RSpade application container. One Dockerfile, two
targets.

    docker build --target dev  -t rspade-dev  .
    docker build --target prod -t rspade-prod .

The application is **not** baked into the image. It is mounted at
`/var/www/html`, so one image serves every project.

## Where this fits

Three tiers own container configuration, and they do not overlap:

| Tier | Location | Owned by | Changes propagate |
|---|---|---|---|
| Image definition | `system/app/RSpade/resource/docker/` (here) | the framework | hard-synced on every `rsx:framework:pull` |
| App startup hook | `rsx/resource/docker/configure.sh` | the application | it is your file |
| Deployment | `docker-compose.yml` at the project root | the developer | your ports, your volumes |

Because this directory is inside a framework-owned zone, **local edits here are
overwritten by the next framework update.** To install packages or run setup for
your own application, use `rsx/resource/docker/configure.sh` — the entrypoint
runs it as root before the application serves traffic.

## dev vs prod

|  | dev | prod |
|---|---|---|
| MySQL server | in the container | **yours** — `mysql-client` only |
| Migrations at start | automatic | **never** — run them deliberately |
| OPcache | revalidate every request | revalidate every 10s |
| PHP-FPM | root, `ondemand` | `www-data`, `dynamic` |
| Browser libraries | yes (`rsx:debug`) | no |
| Blank credentials | generated + printed once | **refuses to start** |
| LibreOffice, poppler | yes | yes — document preview is a runtime feature |

## Services

All under supervisor; `supervisorctl status` lists them.

| Service | What it does |
|---|---|
| `nginx` | web server; `:80` plain, `:8000` asserting https behind a terminator |
| `php-fpm` | two pools — `www`, and `ajax` which exists to prevent an SSR deadlock |
| `redis` | cache, realtime delivery, short-lived counters |
| `mysql` | dev target only |
| `fpc-proxy` | full-page cache; calls back to nginx on `:3201` for misses |
| `realtime` | WebSocket relay behind `/ws` |
| `rsx-lockd` | cluster lock daemon — load-bearing, see its config |
| `tasks` | **the cron replacement**: ticks `rsx:task:process` once a minute |

That last one matters. The framework's documented driver for all background and
scheduled work is a single crontab line, and a container has no crontab. Without
it, `Task::dispatch()` succeeds and nothing ever runs it — silently.

## Two things that look wrong and are not

**MySQL is inside the dev container rather than its own compose service.**
`migrate` snapshots the database before running by stopping MySQL through
`supervisorctl`, copying the data directory, and starting it again. That
requires MySQL to be a supervised program in the same container as PHP. Split it
out and migration rollback stops working.

**php-fpm runs as root in the dev target.** The application tree is a bind mount
from a machine whose file ownership we cannot predict; running as `www-data`
would make everything the app writes owned by uid 33, which the developer then
cannot edit. Root inside a container is not root on the host. The previous
arrangement solved this with `chmod -R 777` plus a permanent inotify watcher
re-applying it — that is gone. Production has no bind mount and so runs as
`www-data`.

## Ports

| Port | Publish it when |
|---|---|
| `80` | plain http — the development default |
| `8000` | a reverse proxy terminates TLS ahead of the container |

`APP_URL` must name the port your browser actually uses. A container cannot
discover its host-side mapping, so pass `RSPADE_APP_URL` (the compose file
does). Get it wrong and the realtime socket dials the wrong port.

## Environment

| Variable | Effect |
|---|---|
| `RSPADE_APP_URL` | written into `.env` as `APP_URL` at start |
| `RSPADE_CONTAINER_TARGET` | `dev` or `prod`; set by the image, not by you |

Everything else is ordinary `.env` configuration — see `.env.README` in the
project root.

## First boot

1. Creates `.env` from `.env.dist` if absent.
2. Generates `APP_KEY` if blank.
3. Sets `APP_URL` from `RSPADE_APP_URL`.
4. **dev**: generates first-user credentials if blank and prints them once.
   **prod**: refuses to start rather than inventing a credential.
5. Unpacks a pristine MySQL data directory (dev, empty volume only).
6. Starts supervisor.
7. Waits for the database — without a deadline, because a slow start is normal
   and a timeout would turn "wait longer" into a failed boot.
8. Provisions databases (dev, first boot only).
9. **dev**: migrates. **prod**: reports pending migrations and leaves them.
10. Runs `rsx/resource/docker/configure.sh` if present.

Every step is idempotent; a restart finds its work done and says nothing.

## Architecture

Authored to build on amd64 and arm64 alike: everything installs through apt or a
distribution setup script, nothing fetches an architecture-pinned binary, and the
base image is pinned by tag rather than by a single-arch digest. Keep it that way
— a hardcoded `x86_64` download URL is what breaks the arm64 build.
