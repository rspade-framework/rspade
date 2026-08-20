# Changelog

All notable changes to `ignition-ui` will be documented in this file.

## 4.11.0 - 2024-04-26

- Add support for LiveWire 3

## 4.10.1 - 2024-03-29

- Change context icon

## 4.10.0 - 2024-03-28

- Add support for Laravel Context

## 4.9.5 - 2024-02-01

- Add `noopener` and `noreferrer` to external links

## 4.9.4 - 2023-09-19

- Subtle stacktrace background gradient

## 4.9.3 - 2023-08-24

- Fix: parsing and formatting multiline SQL queries

## 4.9.2 - 2023-08-23

- Fix: apply changes made to the Ignition config immediately

## 4.9.1 - 2023-08-21

- Fix URL encoding in copied paths

## 4.9.0 - 2023-08-21

- Feature: Add 'copy to clipboard' option to editors

## 4.8.2 - 2023-08-18

- Fix: Improve detection of vendor frames for JS frames

## 4.8.1 - 2023-08-17

- Fix: Navigation for custom contexts not working when context name has whitespaces.

## 4.8.0 - 2023-08-11

- Use container queries for responsive components
- Tweak SQL parameter bindings in the query debug tab
- Fix: don't listen to keypress events when typing in input fields or textareas

## 4.7.1 - 2023-07-25

- Only show context section when relevant

## 4.7.0 - 2023-07-25

- Add browser section displaying the user agent

## 4.6.1 - 2023-07-24

- Fix request tab not showing up for JS errors

## 4.6.0 - 2023-06-28

- Add support for stacktrace arguments

## 4.5.0 - 2023-06-06

- Add job section
- Add command section

## 4.4.1 - 2023-05-25

- Include changes from 4.3.0 again

## 4.4.0 - 2023-05-25

- Add support for custom context
- Add support for exception context
- Show SQL bindings inline in debug section

## 4.3.0 - 2023-05-23

- Add export for HighlightedCode component

## 4.2.4 - 2023-05-04

- Fix typo in regex (non-breaking)

## 4.2.3 - 2023-05-04

- Fix extracting SQL queries from Laravel 10 exception messages

## 4.2.2 - 2023-05-02

- Use scroll overflow for code blocks in solutions
- Make AI disclaimer more subtle

## 4.2.1 - 2023-05-02

- Add additional margin in AI solution disclaimer

## 4.2.0 - 2023-04-24

- Add indicator for AI-generated solutions (#10)
- Add support for rendering markdown in solutions (#9)
- Fix vendor frame grouping on Windows (#8)

## 4.1.2 - 2023-04-20

- Only show App section when there is information to show

## 4.0.4 - 2023-02-28

- Old UI not found
- Tweak card UI to better match Laravel's splash screen (#7)

## 4.0.3 - 2023-02-28

- SSR support
- Right-align line numbers in stacktrace

## 4.0.2 - 2022-05-16

- Update `EnvContext` type: can be `null` or `undefined`
- Don't compress bundle (makes debugging easier down the line)

## 4.0.1 - 2022-05-10

- Fix `Context` component when no `queryString` is provided

## 4.0.0 - 2022-05-10

- Flare specific properties have been removed from the `ErrorOccurrence` type (e.g. `id`, `error_id`, `received_at`, ...)
- All `ErrorOccurrence.context_items` are properly typed now
- Removed any untyped `ContextItem`s (no more `{ group:string; name:string; value:any; }` context items)
- Removed `getContextValues` helper because we no longer need to extract data from untyped `ContextItem`s
- Fixed a missing key in `Query` debug section
- Fixed selecting exceptions without accidentally collapsing the error card

### Upgrading

Implementations of the Ignition components, for example `spatie/laraval-ignition` and Flare, should make sure to provide an update `errorOccurrence` object that adheres to the `ErrorOccurrence` type. This mainly involves transforming the `context_items` to their proper types as described in `types/types.d.ts`.

## 3.3.5 - 2022-05-10

- Handle missing stack trace frames better

## 3.3.4 - 2022-04-23

- Log error to console if healthcheck fails
- Fix flash of unstyled content in Livewire modals

## 3.3.3 - 2022-03-04

- Fix errors when there is only one (non-application) frame in the stack

## 3.3.2 - 2022-03-02

- Include types in build

## 3.3.1 - 2022-03-02

- Improve "create GitHub issue" default content

## 3.3.0 - 2022-03-01

- Add error boundaries

## 3.2.0 - 2022-03-01

- Missing editor config will trigger a warning instead of an error
- Add support for `remoteSitesPath` again

## 3.1.0 - 2022-03-01

- Drop URL hashes for current frame and line number (see https://github.com/spatie/laravel-ignition/issues/64)
