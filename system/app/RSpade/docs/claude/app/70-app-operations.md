<!-- bucket: app — single-source, never duplicate. True ONLY in a downstream application. THIS FRAGMENT IS THE CANONICAL HOME of the app dependency layer; the inverse "add deps inside system/" rule is framework-only and must never load here. -->

## APP OPERATIONS: DEPENDENCIES & LOCAL CONVENTIONS

**NEVER add packages by running `composer`/`npm` inside `system/`** — the framework is read-only. Add your application's packages at the project root with the wrappers:

```bash
php artisan rsx:composer require <pkg>   # PHP package -> /vendor
php artisan rsx:npm install <pkg>        # JS package  -> /node_modules
```

- **Framework-provided packages record instead of duplicating** — requiring one the framework already ships records it as provided-by-framework rather than installing a second copy (`rsx:composer provides` / `rsx:npm provides` lists them).
- **After a framework update** the reconciler runs automatically and names anything you rely on that changed, with the exact adopt/re-record command. Silent otherwise.
- **JS packages reach the browser** via the Asset Bundle `'npm' => [...]` key, identical for app-layer and framework packages.
- **Commit your root `composer.json`/`package.json` + lock files.** **The `"replace"` block is machine-generated and MUST NOT be hand-edited** — any manual edit is overwritten.

### Local conventions

- **App config overrides** live in `rsx/resource/config/rsx.php` (merged over the framework config; never modify `system/config/`).
- **Your own pre-launch checklist** lives at `rsx/resource/audits/prelaunch_checklist.md` — app-specific items (permission gates you added, email templates, seed-data cleanup). The framework-required audits are `php artisan rsx:man prelaunch_checklist`; review BOTH before going live.

Skill: `rspade:dependencies`. Details: `php artisan rsx:man dependencies`, `rsx:man npm`.
