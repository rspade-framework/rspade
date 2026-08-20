# rspade_system

This is a **utility repository** used by the RSpade framework's own tooling. It is not
the repository you clone to build an application.

RSpade uses this repo for two things:

- **Creating new RSpade projects** - the starter template vendors this tree into a new
  project's `system/` directory.
- **Updating existing projects** - `php artisan rsx:framework:pull` fetches framework
  releases from here and applies them to a project's vendored `system/` tree.

Each commit on `master` is one framework release, published as a unit by the RSpade
release process. The `.rspade-release.json` inventory in each release identifies it
and hashes every shipped file.

## Looking for RSpade?

To start building with RSpade, visit the actual project repository:

**https://github.com/rspade-framework/rspade**
