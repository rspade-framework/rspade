---
name: framework-dependencies
description: Adding or changing a dependency in the RSpade monorepo - installing inside system/, committing vendor and node_modules, when and how to add a package to the exposed_composer/exposed_npm lists, the standing commitment that exposure creates and the Category 2 upstream_changes obligation when one breaks or retires, and how the replace map is regenerated downstream. Use when the framework or the template app needs a new third-party package, or when retiring/major-bumping an exposed one.
---

# Framework dependencies (monorepo)

**Framework packages are installed with plain `composer require` / `npm install` run INSIDE `system/`.** No wrapper, no artisan command - those exist for downstream apps.

```bash
cd /var/www/html/system
composer require league/csv
npm install some-package
```

`system/vendor` and `system/node_modules` are **committed** and ship to downstream apps via `rsx:framework:pull`. Commit them with the change, in the same snapshot as everything else - a package installed but not committed is a package downstream will never receive.

**This is the ONLY place you add a package this template needs.** The `/rsx/` starter template in this repo keeps **ZERO application-layer dependencies** by rule: anything template code needs goes framework-level. The root-level `rsx:composer`/`rsx:npm` layer exists for downstream applications, not for us.

**Precedence**: the framework wins every name collision (composer autoloader order for PHP; `system/node_modules` resolving before the project root for JS). Downstream cannot shadow a framework package, which is why the downstream wrappers record rather than install.

---

## Exposing a package to applications

A framework dependency is INTERNAL by default: downstream `rsx:composer require symfony/console` is refused with "an internal framework dependency, not part of the supported surface". To make a package part of the supported surface, add it to the exposure lists in `system/config/rsx.php`:

```php
'dependencies' => [
    'exposed_composer' => [
        'laravel/framework',
        'guzzlehttp/guzzle',
        'giggsey/libphonenumber-for-php',
        'ezyang/htmlpurifier',
        'sokil/php-isocodes',
        'nikic/php-parser',
    ],
    'exposed_npm' => [
        'dompurify',
        'google-libphonenumber',
    ],
],
```

Downstream, an exposed package is **recorded** (name + the framework version at the time) into the app's root manifest instead of installing a duplicate, and the app may `use` it freely.

### Exposure is a standing commitment - decide accordingly

**An exposed package is expected to remain available indefinitely at a compatible major version.** Default tooling exists forever. Expose a package because applications genuinely need it (an HTTP client, a sanitizer), not because it happens to be installed.

Before adding a name to either list, ask:
- Is this the API we want applications writing against for years?
- Are we willing to carry it - and to author a migration document if it ever has to change?
- Is a thin framework facade the better answer, so the dependency stays swappable? (Prefer this when the package is an implementation detail rather than an API applications want directly.)

Adding a name is cheap; removing one is a downstream migration.

---

## When an exposed package breaks or retires

**It is never silent.** Retiring an exposed package, or moving it across a major version boundary, ships with a **Category 2 `upstream_changes` document** (`/system/` core behavior / API-contract change) - authored per the charter in `system/app/RSpade/upstream_changes/CLAUDE.md`, which defines the two document categories, the naming convention and the required structure.

The downstream side then works like this automatically, and you should know it when writing the document:

- The post-update reconciler runs after every `rsx:framework:pull` (purely local, no network) and cross-checks the app's recordings against what the framework now ships.
- **removed** produces a notice naming the exact adopt command (`rsx:composer require <pkg>`), which reclassifies the package as a genuine app-layer install.
- **major_change** produces a notice naming the version move and the re-record command.
- Both are FINDINGS, never a failing exit code. Your document is what tells the developer what to actually change in their code; the reconciler only tells them it happened.

So the document must carry: what changed, how to find affected call sites, and the concrete conversion - not a changelog line. A change that self-corrects (a fatal, a build failure) needs no document at all; an exposure change does not self-correct, which is exactly why this one is mandatory.

---

## The replace map

The DOWNSTREAM root `composer.json` carries a generated `"replace"` block listing every package in `system/vendor/composer/installed.json` at its exact version - composer's documented way of telling the solver "already supplied". It is what lets an app-layer `require` resolve against our pins instead of double-installing.

It is **machine-generated and never hand-edited**, and it is regenerated for the app by the wrappers before every real install/update and by the reconciler after every pull. **Nothing in this repo edits it**; changing the framework's installed set is all the input it needs. If a downstream solve fails against our pins, the cause is a version conflict to resolve here (or an exposure request), never a map edit.

---

## Checklist for a framework dependency change

1. `cd system && composer require` / `npm install` the package.
2. Commit `system/composer.json`, `system/composer.lock`, `system/vendor/` (and the npm equivalents) as part of the working-directory snapshot.
3. If applications should be able to depend on it directly, add it to `exposed_composer` / `exposed_npm` - and accept the standing commitment.
4. If you are REMOVING or major-bumping an already-exposed package, author the Category 2 `upstream_changes` document in the same change.
5. If it is a JS package the browser must load, remember bundles pull it in through an Asset Bundle's `'npm'` key - identical for framework and app layers (`rsx:man npm`).

---

Details: `php artisan rsx:man dependencies`, `rsx:man npm`, `rsx:man upstream_changes`. Authoring charter: `system/app/RSpade/upstream_changes/CLAUDE.md`.
