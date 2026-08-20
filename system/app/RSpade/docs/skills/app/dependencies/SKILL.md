---
name: dependencies
description: Adding PHP and JS packages to a downstream RSpade application - rsx:composer and rsx:npm, why you never run composer/npm inside system/, framework-provided packages that record instead of installing, the provides/adopt/re-record flow, reading the post-update reconciler's notices, getting an npm package into the browser through an Asset Bundle, lockfile and commit guidance, and the machine-generated replace map. Use when installing a package, hitting a refusal from the wrappers, or reacting to a reconciler notice after a framework update.
---

# Managing application dependencies

An RSpade project is **two layers**: the framework core in `system/` (vendored tracked files maintained by `rsx:framework:pull`) and your application at the project root. Dependencies follow the same split.

**NEVER run `composer` or `npm` inside `system/`.** That directory is read-only to you, exactly like `node_modules` or the Linux kernel - the next framework pull overwrites it, and anything you installed there vanishes. Your packages go at the project root:

```
composer.json  ->  /vendor          managed by  php artisan rsx:composer
package.json   ->  /node_modules    managed by  php artisan rsx:npm
```

**The framework always wins a name collision** (composer autoloader order for PHP; `system/node_modules` resolving first for JS). That is why the wrappers refuse to install a duplicate of something the framework already ships - a second physical copy at your root would never be the one that loads.

---

## Adding a package

```bash
php artisan rsx:composer require league/csv
php artisan rsx:npm install js-cookie
```

Both wrappers pass any other command straight through to the real tool at the project root (`composer show/why/outdated`, `npm ls/view/outdated`, ...). Only the mutating verbs are intercepted, plus the RSpade-specific `provides`.

A `require`/`install` is classified three ways:

1. **On the framework's exposed surface** -> **recorded**, nothing installed:
   ```
   [OK] 'guzzlehttp/guzzle' provided by framework at 7.15.2 - recorded (nothing installed).
   ```
2. **Installed in the framework tree but NOT exposed** (an internal framework dependency) -> **refused, no side effects**, with guidance to request exposure:
   ```
   'symfony/console' is an internal framework dependency, not part of the supported surface.
   Open a request to the framework maintainer to expose it. (No changes made.)
   ```
3. **Neither** -> a genuine app-layer package, installed for real (after the replace map is regenerated so composer solves against the framework's pins). `use League\Csv\Reader;` then works from `/rsx/` with zero `system/` changes.

Naming several packages at once validates ALL of them first: if any one is refused, nothing is recorded and nothing is installed.

**Version constraints against an exposed package hard-fail** rather than quietly installing a second copy:

```
'guzzlehttp/guzzle' is provided by the framework at 7.15.2, which does not satisfy your requested constraint '^6.0'.
Require it without a version constraint to accept the framework's version, or open a request to the framework maintainer.
```

---

## Provided-by-framework: recording, and why

```bash
php artisan rsx:composer provides
php artisan rsx:npm provides
```

Lists the framework's exposed packages, the version it currently ships, and whether your app has recorded each.

**Recording** writes a declaration - package name + the framework version at the time - into your root manifest (`extra.rsx.provided` in `composer.json`, `rsx.provided` in `package.json`). Nothing is installed; the framework's copy already loads. **It is a declared coupling**: "my application relies on this framework-provided package" - which is exactly what the reconciler reads after an update. Recording is therefore worth doing even though your code works without it.

Removing a recording is the same verb as removing a package:

```bash
php artisan rsx:composer remove guzzlehttp/guzzle   # drops the recording
php artisan rsx:npm uninstall dompurify             # drops the recording
```

(Applied to a real app-layer package, both delegate to the real tool.)

**The forever-commitment posture**: exposing a package is a standing commitment by the framework - an exposed package is expected to remain available indefinitely at a compatible major version. If a breaking change or retirement ever does happen it is never silent: it ships with a Category 2 `upstream_changes` document (`rsx:man upstream_changes`) and the reconciler surfaces it against your recordings.

---

## After a framework update: reading the reconciler

Every successful `rsx:framework:pull` runs the reconciler at the end. It is **purely local, no network**, so a pull stays offline-capable, and **it never fails your build** - both findings below are notices.

1. **Replace map regenerated** against the possibly-updated framework set. Routine information, not a warning:
   ```
   Framework dependency baseline refreshed (app-layer composer replace map regenerated).
   ```
2. **Your recordings cross-checked** against what the framework now ships:

   *removed* - the framework no longer provides something you recorded:
   ```
   The framework no longer provides 'sokil/php-isocodes' (you recorded it at 4.2.1).
   Run: php artisan rsx:composer require sokil/php-isocodes  to adopt it into your application layer.
   ```
   Run exactly that command: it now classifies as a genuine app-layer package and installs for real.

   *major_change* - a recorded package crossed a major version boundary:
   ```
   Framework-provided 'guzzlehttp/guzzle' moved 7.15.2 -> 8.0.0 (major version change).
   Review your usage; see pending upstream_changes documents.
   Re-record with: php artisan rsx:composer require guzzlehttp/guzzle
   ```
   Review your call sites first, then re-record so the declaration names the new version.

npm recordings are checked identically; the remedy command is `rsx:npm install <package>`. **When everything is clean and the map did not change, the reconciler is completely silent.**

---

## Getting a JS package into the browser

Installing is not shipping. A package reaches the browser through an **Asset Bundle's `'npm'` key**, and this is **identical for app-layer and framework packages** - esbuild resolves `system/node_modules` first, then the project root, so the bundle mechanism does not care which layer a package lives in.

```php
// an Asset Bundle definition
'npm' => [
    'js-cookie',
    'chart.js',
],
```

```javascript
import Cookies from 'js-cookie';
```

Full bundle mechanics: `rsx:man npm`, plus `rspade:bundles`.

---

## Version control

**Always commit** your root manifests and lock files - they ARE your application's dependency layer, and the wrappers and reconciler read and rewrite them:

```
composer.json   composer.lock   package.json   package-lock.json
```

**Committing `/vendor` and `/node_modules` is RECOMMENDED** - consistent with the framework's own commit-everything philosophy (it commits `system/vendor` and `system/node_modules` for exactly this reason: reproducible, network-independent deployments) - but it is your team's call.

`php artisan rsx:composer install` / `rsx:npm install` (no package arguments) materializes the root trees from the committed lock files.

---

## The replace map - do not touch it

The root `composer.json` carries a large auto-generated `"replace"` block: every package in `system/vendor/composer/installed.json` at its exact version. It is composer's own documented way of saying "these are already supplied - do not install them again", which lets an app-layer `require` resolve against the framework's pins and makes an incompatible demand **fail loudly at solve time** instead of silently double-installing.

**It is MACHINE-GENERATED and MUST NOT be hand-edited.** The wrappers regenerate it before every real install/update and the reconciler regenerates it after every pull; any manual edit is overwritten at the next of those events. If you think the map is wrong, the fix is upstream (an exposure request), never a local edit.

---

Details: `php artisan rsx:man dependencies`, `rsx:man npm`, `rsx:man upstream_changes`. Related: `rspade:bundles`, `rspade:framework-updates`.
