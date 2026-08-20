# CodeQuality/Rules — the rule classes

Every concrete `rsx:check` rule, one class per rule, extending
`CodeQualityRule_Abstract`. Grouped by the language or concern they inspect:
`Blade/ Common/ Convention/ Database/ JavaScript/ Jqhtml/ Manifest/ Meta/
Models/ PHP/ Scss/`. The subdirectory is organization only — rules are
**discovered by the manifest**, so there is no registry file to edit and nothing
to register when you add one.

Architecture (the runner, the collector, severities, the config surface) lives in
the parent `CodeQuality/CLAUDE.md`. This file is about writing a rule.

## The contract

Implement `get_id()`, `get_name()`, `get_description()`, `get_file_patterns()`,
`check($file_path, $contents, $metadata)`, and `get_default_severity()`.

- **The id is the API.** `PHP-MASS-01`, `REALTIME-AUTH-01`, `ROUTE-SYNTAX-01` —
  it appears in output, in `@<ID>-EXCEPTION` suppression comments, in
  documentation, and in agents' memories. Pick it once; renaming one breaks every
  suppression comment in every downstream app.
- **Two enforcement tiers.** A normal rule reports a violation. Overriding
  `is_called_during_manifest_scan()` to `true` makes it FATAL — it throws
  `YoureDoingItWrongException` during the manifest build and the code cannot
  ship. Reserve that tier for things that are unambiguously broken, never for
  style.
- **Write the remediation for the reader, not the author.** The rule text is
  read by a developer or an agent at the moment they are blocked: say what is
  wrong, why it is a rule, the exact correct form, and whether to fix it
  autonomously or ask. Agents are instructed to TRUST that text as authoritative
  — so vague remediation produces confident wrong fixes.

## Invariants

- **AST, never regex, when inspecting source.** Use the shared parsers
  (`rsx:man ast_sourcecode_parsers`); a regex rule generates false positives that
  train everyone to ignore the checker.
- **Rules run over files the framework does not control.** Never assume a
  parseable file, a known encoding, or that a match implies a defect — a rule
  that cries wolf is worse than no rule.
- Every rule must be suppressible by a rationale'd `@<ID>-EXCEPTION` comment
  unless it is fatal by design; honor it in `check()`.
- Adding a rule that enforces a NEW downstream-facing contract is a contract
  change: it needs its documentation and, usually, an `upstream_changes` doc.

## Pointers

`../CLAUDE.md` (architecture) · `../RuntimeChecks/CLAUDE.md` (the runtime
sibling) · `rsx:man code_quality` (framework-only; stripped from releases) ·
`rsx:man ast_sourcecode_parsers`
