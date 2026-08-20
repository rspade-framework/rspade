# Ignition UI

UI components used in Laravel's default Ignition error page.

See [spatie/ignition](https://github.com/spatie/ignition) for more information.

## Tagging a new release

- `yarn dev`
- make required changes
- update CHANGELOG.md
- `yarn publish`
- enter the new version you decided on in CHANGELOG.md
- update `@flareapp/ignition-ui` dependency in `spatie/ignition`
- in `spatie/ignition` run `yarn bundle` (or wait for the GH action)
- publish new version of `spatie/ignition` to packagist
