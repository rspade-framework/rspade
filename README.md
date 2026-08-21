# RSpade

**Rapid Single Page Application Development Environment.**

**[rspade.org](https://rspade.org/)** · **[Documentation](https://docs.rspade.org/)** · [GitHub](https://github.com/rspade-framework)

RSpade is a batteries-included framework for building B2B web applications on
PHP and Laravel. This repository is not an empty skeleton — clone it, start it,
and you have a running multi-tenant SaaS with authentication, a client portal,
file handling, background jobs, live-updating pages and a component library,
which you then turn into your product.

It is deliberately opinionated: one way to build a page, one way to load a
record, one way to declare who may see it. There is no build step, no watcher and
no configuration layer — you edit a file and refresh the browser.

---

## Quick start

You need [Docker](https://docs.docker.com/get-docker/) and git. Nothing else —
no PHP, no Node, no MySQL on your machine.

```bash
git clone --depth 1 --recurse-submodules https://github.com/rspade-framework/rspade my-app
cd my-app
bash system/app/RSpade/resource/docker/build.sh
docker compose up
```

Then open **http://localhost:8080** and follow the setup screens: the
application asks for its own address, then for the account you want to sign in
with. That's it — no `.env` to edit first, no install step, no build to run.

Keep `docker compose up` in the foreground for that first run. Once you're set
up, `docker compose up -d` is fine.

<details>
<summary>Requirements, in a little more detail</summary>

- **Docker** with Compose v2 (`docker compose`, not `docker-compose`)
- **4 GB RAM** available to Docker. It will run in less, but the container holds
  MySQL, Redis, nginx, two PHP-FPM pools and several Node services, and 2 GB is
  uncomfortably tight.
- **~6 GB disk** for the image and its build cache
- A free port. `8080` by default — change it in `docker-compose.yml` if
  something else has it, and nothing else needs to agree with it.

The clone is a few hundred megabytes because dependencies are committed. That is
deliberate: it is why there is no install step and no network round-trip when you
start. `--depth 1` skips the history and is the recommended way in.

**`--recurse-submodules` is not optional.** The framework lives in `system/`, which
is a git submodule; without it you get an empty `system/` and nothing runs. If you
already cloned without it:

```bash
git submodule update --init --recursive
```

</details>

---

## What you get

The template application is a small but real CRM: clients, contacts, projects,
tasks and a client portal, all wired up and working. Every screen in it is built
the way the framework intends, so it doubles as the reference for how to build
your own.

Underneath:

**Single-page application by default.** One PHP bootstrap per module, then
JavaScript actions that navigate without page reloads. Routing, layouts and page
titles are declared with decorators.

**Components with no registration.** A component is up to three co-located files
sharing a name — `Foo.jqhtml` for markup, `Foo.js` for behaviour, `Foo.scss` for
its look. Drop them in and use `<Foo>`; the framework finds them.

**Ajax without endpoints to wire.** A static method marked `#[Ajax_Endpoint]`
becomes callable from JavaScript as `My_Controller.save({...})`. No route, no
URL, no client stub.

**Authorization you cannot forget.** Every dispatchable surface must declare an
`#[Auth]` gate. Surfaces are closed by default — one without a gate fails the
build rather than shipping open.

**Live pages.** Mark a model `$realtime` and pages showing its records refresh
themselves when it changes anywhere, over a WebSocket, with no polling.

**Background work.** A method marked `#[Task]` can be dispatched and polled;
`#[Schedule('daily at 3am')]` makes it recurring. One tick drives everything.

**Files and documents.** Content-addressed storage with automatic
de-duplication, thumbnails, text extraction and an in-browser preview for PDFs
and Office documents.

**And a client portal** — a second authenticated experience for your customers,
running alongside the staff application with its own routing and permissions.

---

## Working on it

Edit a file, refresh the browser. There is no build step, no watcher and no
compile command — the framework compiles on demand, and your change is live in
under a second.

```bash
# A shell inside the container, where all the tooling lives
docker compose exec app bash

php artisan rsx:man                 # every documentation topic
php artisan rsx:man spa             # ...and one of them
php artisan rsx:check               # code quality
php artisan rsx:test                # the test suite
php artisan rsx:debug /clients      # render a page headlessly, with real JS
php artisan migrate                 # apply schema changes
```

`php artisan rsx:man` with no argument lists every topic there is. The framework
is heavily documented from the inside; that command is the way in.

Run those from inside the container, not from your host. In development mode
`artisan` checks and refuses if it is anywhere else — the tooling assumes the
container's services and data layout, and outside it commands would half-work
rather than fail cleanly. The refusal prints the `docker compose exec` line for
whatever you just typed, so it costs you one retry. A deployed application in
production mode has no such restriction.

Your code lives in `rsx/`. The framework lives in `system/` and is updated as a
unit:

```bash
php artisan rsx:framework:pull
```

That fetches the current release, applies it, and leaves your application
untouched. Treat `system/` as read-only — customising a framework class is done
by copying it into `rsx/` under the same class name, and the framework will use
yours instead.

---

## Git

**Run git inside the container**, not on your host:

```bash
docker compose exec app bash        # then use git normally
```

or without the shell:

```bash
docker compose exec app git status
docker compose exec app git commit -am "..."
```

Inside the container `git` is the RSpade git proxy. It behaves like git in every
way you care about, with one difference that matters: **it keeps `system/` out of
your commits.**

That directory is the framework, written by `rsx:framework:pull`, and it is
almost never in a settled state — it carries build artifacts, temporary override
files, and whatever a half-finished update left behind. Commit that alongside
your own work and those files fight the next framework update. The proxy also
puts the application into maintenance mode around operations that rewrite the
working tree, so nothing is reading `system/` while it changes underneath.

Using git from your host instead is not forbidden, it just gives up those two
things — so the container is the path of least regret.

---

## Claude Code

RSpade works with any agentic AI tooling, or none at all — nothing in the
framework requires it. But **Claude Code is the official assistant for RSpade**,
and the project is set up for it: `CLAUDE.md` instruction files throughout the
tree, a skills plugin covering every subsystem, and CLI configuration already in
place. That is several hundred pages of conventions, mandates and worked
examples, so an assistant writes *RSpade* code rather than generic Laravel — the
difference between help and hindrance on an opinionated framework.

Build the image with Claude Code included:

```bash
bash system/app/RSpade/resource/docker/build.sh --claude
```

Then start a session inside the container:

```bash
docker compose exec app claude
```

Or from a shell you are already in (`docker compose exec app bash`), just type
`claude`.

The launcher checks for a newer release on each start and updates itself, so the
CLI does not go stale at whatever version existed on the day you built the
image. Set `RSPADE_CLAUDE_NO_UPDATE=1` to pin it.

**Your settings persist.** `docker-compose.yml` maps `~/.claude` in the
container to `storage/.claude` in your project, so your login, preferences and
history survive `docker compose down` and rebuilding the image. That directory is
gitignored, so none of it is committed.

Run it inside the container rather than on your host — that is where the project,
the tooling and the documentation all are, and where `git` is the RSpade proxy
described above.

---

## Configuration

`.env` holds deployment-specific values — database credentials, mail settings,
API keys. It is created for you on first run.

**`.env.README` explains every credential**: what it is, how to obtain one, and
what happens when it is not set. Start there rather than guessing from key
names.

Application *behaviour* is configured in `rsx/resource/config/rsx.php`, which is
version-controlled and merges over the framework's defaults.

### The database

The development container runs its own MySQL, and the client tools are in there
with it — you do not need MySQL installed on your machine to use them.

```bash
# A SQL prompt on the application's database
docker compose exec app mysql rspade

# Dump the whole schema and its data
docker compose exec app mysqldump rspade | less

# Throw the database away and start over
echo "drop database rspade; create database rspade" | docker compose exec app mysql
```

**Name the database.** `mysql` on its own connects to the server without
selecting anything, and the first query answers `ERROR 1046 (3D000): No database
selected`. `mysql rspade` is the form to use. Same for `mysqldump`, which dumps
nothing useful without being told what to dump.

**After a reset, migrate.** Dropping the database leaves you with an empty one
and an application that cannot serve a page. Bring the schema back, then let the
setup screen create your account again:

```bash
docker compose exec app php artisan migrate
```

The first-run screens return on the next page load, because the account you had
lived in the database you just dropped.

**No password, and that is not an oversight.** MySQL's `root` account here
authenticates by unix socket — the operating-system user connecting *is* the
credential — and `docker compose exec` runs as root inside the container. So
these commands arrive already authenticated, as `root`, with more privilege than
the application itself has. The application connects as the `rspade` user with
the password in `.env`, which is why `DB_USERNAME` and `DB_PASSWORD` are there
and why you never need to type them here.

None of this reaches outside the container: the socket is not on your machine
and the port is not published. If you renamed the database in `.env`, use that
name in place of `rspade`.

**Connecting from your own tools** — TablePlus, DataGrip, DBeaver — needs the
port published to your machine, which it is not by default. Add it to the `app`
service in `docker-compose.yml`:

```yaml
    ports:
      - "8080:80"
      - "3306:3306"
```

Then connect to `127.0.0.1:3306` with the credentials from `.env`. Leave it off
if you do not need it: it is one more thing listening on your machine.

### Where your data lives

Everything the running application writes lands under `storage/` in your
project — it is gitignored, and it is the whole of the state:

| | |
|---|---|
| `storage/mysql_data` | the database |
| `storage/storage/files` | uploaded file attachments |
| `storage/logs` | application logs |
| `storage/.claude` | Claude Code's settings and history |

Back the project up by copying it. `docker compose down -v` destroys named
volumes, and none of this is in one, so it survives.

---

## Running it without Compose

`docker compose up` is the shortest path and the one the Quick start uses. The
shipped `docker-compose.yml` is not doing anything clever, though — here is the
whole of it as a single `docker run`:

```bash
docker run -d \
  --name rspade \
  -p 8080:80 \
  -e PUID=$(id -u) -e PGID=$(id -g) \
  -v "$PWD":/var/www/html \
  -v "$PWD/storage/mysql_data":/var/lib/mysql \
  -v "$PWD/storage/.claude":/root/.claude \
  --stop-timeout 30 \
  rspade/rspade-server-dev:latest
```

Reach for this when Compose is in your way: when the container is one service in
a larger stack, when you want the project, the database or Claude Code's state
somewhere other than this directory, or when the port should not be published to
the host at all because something else is terminating traffic in front of it.

What each part is for:

| | |
|---|---|
| `-p 8080:80` | the application. Drop it entirely behind a reverse proxy on a shared network — see below |
| `-e PUID` / `-e PGID` | run as you, so files the application writes (including the database) are yours. Linux only; harmless elsewhere |
| `-v "$PWD":/var/www/html` | your project. The container serves what you edit |
| `-v .../var/lib/mysql` | the database. Point it anywhere you like — it is an ordinary directory |
| `-v .../root/.claude` | Claude Code's login and history, if you use it |
| `--stop-timeout 30` | lets the container shut down cleanly instead of being killed mid-write |

Pass a command and it runs once the application is actually serving, taking the
container down when it exits — so this boots in the foreground, shows you the
startup, and leaves you at a prompt:

```bash
docker run -it --rm \
  -p 8080:80 \
  -v "$PWD":/var/www/html \
  -v "$PWD/storage/mysql_data":/var/lib/mysql \
  rspade/rspade-server-dev:latest bash
```

Keep the database mount even here. Without it the container gets a fresh,
empty database that `--rm` throws away when the shell exits — fine if that is
what you meant, surprising if it is not.

### Behind a reverse proxy

The container serves plain HTTP on port **80** and expects TLS to be terminated
in front of it. Put it on a network with your proxy and publish nothing:

```bash
docker network create web

docker run -d --name rspade --network web \
  -e VIRTUAL_HOST=app.example.com \
  -e LETSENCRYPT_HOST=app.example.com \
  -v "$PWD":/var/www/html \
  -v "$PWD/storage/mysql_data":/var/lib/mysql \
  rspade/rspade-server-dev:latest
```

That labelling is [nginx-proxy](https://github.com/nginx-proxy/nginx-proxy)'s.
With [Caddy](https://caddyserver.com/), a two-line Caddyfile does the same job
and obtains the certificate itself:

```
app.example.com {
    reverse_proxy rspade:80
}
```

**Set `APP_URL` to the address people actually type**, not the container's:

```
APP_URL=https://app.example.com
```

RSpade generates every URL from that one value, and outside development mode it
must be `https` — the session cookie is `Secure` there, so a plain-http page
would discard it on every request and nobody could stay logged in. Your proxy is
what makes it https; the container itself never needs a certificate.

Two things that follow from that, worth knowing before you debug them:

- Forward the usual `X-Forwarded-Proto` and `X-Forwarded-For` headers. Both
  proxies above do it by default.
- The **production** image (`rspade/rspade-server-prod`) ships no database. It
  expects one you provide, configured through `.env` — so the `/var/lib/mysql`
  mount above is a development-image concern and does not apply to it.

---

## Going to production

```bash
php artisan rsx:mode:set prod
php artisan rsx:prod:enable
```

Production is a sealed build: assets are compiled once, minified and pinned, and
the application refuses to serve anything that drifts from the seal. There is a
matching production container target that omits the bundled database and expects
you to bring your own.

Before you launch, read `php artisan rsx:man prelaunch_checklist`. It is a real
checklist of things that are impractical to enforce automatically, and it exists
because each entry on it has bitten somebody.

---

## Documentation

Everything ships with the framework:

| | |
|---|---|
| `php artisan rsx:man` | every topic, listed |
| `.env.README` | every credential in `.env` |
| `rsx/resource/docs/` | your application's own documentation lives here |

If you use an AI coding assistant, the framework carries its own instructions
for one — several hundred pages of conventions, mandates and worked examples, so
that an assistant writes RSpade code rather than generic Laravel. No particular
tool is required or assumed.

---

## Built on Laravel, but not Laravel

The reward for those opinions is that application code stays small and
consistent, with nothing to configure. The cost is that RSpade **diverges from
Laravel substantially** — mass assignment is prohibited, eager loading throws,
the Schema builder is not used, and dates are strings rather than Carbon
objects.

Do not assume a Laravel pattern works here without checking.
`rsx:man framework_divergences` lists every difference.

---

## License

MIT — see [LICENSE](LICENSE).

Copyright (c) 2026 HansonXyz
