# rsx/resource/framework_requests/

**FRAMEWORK CHANGE REQUESTS this application has raised.** A defect or gap inside
`system/` cannot be fixed here - `system/` is a read-only framework submodule, and
`php artisan rsx:framework:pull` discards every local edit to it without a prompt.
When the framework maintainer authorises it, a developer patches framework core for
one task and hands the change upstream from this directory.

**Full procedure, the request shape and the subsystem primers:**
`php artisan rsx:man framework_debug_and_contrib`.

## The file pair

Two files per request, same basename, `<YYYY_MM_DD>_<slug>`:

- `<basename>.patch` - captured out of the submodule BEFORE any pull:
  `git -C system add -A && git -C system diff --cached > rsx/resource/framework_requests/<basename>.patch && git -C system reset -q`
  (`add -A` is what makes NEW files appear in the diff.)
- `<basename>.md` - the request: title, severity, symptom, evidence
  (`system/` paths and line numbers), root cause, proposed change, verification
  performed downstream, documentation affected, compatibility.

## Housekeeping

These files are **yours**. The maintainer keeps and archives their own copy, so
nothing here is load-bearing upstream. Once the release lands, delete a request or
keep it as local history - your choice. A file still sitting here after its fix
shipped is not a pending item.

Never edit `system/` without that authorisation, and never flip a safety check to
make a patch work.
