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
docker compose up
```

The first start downloads the RSpade development image
(`ghcr.io/rspade-framework/rspade-server-dev`, built for x86 and ARM alike), which
takes a few minutes once. Then open **http://localhost:8080** and follow the setup
screens: the application asks for its own address, then for the account you want
to sign in with. That's it — no `.env` to edit first, no install step, no build to
run.

Keep `docker compose up` in the foreground for that first run. Once you're set
up, `docker compose up -d` is fine.

<details>
<summary>Requirements, in a little more detail</summary>

- **Docker** with Compose v2 (`docker compose`, not `docker-compose`)
- **4 GB RAM** available to Docker. It will run in less, but the container holds
  MySQL, Redis, nginx, PHP and several Node services, and 2 GB is uncomfortably
  tight.
- **~6 GB disk** for the image
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

### VS Code

The project ships editor support for the things the framework adds to PHP — RSX
naming conventions, `.jqhtml` components, `Rsx::Route()` calls, jump-to-definition
across the manifest. **Open the project folder in VS Code and click "Install" when
it offers the recommended extensions** (**JQHTML** and **RSpade Framework
Support**). Neither is required, but RSpade's conventions are invisible to a stock
PHP setup, which will flag correct code as wrong and offer no navigation for
components, routes or models.

---

## Documentation

Everything ships with the framework, so it always matches the version you run:

| | |
|---|---|
| [**Developer crash course**](https://github.com/rspade-framework/rspade_system/tree/master/app/RSpade/docs/developer/DEVELOPER_QUICKSTART.md) | the framework in one sitting — **start here** |
| [Development environment](https://github.com/rspade-framework/rspade_system/tree/master/app/RSpade/docs/developer/DEVELOPMENT_ENVIRONMENT.md) | Compose or `docker run`, file ownership, the database and GUI clients, a reverse proxy, Claude Code, updating the image |
| [System administration](https://github.com/rspade-framework/rspade_system/tree/master/app/RSpade/docs/operations/SYSTEM_ADMINISTRATION.md) | what runs in the container, backups, updates, health, logs, maintenance mode, the `/_sys` panel |
| [Production deployment](https://github.com/rspade-framework/rspade_system/tree/master/app/RSpade/docs/operations/PRODUCTION_DEPLOYMENT.md) | the production image, first install, the deploy procedure, rollback, the container environment |
| `php artisan rsx:man` | the reference: every topic, listed |
| `.env.README` | every credential in `.env`: what it is and how to obtain one |
| `rsx/resource/docs/` | your application's own documentation lives here |

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

**New to RSpade? Read the [developer crash course](https://github.com/rspade-framework/rspade_system/tree/master/app/RSpade/docs/developer/DEVELOPER_QUICKSTART.md) first.**
It is the whole framework in one sitting, written for somebody joining an
existing project rather than designing one from scratch: how a page is assembled,
how a component loads its data and what the lifecycle rules are for, how models
reach JavaScript, and how forms, modals, routing, permissions, live updates and
background jobs are declared. It explains the reasoning behind the parts that are
unusual, so the code stops looking like magic and starts looking like
conventions. An hour with it is the difference between reading this codebase and
guessing at it.

---

## Tooling and commands

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

Run those from inside the container, not from your host. In development mode
`artisan` refuses anywhere else — the tooling assumes the container's services and
data layout — and the refusal prints the `docker compose exec` line for whatever
you just typed, so it costs you one retry.

**The database** is in the container too: `docker compose exec app mysql rspade`
gives you a SQL prompt. Connecting a GUI client such as TablePlus or DBeaver takes
one published port —
[Development environment](https://github.com/rspade-framework/rspade_system/tree/master/app/RSpade/docs/developer/DEVELOPMENT_ENVIRONMENT.md#4-the-database).

**Configuration.** `.env` holds deployment-specific values — database
credentials, mail settings, API keys — and is created for you on first run;
`.env.README` explains every one. Application *behaviour* is configured in
`rsx/resource/config/rsx.php`, which is version-controlled and merges over the
framework's defaults.

**Your code lives in `rsx/`. The framework lives in `system/`** and is updated as a
unit with `php artisan rsx:framework:pull`, which leaves your application
untouched. Treat `system/` as read-only — customising a framework class is done by
copying it into `rsx/` under the same class name, and the framework will use yours
instead.

---

## Git

**Run git inside the container**, not on your host:

```bash
docker compose exec app git status
docker compose exec app git commit -am "..."
```

Inside the container `git` is the RSpade git proxy. It behaves like git in every
way you care about, with one difference that matters: **it keeps `system/` out of
your commits.** That directory is the framework, written by `rsx:framework:pull`,
and it is almost never in a settled state — commit it alongside your own work and
those files fight the next framework update. The proxy also puts the application
into maintenance mode around operations that rewrite the working tree, so nothing
is reading `system/` while it changes underneath.

Using git from your host is not forbidden, it just gives up those two things — so
the container is the path of least regret.

---

## Claude Code

RSpade works with any agentic AI tooling, or none at all — nothing in the
framework requires it. But **Claude Code is the official assistant for RSpade**,
and the project is set up for it: `CLAUDE.md` instruction files throughout the
tree, a skills plugin covering every subsystem, and CLI configuration already in
place. That is several hundred pages of conventions, mandates and worked
examples, so an assistant writes *RSpade* code rather than generic Laravel — the
difference between help and hindrance on an opinionated framework.

Start a session inside the container:

```bash
docker compose exec app claude
```

The first `claude` installs Claude Code into the container, and it keeps itself up
to date from then on. Your login, preferences and history are kept in
`storage/.claude` in your project (gitignored), so they survive
`docker compose down` and a new image. Run it inside the container rather than on
your host — that is where the project, the tooling and the documentation all are.

---

## Administration basics

The full picture is in
[System administration](https://github.com/rspade-framework/rspade_system/tree/master/app/RSpade/docs/operations/SYSTEM_ADMINISTRATION.md);
these are the parts everyone needs.

**Where your data lives.** Everything the running application writes lands in your
project, outside the container: the database in `storage/mysql_data`, uploaded
files in `storage/uploads`, logs in `storage/logs`. A backup is the database,
`storage/`, and `.env` (which is not in git) —
[what to back up, and how](https://github.com/rspade-framework/rspade_system/tree/master/app/RSpade/docs/operations/SYSTEM_ADMINISTRATION.md#2-where-the-data-lives-and-what-to-back-up).

**Updating.** The image: `docker compose pull`, then `docker compose up -d` —
nothing is lost, because nothing lives in the container. When a framework update
needs a newer image than the one you are running, the container, `artisan` and the
web all refuse with the `docker pull` that fixes it. The framework:
`php artisan rsx:framework:pull`, then `php artisan rsx:framework:breaking_changes`
for anything it asks you to change by hand —
[updating](https://github.com/rspade-framework/rspade_system/tree/master/app/RSpade/docs/operations/SYSTEM_ADMINISTRATION.md#3-updating).

**Security updates are automatic.** The container installs PHP and system security
patches at every start and every four hours, restarting PHP without dropping a
request in flight.

**Health.** `php artisan rsx:health` checks every service, dependency and setting
the installation relies on, with a remedy beside anything wrong. The `/_sys` panel
shows the same report, and more, in the browser.

**Logs.** The application's log is `storage/logs/laravel.log`, rotated nightly by
the framework; the container's own output is `docker compose logs app` —
[logs](https://github.com/rspade-framework/rspade_system/tree/master/app/RSpade/docs/operations/SYSTEM_ADMINISTRATION.md#5-logs).

**Other ways to run it.** Without Compose, as a single `docker run`, or behind a
reverse proxy on a real hostname:
[Development environment](https://github.com/rspade-framework/rspade_system/tree/master/app/RSpade/docs/developer/DEVELOPMENT_ENVIRONMENT.md).

**Going to production.** Production runs the production image,
`ghcr.io/rspade-framework/rspade-server-prod`, with your own MySQL behind a
TLS-terminating proxy, as a sealed build; every deploy is pull the image, stop,
pull your code, snapshot the database, start —
[Production deployment](https://github.com/rspade-framework/rspade_system/tree/master/app/RSpade/docs/operations/PRODUCTION_DEPLOYMENT.md).

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

## Project status

RSpade is at **release candidate**. It is ready for production use, but expect some
glitches and some changes going forward. If something misbehaves, confuses or blocks
legitimate work, please report it:

- email **brian@hanson.xyz**
- **[rspade.org](https://rspade.org/)**
- the [issue tracker](https://github.com/rspade-framework/rspade/issues)

---

## License

MIT — see [LICENSE](LICENSE).

**The template application in `rsx/` carries one additional permission: you do
not have to keep the copyright notice.** It is there to be deleted and replaced,
so build your product out of it and license that product however you like,
proprietary included, with no attribution obligation. The framework in `system/`
stays plain MIT, notice and all.

Copyright (c) 2026 HansonXyz
