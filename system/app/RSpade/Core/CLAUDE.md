# Core Framework Systems

## Main_Abstract Middleware System

The framework provides application-wide middleware hooks via `Main_Abstract`:

### Implementation

1. **Create `/rsx/main.php`** extending `Main_Abstract` with three methods:
   - `init()` - Called once during bootstrap
   - `pre_dispatch(Request $request, array $params)` - Called before any route dispatch
   - `unhandled_route(Request $request, array $params)` - Called when no route matches

2. **Pre-dispatch flow**:
   - Main::pre_dispatch() called first
   - Controller::pre_dispatch() called second
   - If either returns non-null, dispatch halts with that response

3. **Controller/API Method Signatures**:
   - All controller methods: `function method_name(Request $request, array $params = [])`
   - All API methods: `function method_name(Request $request, array $params = [])`
   - `$params` contains GET query string parameters and URL-extracted route parameters (like `:id`)
   - POST data is accessed via `$request->post()` or `$request->input()`, NOT in `$params`

## Core JavaScript Classes

These classes are ALWAYS available - never check for their existence:
- `Rsx_Manifest` - Manifest management
- `Rsx_Storage` - Browser storage (session/local) with scoping
- `Rsx` - Core framework utilities
- All classes in `/app/RSpade/Core/Js/`

Use them directly without defensive coding:
```javascript
// ✅ GOOD
Rsx_Manifest.define(...)

// ❌ BAD  
if (typeof Rsx_Manifest !== 'undefined') {
    Rsx_Manifest.define(...)
}
```

## Dispatcher System

Maps HTTP requests to RSX controllers based on manifest data.

## Autoloader System

Provides path-agnostic class loading - classes are found by name, not path.

## Manifest System

Indexes all files in `/rsx/` for automatic discovery and loading.

## JQHTML Named Slots (v2.2.112+)

Child template syntax changed from `<Slot:slotname />` tags to `content('slotname')` function:
- Old: `<Slot:header />` (deprecated)
- New: `<%= content('header') %>` (v2.2.112+)
- Parent syntax: `<Slot:header>content</Slot:header>`

## JQHTML Slot-Based Template Inheritance (v2.2.108+)

When component template contains ONLY slots (no HTML), it automatically inherits parent class template structure:
- Enables abstract base components with customizable slots
- Child templates define slot-only files to extend parent templates
- Parent templates call slots: `<%= content('slotname', data) %>` (data passing supported)
- JavaScript class must extend parent: `class Child extends Parent`
- Slot names cannot be JavaScript reserved words (enforced by parser)

## JQHTML Define Tag Configuration

`<Define>` tag supports three attribute types:
- `extends="Parent"` - Explicit template inheritance
- `$property=value` - Set default this.args values (unquoted=JS expression, quoted=string)
- Regular HTML attributes (class, id, tag, data-*)
- Enables template-only components without JavaScript classes

## CLI Invocation Parameters vs Environment Variables

**OWNER RULING (do not reintroduce):** the parameters of a program invocation are
ALWAYS `--flags`, NEVER `KEY=VALUE` env prefixes. An environment variable describes
the ENVIRONMENT — a PATH-like, deployment-pertaining, `.env`-worthy fact about the
system the program runs in (`RSX_MODE`, `APP_URL`, `DB_*`, `APP_DEBUG`). It is
NOT a channel for telling a single invocation what to do. The owner has expressed
explicit displeasure at env-as-params; it must not reappear.

- WRONG: `RSX_FORCE_BUILD=1 RSX_PROD_BUILD_AUTHORIZED=1 php artisan rsx:prod:build`
- RIGHT: `php artisan rsx:prod:build --force --authorized`

**Passing state to a subprocess:** write the deployment fact to `.env` FIRST, then
invoke — the child boots reading `.env` (e.g. the prod-mode commands set `RSX_MODE`
before spawning `rsx:prod:build`, so no `RSX_MODE=` prefix is needed). Per-invocation
intent (force, authorization) rides as `--flags`; to reach a grandchild, forward the
flag explicitly (rsx:prod:build passes `--authorized` on to its `optimize:cache`
child) — never rely on env inheritance.

**Boot-time flag detection:** pre-handler code (Manifest boot, seal authorization)
runs before any command's `handle()`, so it cannot use `$this->option()`. The
sanctioned channel is argv inspection — `Manifest::__cli_has_flag('--flag')` (the same
technique `Manifest::_is_safe_command()` uses to read the command name). Programmatic
in-process authorization is `Rsx_Prod_Seal::authorize_process()`. Neither ever reads
an env var for invocation intent.