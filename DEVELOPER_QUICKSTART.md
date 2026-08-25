# RSpade Developer Quickstart

Everything you need to be productive in an existing RSpade project.

This is not a design guide and it is not a reference manual — it is the shape of
the framework, in one sitting. Read it once, then open the code: the goal is that
every file you land in afterwards is recognisable.

**Contents**

1. [What RSpade is](#1-what-rspade-is)
2. [What it takes off your plate](#2-what-it-takes-off-your-plate)
3. [Running the project](#3-running-the-project)
4. [Your editor](#4-your-editor)
5. [Working in the container](#5-working-in-the-container)
6. [How the code is organised](#6-how-the-code-is-organised)
7. [How a page happens](#7-how-a-page-happens)
8. [Actions and routing](#8-actions-and-routing)
9. [Components](#9-components)
10. [Styling](#10-styling)
11. [Models and the database](#11-models-and-the-database)
12. [Ajax endpoints](#12-ajax-endpoints)
13. [Forms](#13-forms)
14. [Modals](#14-modals)
15. [Authorization](#15-authorization)
16. [Live pages](#16-live-pages)
17. [Background work](#17-background-work)
18. [The rest of the toolbox](#18-the-rest-of-the-toolbox)
19. [Debugging and testing](#19-debugging-and-testing)
20. [Working with an AI assistant](#20-working-with-an-ai-assistant)
21. [Where to go next](#21-where-to-go-next)

---

## 1. What RSpade is

RSpade is a complete, opinionated implementation of the backend a B2B web
application needs, exposed to your code as ordinary functions and declarations.

Authentication, sessions, multi-tenancy, permissions, a client portal, file
storage with de-duplication and previews, background jobs, scheduling, email and
SMS queues, a websocket layer, an external API with its own docs and key
management, audit authorship, search indexing — all of it is written, tested and
wired. It is the roughly 80% of a serious application that is the same in every
serious application, and in RSpade you do not write it, configure it or assemble
it from packages.

What is left is the part that is actually *your* product:

| | |
|---|---|
| **A SPA client** | routes, layouts and pages that navigate without reloads |
| **Database models** | your tables, your rules, reachable from PHP and JavaScript alike |
| **A component language** | `.jqhtml` — markup, behaviour and style, composed on top of jQuery |
| **An SCSS pipeline** | component-scoped styles, no configuration |
| **An API layer** | declare an endpoint, it exists |
| **A task scheduler** | declare a job, it runs |

The thing that makes it feel different from a framework of libraries is that
**every part knows about every other part, and they refer to each other by
name.** A JavaScript action names the PHP controller that boots it. A form names
the controller and method that will save it. A component names another component
by its tag. A route is named by the class that serves it. There is no wiring file
in the middle translating between them, because there is nothing to translate:
the framework builds a manifest of everything in the project and resolves those
names for you, on both sides of the wire.

RSpade is built on Laravel, but it diverges from it substantially and
deliberately. Do not assume a Laravel pattern works here without checking —
`php artisan rsx:man framework_divergences` lists every difference.

---

## 2. What it takes off your plate

The best way to see the point is concretely. Every row below is a decision, a
config file, a package choice or a layer of glue that a conventional stack asks
you to own — and that RSpade has already answered.

| The usual job | In RSpade |
|---|---|
| Imports, autoload maps, namespace declarations | **None.** Classes are found by name through the manifest. `Client_Model` is `Client_Model` from anywhere, in either language. |
| A DI container, service providers, bindings | **None.** Classes are namespacing tools; most things are static. Nothing is injected because nothing is hidden. |
| A bundler, a watcher, a build step | **None.** Assets compile on demand. Save the file, refresh the browser, it is live in under a second. |
| A routes file that drifts from the controllers | Routes are declared **on the thing they serve** (`@route`, `#[Route]`). URLs are generated from the class name, so renaming a controller moves its URL with it. |
| An API client layer — fetch wrappers, axios instances, generated SDKs | A PHP method marked `#[Ajax_Endpoint]` is callable from JavaScript as `My_Controller.save({...})`. No route, no URL, no stub, no codegen. |
| A state-management library, reducers, context providers | Three named buckets on every component (`args`, `data`, `state`), each with one job and enforced boundaries. |
| Component registration, index files, import trees | Drop three files sharing a name into the tree and write `<Foo>`. The framework finds them. |
| CSS modules, CSS-in-JS, a class-name build step | Every component owns a `.scss` scoped to its own name. Auto-bundled. |
| DTOs, serializers, mappers, field aliasing | **One name at every layer** — database column, PHP property, JSON key and JavaScript field are the same string. Grep finds everything. |
| Auth middleware you can forget to attach | Every dispatchable surface **must** declare `#[Auth]`. Surfaces are closed by default; one without a gate fails the build rather than shipping open. |
| Websocket infrastructure, channel auth, client reconnect logic | `public static $realtime = true;` on a model, `this.subscribe(...)` in a component. Pages update themselves. |
| A queue service, workers, a scheduler daemon | `#[Task]` makes a method dispatchable; `#[Schedule('daily at 3am')]` makes it recurring. One cron line drives everything. |
| Two date libraries that disagree across the wire | `Rsx_Time` and `Rsx_Date`, identical API in PHP and JavaScript, ISO strings everywhere. No serialization surprises. |
| Form binding, dirty tracking, error placement | Give a form `$data` and matching field names. Values bind themselves; a validation error returned from the controller lands on the right field. |
| A utility library per language, chosen and versioned by you | One shared standard library. `is_email()`, `array_get()`, `debounce()`, `html()` exist in both languages with the same names. |
| Migration DSLs and rollback theatre | Forward-only raw SQL, with an automatic database snapshot before every run and an automatic rollback on failure. |

None of this is achieved by hiding things. It is achieved by **deciding** things:
one way to build a page, one way to load a record, one way to declare who may see
it. That is the trade. Application code stays small, uniform and greppable, and
the cost is that you do it RSpade's way.

---

## 3. Running the project

You need [Docker](https://docs.docker.com/get-docker/) and git. Nothing else — no
PHP, no Node, no MySQL on your machine.

```bash
git clone --recurse-submodules <your-project-url> my-app
cd my-app
bash system/app/RSpade/resource/docker/build.sh
docker compose up
```

Open **http://localhost:8080**. On a fresh database the application takes you
through its own setup screens — it asks for its own address, then for the account
you want to sign in with. There is no `.env` to write first and no install step.

Keep `docker compose up` in the foreground for the first run so you can see the
services come up. After that, `docker compose up -d` is fine.

A few things worth knowing before they surprise you:

- **`--recurse-submodules` is not optional.** The framework lives in `system/`,
  which is a git submodule. Without it you get an empty directory and nothing
  runs. Already cloned? `git submodule update --init --recursive`.
- **The clone is large**, because dependencies are committed. That is deliberate:
  it is why there is no install step and no network round-trip on startup.
- **Everything the application writes lives under `storage/`** — the database, the
  uploads, the logs. It is gitignored, and it is the whole of the state. Back the
  project up by copying the directory.
- **Add Claude Code to the image** with `build.sh --claude` if you intend to use
  it. See [section 20](#20-working-with-an-ai-assistant).

---

## 4. Your editor

Open the project folder in VS Code. It will offer you the workspace's recommended
extensions — **click Install.** That is the whole setup.

Two of them matter:

- **RSpade Framework Support** — go-to-definition across the manifest (including
  from JavaScript into PHP), formatting on save using the project's own
  formatter, framework attribute highlighting, lifecycle-method checking,
  project-wide refactors, and live git status.
- **JQHTML** — syntax highlighting for `.jqhtml` templates and Blade.

Neither is required; the framework builds and runs identically without them. They
matter because **RSpade's conventions are invisible to a stock PHP setup.** Your
editor will see a class referenced with no import, a PHP method called from
JavaScript, and a method that nothing appears to call — and will flag correct code
as wrong while offering no navigation. The extension asks the running RSpade
server the same questions the framework asks itself, so its answers are as
accurate as the framework's own resolution.

To install by hand instead, search the Extensions view for **JQHTML** and
**RSpade Framework Support**.

---

## 5. Working in the container

All the tooling lives inside the container, and in development mode `artisan`
checks and refuses to run anywhere else. The refusal prints the exact
`docker compose exec` line for whatever you typed, so it costs you one retry.

```bash
docker compose exec app bash        # a shell inside the container
```

From there:

```bash
php artisan rsx:man                 # every documentation topic there is
php artisan rsx:man spa             # ...and one of them
php artisan rsx:check               # code quality; run before you commit
php artisan rsx:test                # the test suite
php artisan rsx:debug /clients      # render a page headlessly, with real JS
php artisan migrate                 # apply schema changes
php artisan db:query "SELECT ..."   # query the database directly
php artisan rsx:health              # verify services, dependencies, environment
```

**`php artisan rsx:man` with no argument lists every topic.** The framework is
heavily documented from the inside, and that command is the front door. Do not
work from a memorised list of topics — ask it.

### Framework updates

```bash
php artisan rsx:framework:pull
```

That fetches the current framework release, applies it, and leaves your
application untouched. `system/` is framework property and is overwritten in its
entirety on every update — **treat it as read-only.** To customise a framework
class, copy it into `rsx/` under the same class name; the manifest will use yours
instead and every existing reference keeps resolving.

After a pull, check for anything the update needs from you by hand:

```bash
php artisan rsx:framework:upstream_changes
```

### Git

**Run git inside the container.**

```bash
docker compose exec app git status
docker compose exec app git commit -am "..."
```

Inside the container, `git` is the RSpade git proxy. It behaves like git in every
way you care about, with one difference that matters: **it keeps `system/` out of
your commits.** That directory is written by the framework updater and is rarely
in a settled state — commit it alongside your own work and those files fight the
next update. The proxy also puts the application into maintenance mode around
operations that rewrite the working tree, so nothing is reading `system/` while it
changes underneath.

Using git from your host is not forbidden; it just gives up both of those.

---

## 6. How the code is organised

Your code is in `rsx/`. The framework is in `system/`.

```
rsx/
    app/            application modules — this is where pages live
    models/         Eloquent models
    lib/            shared, non-visual libraries
    theme/          global SCSS, variables, and reusable components
    services/       background tasks and integrations
    handlers/       event handlers
    commands/       your own artisan commands
    emails/         Blade email templates
    portal/         the client portal (a parallel authenticated app)
    public/         static assets
    resource/       config overrides, migrations, your own docs and man pages
    tests/          your test suite
```

Under `app/`, each top-level directory is a **module**, and a module owns a
bundle, a bootstrap controller and a tree of features:

```
rsx/app/frontend/
    frontend_bundle.php              what this module's pages load
    Frontend_Spa_Controller.php      the #[SPA] bootstrap
    Frontend_Spa_Layout.jqhtml/.js   the persistent layout
    clients/                         a feature
        clients_controller.php       its Ajax endpoints
        list/    Clients_Index_Action.js  + .jqhtml
        view/    Clients_View_Action.js   + .jqhtml + .scss
        edit/    Clients_Edit_Action.js   + .jqhtml
```

### The important part: none of this is enforced by paths

**To the framework, the project is essentially pathless.** Classes are found by
name, not by location. Components are discovered wherever they are. Moving a
directory does not break a single reference, because nothing referenced it by
path in the first place.

So the layout above is **convention** — but it is convention worth keeping, for
two reasons. It means you can predict where a file is from its route and vice
versa. And it means files that belong together are together: markup, behaviour
and style for one screen sit in one directory, not scattered across four trees.

Two path rules the framework *does* enforce, and they are worth knowing:

- Any directory named **`resource/`** is ignored by the framework (except
  `rsx/resource/config/`).
- Any directory named **`public/`** is web-accessible and ignored by the
  framework.

Naming, however, *is* load-bearing and is checked by `rsx:check`: PHP methods and
variables are `underscore_case`, RSX classes are `Like_This_With_Underscores`,
files are `lowercase_with_underscores`, tables are `lowercase_plural`. Files that
share a prefix are one related set and are renamed together.

To scaffold rather than hand-place, the generators know the conventions:

```bash
php artisan rsx:app:module:create <module>
php artisan rsx:app:module:feature:create <module> <feature>
php artisan rsx:app:component:create --name=<name>_component --module=<module>
```

---

## 7. How a page happens

Worth holding in your head before reading any screen's code.

1. The browser requests `/clients`. One **PHP bootstrap controller** per module
   (marked `#[SPA]`) answers, and serves the module's shell and its bundle.
2. From then on the module is a **single-page application.** The framework's
   router matches the URL against the `@route` declarations on the module's
   JavaScript **Actions**, and dispatches to one of them.
3. The Action's **layout** persists across navigations. The Action renders into
   its `$sid="content"` element.
4. The Action is itself a component: it runs the lifecycle, fetches its data over
   Ajax, and renders its `.jqhtml` template — which is composed almost entirely of
   other components.
5. Every link inside the app is intercepted and dispatched client-side. No
   reloads.

Server-rendered Blade pages exist too, for login flows and SEO-critical public
pages. Everything authenticated is an SPA Action.

---

## 8. Actions and routing

An Action is a JavaScript class with decorators on top. This is the entire
declaration of a page:

```javascript
@route('/clients')
@layout('Frontend_Spa_Layout')
@spa('Frontend_Spa_Controller::index')
@title('Clients')
@auth('is_logged_in')
class Clients_Index_Action extends Spa_Action {
    async on_load() {
        this.data.clients = await Frontend_Clients_Controller.list();
    }
}
```

- **`@route`** may be repeated. An add/edit screen is one class with two:
  `@route('/clients/add')` and `@route('/clients/edit/:id')`.
- **Route and query parameters both land in `this.args`** — `this.args.id` above.
- **`@auth` is mandatory** on every routed Action. See
  [section 15](#15-authorization).
- **`@title`** is the whole title for a fixed-title page. For a title that
  depends on loaded data, override `page_title()` and start it with
  `await this.await_loaded()`.

### Links and navigation

**Never hardcode a URL.** `rsx:check` will flag it. Ask the framework:

```javascript
Rsx.Route('Clients_Index_Action')                  // /clients
Rsx.Route('Clients_Edit_Action', client.id)        // /clients/edit/42
Rsx.Route('Clients_Index_Action', {filter: 'new'}) // ?filter=new
```

```php
Rsx::Route('Frontend_Clients_Controller::view', $id)
```

The same spellings work in both languages. Because URLs are generated from class
names, renaming a class moves every link to it — there is no list of paths to
keep in agreement.

A plain `<a href="${Rsx.Route(...)}">` is all you need: **link interception is
automatic**, and only known SPA routes are intercepted, so an external link needs
no opt-out attribute. To navigate from code:

```javascript
Spa.dispatch('/clients');    // pushes history — a normal navigation
Spa.redirect('/clients');    // REPLACES history — for a redirect in on_load()
```

---

## 9. Components

A component is up to three co-located files sharing one name:

```
section.jqhtml      markup       (required)
section.js          behaviour    (optional)
section.scss        its look     (optional)
```

There is no registration step. Use it from a template as `<Section $title="Notes">`
or from JavaScript as `$el.component('Section', {title: 'Notes'})`.

### The template

`<Define:>` **is** the element — it is not a wrapper around one:

```jqhtml
<Define:Client_Card tag="div" class="Client_Card">
    <div class="Client_Card__name"><%= this.args.client.name %></div>
    <% if (this.args.client.is_active) { %>
        <span class="badge">Active</span>
    <% } %>
    <%= content() %>
</Define:Client_Card>
```

- `<%= value %>` interpolates **escaped**; `<%!= value %>` interpolates raw HTML.
- `<% ... %>` is plain JavaScript.
- `<%= content() %>` renders whatever the caller put inside the tag.
- `@click=this.handler` binds an event on a child element.
- `$sid="name"` tags a child element so the class can reach it as
  `this.$sid('name')`. Set it in the template only, never from JavaScript.

### The three state buckets

Never mix them. Each has one job, and the boundaries are enforced at runtime.

| Bucket | Holds | Writable in |
|---|---|---|
| **`this.args`** | arguments passed in | everywhere *except* `on_load()` |
| **`this.data`** | Ajax-loaded data; the source of truth for display | `on_create()` and `on_load()` only |
| **`this.state`** | arbitrary UI state — toggles, selections, editor buffers | anywhere |

**Render from `this.data` directly.** Copy it into `this.state` only when the
user is about to edit it and you will save it later; then save through a
controller and `reload()` to discard the buffer.

### The lifecycle

```javascript
class Clients_View_Action extends Spa_Action {
    on_create() {            // 1. sync defaults, so the first paint has shape
        this.data.client = {name: ''};
        this.data.loading = true;
    }

    async on_load() {        // 2. fetch — the only async data step
        this.data.client = await Client_Model.fetch(this.args.id);
        this.data.loading = false;
    }

    on_render() {}           // fires after each render, before children are ready

    on_ready() {}            // 3. children guaranteed ready; safe to touch them

    on_stop() {}             // teardown
}
```

The order is `on_create()` → render → `on_render()` → `on_load()` →
`on_loaded()` → `on_ready()`. If `on_load()` changed `this.data`, the component
renders a second time — defaults first, populated second — which is why
`on_create()` sets shapes rather than leaving fields undefined.

**`on_render()` may fire more than once**, so any handler you bind there must be
idempotent (`.off('click.mycmp').on('click.mycmp', ...)`).

### The `on_load()` gotcha — and why it exists

Inside `on_load()` these all throw at runtime:

```javascript
async on_load() {
    this.$sid('row').text();     // NO — DOM access
    this.state.count = 5;        // NO — writing state
    this.args.filter = 'new';    // NO — writing args
    this.render();               // NO — the re-render is automatic
}
```

`on_load()` may read `this.args` and write `this.data`. That is all.

This looks restrictive until you see what it buys. **`on_load()` is re-run** —
by `reload()`, by `refresh()`, and by every realtime notification — and the
framework's job is to make the result of re-running it indistinguishable from
loading fresh. That only holds if `on_load()` is a pure function from `args` to
`data`. If it also poked the DOM, the render that follows would wipe those pokes.
If it wrote `this.state`, the user's editor buffer would be clobbered on every
background refresh. If it mutated `this.args`, the next reload would ask a
different question than the URL says it should.

So the restriction is not bureaucracy — it is the thing that lets you call
`reload()` from anywhere and trust it.

Two consequences to internalise:

- **Ajax belongs in `on_load()`.** Never refetch into `this.data` from an event
  handler; change `this.args` and call `reload()` instead.
- **Set your loaded flag last.** `this.data.loaded = true` at the end of
  `on_load()` means a half-populated `this.data` can never paint as if it were
  complete.

### Re-rendering

| Call | Does |
|---|---|
| `reload()` | re-runs `on_load()` and repaints unconditionally — for **user** actions |
| `refresh()` | re-runs `on_load()` and repaints **only if `this.data` changed** — for **server** notifications |
| `render()` | re-executes the template only |
| `stop()` | destroys the component |

`reload()` does *not* re-run `on_create()`.

### Composition

An Action's template should read as a list of component invocations, not as a
pile of `<div class>`:

```jqhtml
<Define:Clients_Index_Action>
<Page_Scaffold>
    <Slot:main>
        <Clients_DataGrid />
    </Slot:main>
</Page_Scaffold>
</Define:Clients_Index_Action>
```

If you find yourself copy-pasting markup, extract a component. Browse
`rsx/theme/components/` before writing one — the vocabulary for cards, sections,
tabs, grids, page scaffolds and form widgets is already there.

---

## 10. Styling

Every styled element is a component with its own SCSS file, and that file owns
the component's entire look. There are no `<style>` tags and no inline styles.

**The wrapping class must match the component name exactly, and BEM children use
that PascalCase name as their prefix:**

```scss
.Client_Card {
    padding: $spacing-3;

    &__name {
        font-weight: $font-weight-semibold;
    }

    &--compact {
        padding: $spacing-1;
    }
}
```

Kebab-case does not match the compiled CSS, and the element silently gets no
styles. This is the single most common styling mistake.

**Check `rsx/theme/variables.scss` before writing anything new** — spacing,
colours, weights and borders are already named.

**Responsive breakpoints are semantic, and Bootstrap's are replaced.**
`.col-md-6` and `.d-lg-none` do not work. Use the RSpade tiers:

```scss
@include mobile  { ... }   // 0–1023
@include desktop { ... }   // 1024+
@include tablet  { ... }   // and phone, phone-sm, phone-lg, desktop-sm/md/lg/xl
```

with matching utility classes (`.col-tablet-6`, `.d-desktop-block`,
`.mobile-only`) and `Responsive.is_mobile()` in JavaScript.

A page or Action's own `.scss` should be nearly empty. A page's look lives in the
components it composes.

---

## 11. Models and the database

Models are Eloquent, with RSpade's rules on top.

```php
class Client_Model extends Rsx_Site_Model_Abstract
{
    public static $realtime = true;
    public static $unbounded = true;
}
```

Four rules that will catch you if you arrive from Laravel:

- **No mass assignment.** Assign every field explicitly, so what a request can
  write is visible in the code.
- **No eager loading.** `->with()` throws. Load what you need, when you need it.
- **Datetimes are ISO strings, not Carbon.** `$model->created_at` is a string;
  never call `->format()` on it. Pass it to `Rsx_Time::format_datetime()`.
- **No field aliasing, anywhere.** The column, the PHP property, the JSON key and
  the JavaScript field are all the same string.

### Enums

Enums are integer columns mapped at the model to constants, labels and any extra
properties you want:

```php
public static $enums = [
    'status_id' => [
        self::STATUS_ACTIVE   => ['label' => 'Active'],
        self::STATUS_INACTIVE => ['label' => 'Inactive'],
    ],
];
```

You then get magic properties and generated helpers on both sides:

```php
$client->status_id__label;                  // "Active"
```
```javascript
Client_Model.STATUS_ACTIVE
Client_Model.status_id__enum_select()       // [{value, label}] for a dropdown
```

Run `php artisan rsx:constants:regenerate` after changing one.

### Reading a record from JavaScript

JavaScript reaches the ORM through **one framework endpoint per model**, by
explicit opt-in. The model declares a `fetch()`:

```php
#[Auth('is_logged_in')]                       // usually declared once, on the class
class Client_Model extends Rsx_Site_Model_Abstract
{
    #[Ajax_Endpoint_Model_Fetch]
    public static function fetch($id)
    {
        $client = static::find($id);
        if (!$client) return false;           // false reads as "not found"
        return $client->to_fetch_array();
    }
}
```

and JavaScript calls it directly:

```javascript
const client = await Client_Model.fetch(1);           // throws if not found
const maybe  = await Client_Model.fetch_or_null(999); // null if not found
const owner  = await client.owner();                  // lazy relationship
```

Two things to understand about `fetch()`:

- **It is a security boundary, not a formatting layer.** Never rename a field or
  format a date in it. The `#[Auth]` gate runs before any model code, and a
  denial returns the same generic "not found" as a missing row, so nobody can
  enumerate ids. Record-level rules — ownership, membership, record state —
  belong in the body.
- **A separate controller endpoint that fetches one record is an anti-pattern.**
  If a model has no `fetch()`, that is the thing to add.

### Bounded result sets

A function does the whole job. `get_sessions_for_user()` returns *all* of a
user's sessions — if it returned the first hundred it would be named
`get_first_100_sessions_for_user()`. When a set may be large, iterate it rather
than truncating it:

```php
foreach (Client_Model::where('site_id', $id)->result_set() as $client) { ... }
```

`result_set()` walks every record, one keyset page at a time, with a `foreach`
that looks entirely ordinary. A bare `LIMIT` that silently truncates is a bug
unless the caller asked for N.

### Migrations

Forward-only, raw SQL, no rollbacks — deterministic transformations against known
state.

```bash
php artisan make:migration:safe add_portal_flag_to_clients
php artisan migrate
```

`migrate` snapshots the database before the run and rolls the whole run back
automatically if a step fails. **Run it with its full output** — never pipe it
through `tail` or grep it; the snapshot narrative is the diagnostic.

Write the exact transformation. No `IF EXISTS`, no `information_schema` probing,
no fallbacks — failures should fail loudly, and the snapshot is what handles
recovery.

Audit columns (`created_by_id`/`created_by_type` and friends) are added to every
table by the framework and stamped automatically on save. Never declare them in a
migration.

---

## 12. Ajax endpoints

A static method on a controller, marked `#[Ajax_Endpoint]` with its mandatory
`#[Auth]`, is callable from JavaScript under the controller's own name. There is
no route to add and no client stub to write.

```php
#[Auth('is_logged_in')]
class Frontend_Clients_Controller extends Rsx_Controller_Abstract
{
    #[Ajax_Endpoint]
    public static function client_contacts(Request $request, array $params = [])
    {
        $client_id = $params['id'] ?? null;
        if (!$client_id) {
            return response_error(Ajax::ERROR_VALIDATION, 'Client ID is required');
        }
        return ['contacts' => /* ... */];
    }
}
```

```javascript
const result = await Frontend_Clients_Controller.client_contacts({id: 42});
```

**`$.ajax()` is overridden to throw.** Always call `Controller.method()`.

**Every Ajax response is HTTP 200**, success and failure alike. The outcome
travels in the envelope, and the JavaScript promise resolves or rejects on it.
Errors are returned, not thrown:

```php
return response_error(Ajax::ERROR_NOT_FOUND, 'Client not found');
```

with `ERROR_VALIDATION`, `ERROR_NOT_FOUND`, `ERROR_UNAUTHORIZED`,
`ERROR_AUTH_REQUIRED`, `ERROR_FATAL` and `ERROR_GENERIC` available.

**Unhandled errors already log and already surface to the user**, so catch only
what you specifically handle. Blanket try/catch is a bug here, not caution.

You can call an endpoint from the command line, which is invaluable when
debugging:

```bash
php artisan rsx:ajax Frontend_Clients_Controller client_contacts --args='{"id":42}' --user=1
```

---

## 13. Forms

A form is a component wrapping field components. Values bind by name; you never
write the binding.

```jqhtml
<Rsx_Form $data="<%= JSON.stringify(this.data.form_data) %>"
          $controller="Frontend_Clients_Controller" $method="save">

    <% if (this.data.is_edit) { %><Hidden_Input $name="id" /><% } %>

    <Section $title="Basic Information">
        <Form_Field $label="Company Name" $required=true>
            <Text_Input $name="name" $max_length=Client_Model.field_length('name') />
        </Form_Field>
        <Form_Field $label="Email">
            <Text_Input $name="email" $type="email"
                        $max_length=Client_Model.field_length('email') />
        </Form_Field>
    </Section>
</Rsx_Form>
```

The rules are short:

- **`$data` present means edit, absent means add.** Same form, both jobs.
- **`$name` goes on the input, never on `Form_Field`.**
- **You never set `$value`** — the form sets it from `$data`.
- **`$max_length` is required on `Text_Input`.** `Model.field_length('col')` reads
  it from the schema so it cannot drift.

The Action loads; the controller saves. `on_create()` sets the defaults,
`on_load()` fetches for edit mode, and the controller's `save()` validates and
persists. Returning field errors places them automatically:

```php
return response_error(Ajax::ERROR_VALIDATION, [
    'name'  => 'Company name is required',
    'email' => 'Please enter a valid email address',
]);
```

**Open an existing form before writing a new one.** Because binding is automatic
rather than spelled out in the markup, copying a comparable form is faster than
reasoning the wiring out. `rsx/app/frontend/clients/edit/` is a good one.

---

## 14. Modals

All modals are async, and they read as ordinary code:

```javascript
const ok = await Modal.confirm('Delete Client',
                               'Are you sure? This can be undone.',
                               'Delete', 'Cancel');
if (!ok) return;

await Modal.alert('No Contacts', 'Add a contact first.');
const name = await Modal.prompt('Rename', 'New name:');
```

**Basic dialogs take positional arguments, and they overload**: one argument is
the body; two or more make the first the **title** and the second the body. This
is easy to get backwards — read it twice.

For a form in a modal, hand it a component:

```javascript
const result = await Modal.form({
    title: 'Add User',
    component: 'Add_User_Modal',
    component_args: {client_id: this.args.id},
    on_submit: async (vals) => {
        const r = await User_Controller.create(vals);
        return r.ok ? r : false;      // false keeps the modal open
    },
});
```

---

## 15. Authorization

**Every dispatchable surface must declare `#[Auth]`** — routes, SPA bootstraps,
Ajax endpoints, model fetches, API endpoints, and `@route` Actions. Surfaces are
**closed by default**: one with no gate does not deploy, and the manifest build
fails with a per-violation worklist. There is no off switch, and a public surface
says so out loud with `#[Auth('public')]`.

```php
#[Auth('is_logged_in')]                              // class-level covers all methods
class Frontend_Clients_Controller extends Rsx_Controller_Abstract
{
    #[Ajax_Endpoint]
    #[Auth('can_manage_clients')]                    // method-level is ADDITIVE
    public static function save(Request $request, array $params = []) { }
}
```

Multiple names in one attribute are ANDed. Method-level gates narrow; they never
widen.

**Gate versus record.** `#[Auth]` answers *"may this user use this surface at
all"*. The function body answers *"which records may they touch"*. A rule whose
answer depends on which row it is, is never a gate — that belongs in the body,
alongside `require_permission()` and `Session::is_logged_in()`.

The names in the gate are `#[Auth_Check]` methods on your `Permission` class,
which usually consult roles and per-user ACL grants but may consider anything.

Because gates are declared on the destination, the framework can answer whether a
link should be visible at all:

```javascript
Permission.can_access('Settings_User_Management_Index_Action')   // true / false
```
```php
Permission::can_access('Frontend_Clients_Controller::save');
```

A sidebar built this way cannot lie.

Sessions are always reached through the `Session` facade — never Laravel Auth,
never `$_SESSION`:

```php
Session::is_logged_in();
Session::get_user();       // the site User_Model
Session::get_site_id();
```

---

## 16. Live pages

Pages update themselves when data changes elsewhere. Mark the model:

```php
public static $realtime = true;
```

and subscribe in the component:

```javascript
on_create() {
    this.subscribe(Client_Model, this.args.id, () => this.refresh());
}
```

That is the whole feature. Every committed `save()` or `delete()` publishes a
frame; the browser receives it and your callback runs.

Three things make this safe and predictable:

- **Frames are notifications, never data.** A frame says "something changed, go
  look"; your callback refetches through ordinary gated Ajax. Confidential data
  never rides the socket, so the system is safe by construction.
- **Subscribe in `on_create()`.** That gates the first load on the subscription
  being live, which gives you exactly one fetch with no race.
- **Use `refresh()`, not `reload()`.** `refresh()` repaints only if the data
  actually changed, so a no-op notification does not destroy child DOM or a
  half-typed form. Rule of thumb: the *server* saying something changed →
  `refresh()`; the *user* doing something → `reload()`.

Every resubscribe fires your callback once as a resync rather than replaying
missed messages, so **write every callback as an idempotent refetch** and it will
always be correct.

---

## 17. Background work

A background job is a public static method on a service class, marked `#[Task]`:

```php
class Seeder_Service extends Rsx_Service_Abstract
{
    #[Task('Seed test clients into the database')]
    #[Schedule('daily at 3am')]
    public static function seed_clients(Task_Instance $task, array $params = [])
    {
        $task->log('info', 'starting');
    }
}
```

```php
$id = Task::dispatch('Seeder_Service', 'seed_clients', ['count' => 20]);
$status = Task::status($id);
```

`dispatch()` enqueues the job, spawns a detached worker when it is due, and hands
back a pollable id. One cron entry drives the whole system.

**Tasks run concurrently and unguarded.** There is no automatic application lock:
several workers may run at once, and a task shares tables with web requests and
other tasks. `#[Exclusive]` and `#[Debounce(seconds)]` guard one task against
*itself*, not a shared table against other writers — for that, take a lock:

```php
$token = RsxLocks::named_write_lock('client_import');
try   { /* critical section */ }
finally { RsxLocks::release_lock($token); }
```

Always release in a `finally`. A lock is held until you release it or your process
dies — there is no lease and no TTL.

---

## 18. The rest of the toolbox

Short introductions to the subsystems you will meet. Each has a man page.

**Files.** Upload through `Ajax.upload(form_data)`, then claim the file onto a
record. Identical bytes are stored once, and rendered, previewed and
text-extracted once. Display an image or document thumbnail with
`<Attachment_Thumbnail $attachment_id=... />` and nothing else — never build a
thumbnail URL. Office documents are converted to PDF by a background worker, so
their thumbnail appears a few seconds after upload and swaps itself in over
realtime. `rsx:man file_upload`, `rsx:man documents`.

**Email and SMS.** Everything is queued, never sent inline, so a slow mail host
can never slow a user's action. `Rsx_Mail::send($to, $subject, $template, $data,
$category)`; categories decide whether unsubscribes apply. Templates are Blade
files in `rsx/emails/`. `rsx:man email`.

**Events.** Attribute-based hooks with no registration: a public static method in
`rsx/handlers/` marked `#[OnEvent('some.event')]`. Four kinds — fire-and-forget
actions, filters that transform a value, gates that can deny, and resolvers where
the first non-null answer wins. `rsx:man event_hooks`.

**The external API.** Static methods marked `#[Api_Endpoint]` on a class
extending `Rsx_Api_Controller_Abstract`, with a path under `/api/vN/`. Auth is
bearer-token only, which produces a cookie-less headless session — so site
scoping applies automatically and you never hand-write a `site_id` clause. Live
docs and a tester at `/apidocs`. `rsx:man external_api`.

**The client portal.** A second authenticated experience for your customers,
running parallel to the staff app with its own dispatcher, routing and permission
facade, in `rsx/portal/`. One session per browser, shared with the staff app.
When app code has to fork, fork on `Rsx_Portal::is_portal_request()` — never on
`is_logged_in()`, because identity is not experience. `rsx:man portal`.

**Application modes.** `development` (what you are in: everything compiles on
demand) and the two sealed builds, `debug` and `production`, which are compiled
once by an explicit command and are then immutable. `rsx:man app_mode`.

**Maintenance mode.** `php artisan rsx:maintenance:enable --reason="..."` takes
the app down and `:disable` brings it back. The gate stops automation, not humans
— `migrate` still runs. `rsx:man maintenance_mode`.

**Configuration.** `.env` holds deployment-specific values only — credentials,
hostnames, keys — and `.env.README` explains every one of them. Application
*behaviour* belongs in `rsx/resource/config/rsx.php`, which is version-controlled
and merges over the framework defaults. `APP_URL` is the single source of the
application's hostname; everything else derives from it.

---

## 19. Debugging and testing

### rsx:debug

The tool you will use most. It renders a route through a real headless browser
with full JavaScript execution, and reports everything that happened:

```bash
php artisan rsx:debug /clients --user=1
php artisan rsx:debug /clients/view/42 --user=admin@example.com --console --full
php artisan rsx:debug /dashboard --portal --portal-user=5
```

It gives you the HTTP response, the rendered DOM, console output including
`console_debug()` channels, every XHR the page made, and the application log —
without you clicking anything. `--user` authenticates as anyone, in development
only.

### Printing things

```php
rsx_dump_die($value, $other);          // dump with location and stack trace
console_debug('CHANNEL', $value);      // channel-filtered; exists in both languages
```

`console_debug()` is stripped in production builds, so it is safe to leave.

### The suite

```bash
php artisan rsx:test                       # everything
php artisan rsx:test --group=clients       # one concern
php artisan rsx:test --filter=save         # one test
php artisan rsx:check                      # code quality — run before committing
```

Tests run against a **separate database**, and the runner refuses to start if
that database is the same as your development one.

Every finding from `rsx:check` carries its own remediation text explaining what,
why and how. Trust it — the rules exist because each one has a scar behind it.

---

## 20. Working with an AI assistant

RSpade works with any agentic tooling, or none. But **Claude Code is the official
assistant for RSpade**, and the project is built for it.

The framework carries its own instructions for an assistant: `CLAUDE.md` files
throughout the tree, a plugin of skills covering every subsystem — SPA, jqhtml,
forms, modals, migrations, auth gates, realtime, tasks, the portal, files,
testing, and thirty more — plus the full man page library and the reference
application as a worked example. That is several hundred pages of conventions,
mandates and gotcha catalogs, most of it written down precisely because it is
non-obvious.

The practical result is that an assistant working in this project writes
*RSpade* code rather than generic Laravel and React, which on an opinionated
framework is the difference between help and hindrance. It can answer most
questions about the framework without guessing, and it knows where the landmines
are because they are documented next to the rules that exist to avoid them.

It can also **check its own work.** `php artisan rsx:debug` renders any page
headlessly with real JavaScript and reports the console, the network activity and
the DOM — so an assistant can make a change, load the affected screen, see
whether it actually worked, and iterate. Combined with `rsx:check` and
`rsx:test`, that closes the loop without a human in it for the routine parts.

Build the image with it included, then start a session inside the container:

```bash
bash system/app/RSpade/resource/docker/build.sh --claude
docker compose exec app claude
```

Your login, settings and history live in `storage/.claude` in the project, so
they survive rebuilds. That directory is gitignored.

Run it inside the container rather than on your host — that is where the project,
the tooling, the documentation and the RSpade git proxy all are.

---

## 21. Where to go next

**The man pages are the reference tier**, and they ship with the framework:

```bash
php artisan rsx:man                 # lists every topic
php artisan rsx:man jqhtml
php artisan rsx:man spa
php artisan rsx:man model_fetch
php artisan rsx:man framework_divergences
```

They are terse, complete and written for someone who already knows what they are
looking for — which, after this document, you do.

**The template application is the other reference.** Every screen in it is built
the way the framework intends. When you are unsure how something should look,
open the equivalent screen in `rsx/app/frontend/` and copy its shape.

**Full online documentation is coming to [rspade.org](https://rspade.org/).**

---

## A note on why this exists

Once upon a time there was a language and an IDE called Visual Basic 6. You
opened it, you dragged a button onto a form, you double-clicked it, and you wrote
the line of code that ran when someone clicked it. There was no build pipeline to
choose, no dependency graph to reason about, no toolchain to keep alive. The
distance between having an idea and seeing it run was about four seconds, and a
generation of developers learned to build real software that way — because it was
easy, and because it was fun.

Web development has spent twenty years moving in the other direction. The modern
stack is a remarkable engineering achievement and an exhausting place to build a
product: a dozen libraries that must agree with each other, a build system that
must be maintained, a type layer, a state layer, a data-fetching layer, a routing
layer, and a config file for each — most of it in service of decisions that have
one sensible answer for the kind of application you are actually writing.

RSpade is an attempt to give that back. Not by being smaller or less capable, but
by making all of those decisions once, correctly, and then getting out of the
way. Edit a file, refresh the browser. Name a thing and use it. Declare what you
want and let the framework wire it.

The point of the simplicity is not the simplicity. It is what you can attempt
once the overhead is gone — because the ceiling on what one developer can build
has never been about how fast they type. It has been about how much of the
machine they have to hold in their head at once.

---

<sub>RSpade — Rapid Single Page Application Development Environment.
Provided by © 2026 [HansonXyz](https://github.com/hansonxyz) · MIT</sub>
