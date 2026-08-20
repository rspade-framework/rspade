# RSpade Code Quality System

## Overview

The Code Quality system is a modular, extensible framework for enforcing coding standards and best practices across the RSpade codebase. It replaces a monolithic 1921-line checker with a clean, maintainable architecture using Manifest-based auto-discovery.

## Architecture

### Core Components

1. **CodeQualityChecker** (`CodeQualityChecker.php`)
   - Main orchestrator that discovers and runs all rules
   - Auto-discovers rules via RuleDiscovery::discover_rules()
   - Handles file scanning, caching, and violation collection
   - Performs syntax linting for PHP, JavaScript, and JSON files

2. **CodeQualityRule_Abstract** (`Rules/CodeQualityRule_Abstract.php`)
   - Base class for all code quality rules
   - Defines the interface: `get_id()`, `get_name()`, `check()`, etc.
   - Provides `add_violation()` helper method
   - Rules self-register by extending this class

3. **Violation** (`Violation.php`)
   - Data class representing a code violation
   - Contains: rule_id, file_path, line_number, message, severity, code_snippet, suggestion
   - Provides `to_array()` for serialization

### Support Classes

- **ViolationCollector** - Aggregates violations from all rules
- **CacheManager** - Caches sanitized file contents to improve performance
- **FileSanitizer** - Removes comments and strings for accurate code analysis

## Rule Categories

136 rule classes live under `Rules/`, grouped by subdirectory (Blade, Common, Convention,
Database, JavaScript, Jqhtml, Manifest, Meta, Models, PHP, Scss). The listings below are
ILLUSTRATIVE SAMPLES of each category, not an inventory - the rule set is discovered from the
filesystem, so `Rules/` itself is the authoritative list - read the directory for the full set.

### PHP Rules (`Rules/PHP/`)

1. **NamingConventionRule** (PHP-NAMING-01)
   - Enforces underscore_case for methods and variables
   - Excludes Laravel framework methods (toArray, firstOrCreate, etc.)
   - Severity: Medium

2. **MassAssignmentRule** (PHP-MASS-01)
   - Prohibits use of $fillable property
   - Ensures $guarded = ['*'] or removal
   - Severity: High

3. **PhpFallbackLegacyRule** (PHP-FALLBACK-01)
   - Detects "fallback" or "legacy" in comments/function names
   - Enforces fail-loud principle
   - Severity: Critical

4. **DbTableUsageRule** (PHP-DB-01)
   - Prohibits DB::table() usage
   - Requires ORM models for database access
   - Severity: High

5. **FunctionExistsRule** (PHP-FUNC-01)
   - Prohibits function_exists() checks
   - Enforces predictable runtime environment
   - Severity: High

### Jqhtml Rules (`Rules/Jqhtml/`)

1. **JqhtmlInlineScriptRule** (JQHTML-INLINE-01)
   - Prohibits inline <script> and <style> tags in .jqhtml templates
   - Enforces component class pattern with Component
   - Requires separate .js and .scss files
   - Severity: Critical
   - Runs at manifest-time

### JavaScript Rules (`Rules/JavaScript/`)

1. **VarUsageRule** (JS-VAR-01)
   - Prohibits 'var' keyword, requires let/const
   - Severity: Medium

2. **DefensiveCodingRule** (JS-DEFENSIVE-01)
   - Prohibits typeof checks for core classes
   - Core classes always exist in runtime
   - Severity: High

3. **InstanceMethodsRule** (JS-STATIC-01)
   - Enforces static methods in JavaScript classes
   - Exceptions allowed with @instance-class comment
   - Severity: Medium

4. **JQueryUsageRule** (JS-JQUERY-01)
   - Enforces $ over jQuery
   - Detects deprecated methods (live, die, bind, etc.)
   - Severity: Medium

5. **ThisUsageRule** (JS-THIS-01)
   - Detects problematic 'this' usage
   - Suggests class reference pattern
   - Severity: Medium

6. **DocumentReadyRule** (JS-READY-01)
   - Prohibits jQuery ready patterns
   - Requires ES6 class with static init()
   - Severity: High

7. **JsFallbackLegacyRule** (JS-FALLBACK-01)
   - JavaScript version of fallback/legacy detection
   - Severity: Critical

### Common Rules (`Rules/Common/`)

1. **FilenameCaseRule** (FILE-CASE-01)
   - Enforces lowercase filenames
   - Severity: Low

2. **FilenameEnhancedRule** (FILE-NAME-01)
   - Validates controller/model naming conventions
   - Checks file-class name consistency
   - Severity: Medium

3. **RootFilesRule** (FILE-ROOT-01)
   - Restricts files in project root
   - Maintains clean project structure
   - Severity: Medium

4. **RsxTestFilesRule** (FILE-RSX-01)
   - Prevents test files directly in rsx/
   - Enforces proper test organization
   - Severity: Medium

5. **RouteExistsRule** (ROUTE-EXISTS-01)
   - Validates Rsx::Route() calls reference existing routes
   - Checks controller/method combinations exist in manifest
   - Suggests placeholder URLs for unimplemented routes
   - Severity: High

### Manifest Rules (`Rules/Manifest/`)

1. **SpaAttributeMisuseRule** (PHP-SPA-01)
   - Detects #[SPA] combined with #[Route] on same method
   - #[SPA] is for bootstrap entry points only, not route definitions
   - Routes in SPA modules are defined in JavaScript actions with @route()
   - Runs at manifest-time for immediate feedback
   - Severity: Critical

2. **InstanceMethodsRule** (MANIFEST-INST-01)
   - Enforces static-only classes unless marked Instantiatable
   - Checks both PHP (#[Instantiatable]) and JS (@Instantiatable)
   - Walks inheritance chain to check ancestors
   - Severity: Medium

3. **ParentCallChain_CodeQualityRule** (PHP-PARENT-CHAIN-01)
   - Default parent-call chaining: an override must call `parent::<same-method>()`
   - Exempt when the nearest declaring ancestor is abstract or `#[Replaceable]`
   - Vendor parents excluded (manifest never scans `vendor/`); covers static + instance, incl. `__construct`/magic
   - Cross-file, AST-based (nikic/php-parser); runs at manifest-time
   - Severity: Critical

4. **SealedProperty_CodeQualityRule** (SEALED-01)
   - A subclass may not redeclare a property an ancestor marked `#[Sealed]`
   - PROPERTIES ONLY - methods already have PHP's `final`; properties have no such keyword
   - A REDECLARATION is flagged, not a read or a write; redeclaring the same value still counts
   - Cross-file, AST-based (nikic/php-parser); runs at manifest-time; honors `@SEALED-01-EXCEPTION`
     (file level, or on/above the declaration)
   - Severity: Critical

5. **ActorModel_CodeQualityRule** (ACTOR-01)
   - Every class named by `Rsx_Model_Abstract::AUDIT_ACTOR_MODELS` (what the authorship
     auto-stamp writes into a `*_by_type` column) must extend the actor layer
     (`Rsx_Actor_Model_Abstract` / `Rsx_Site_Actor_Model_Abstract`)
   - Every concrete descendant of the actor layer must still resolve `SoftDeletes`
   - Aimed at DOWNSTREAM class-override replacements of the three framework identity models:
     the failure is otherwise invisible until an authorship display runs, far from the change
   - Cross-file; runs at manifest-time; full contract: `rsx:man actors`
   - Severity: Critical

6. **MorphStringPattern_CodeQualityRule** (POLY-01)
   - Rejects Laravel's string-morph pattern on a polymorphic relation
   - `morphTo()` -> the DECLARING model must declare the `*_type` column in `$type_ref_columns`;
     `morphOne()`/`morphMany()` -> the RELATED model must (its table carries the column)
   - `morphToMany()`/`morphedByMany()` always flagged: a pivot's discriminator is owned by no
     model, so no declaration can cover it
   - Also enforces the pair standard: a morph type column must end in `_type`
   - Cross-file, AST-based (nikic/php-parser); runs at manifest-time; honors `@POLY-01-EXCEPTION`
   - Severity: Critical

7. **RelationshipOverride_CodeQualityRule** (RELATIONSHIP-OVERRIDE-01)
   - An override of an ancestor's `#[Relationship]` method must itself carry `#[Relationship]`
   - `get_relationships()` unions the lineage, so an unattributed override does NOT remove the
     name - it splits the declaration from the implementation, and the codegen/index/isRelation()
     readers all follow the attribute
   - Cross-file, pure compiled-manifest walk (no AST); runs at manifest-time; honors
     `@RELATIONSHIP-OVERRIDE-01-EXCEPTION`
   - Severity: Critical

8. **AbstractRegistryAttribute_CodeQualityRule** (ABSTRACT-ATTR-01)
   - An abstract class may not declare a REGISTRY attribute, at class level or on a method:
     `Auth`, `Route`, `SPA`, `Portal_Route`, `Ajax_Endpoint`, `Ajax_Endpoint_Model_Fetch`,
     `Api_Endpoint`, `Task`, `Schedule`, `Exclusive`, `Debounce`, `Emitter`, `OnEvent`,
     `Health_Check`, `Realtime_Touch`, `FPC` (the list is a class constant on the rule)
   - Those registries are built from the manifest, which indexes attributes per FILE and never
     inherits them: the registration reaches no subclass, or becomes a phantom entry keyed to the
     abstract that executes AS the abstract. Both silent at runtime
   - NOT covered (legitimate on an abstract): `#[Relationship]` and `#[Auth_Check]` (consumed
     through a lineage union), `#[Replaceable]`, `#[Instantiatable]`, `#[Monoprogenic]`, `#[Sealed]`
   - Cross-file, pure compiled-manifest walk (no AST); runs at manifest-time; honors
     `@ABSTRACT-ATTR-01-EXCEPTION`
   - Severity: Critical

## Configuration

### Config File (`config/rsx.php`)

```php
'code_quality' => [
    'enabled' => env('CODE_QUALITY_ENABLED', true),
    'cache_enabled' => true,
    'parallel_processing' => false,
    'excluded_directories' => [
        'vendor',
        'node_modules',
        'storage',
        'bootstrap/cache',
        'CodeQuality', // Exclude checker itself
    ],
    'rsx_test_whitelist' => [
        // Files allowed in rsx/ directory
        'main.php',
        'routes.php',
    ],
],
```

### Disabling Rules

Rules can be disabled by adding them to the disabled list:

```php
'disabled_rules' => [
    'RULE-ID', // the rule's get_id(), to suppress it project-wide
],
```

## Usage

### Command Line

```bash
# Run all checks
php artisan rsx:check

# Check specific directory
php artisan rsx:check rsx/

# Check specific file
php artisan rsx:check app/Models/User.php
```

### Exception Granting System

The code quality system supports granting exceptions to allow specific violations when justified. Exceptions are granted via specially formatted comments in the source files.

#### Exception Comment Format

```
@{RULE-ID}-EXCEPTION - Optional rationale
```

**Naming Convention:**
- Use the exact rule ID from `get_id()` method
- Add `-EXCEPTION` suffix
- Examples: `@PHP-NAMING-01-EXCEPTION`, `@JS-DEFENSIVE-01-EXCEPTION`, `@FILE-CASE-01-EXCEPTION`

#### Exception Placement

**Line-Level Exceptions** (most common):
Place the exception comment on the same line as the violation OR on the line immediately before it.

```php
// Same-line exception
if (key && key.startsWith('rsx::')) {  // @JS-DEFENSIVE-01-EXCEPTION - storage.key() can return null

// Previous-line exception
// @PHP-REFLECT-01-EXCEPTION - Test runner needs ReflectionClass for filtering
if ($reflection->isAbstract()) {
    continue;
}
```

**File-Level Exceptions** (for entire file):
Place at the top of the file, after namespace/use statements, before the main docblock.

```php
<?php

namespace App\RSpade\Core\Ajax;

use SomeClass;

// @FILE-SUBCLASS-01-EXCEPTION

/**
 * Class docblock
 */
class MyClass {
```

**Docblock Exceptions** (for method/class):
Place inside the docblock using JSDoc/PHPDoc style.

```php
/**
 * Check if a method is overriding a parent method
 *
 * @PHP-REFLECT-02-EXCEPTION: This method needs ReflectionClass to check parent methods
 * from Laravel framework classes which are not tracked in the manifest.
 */
protected function is_overriding_parent_method($class_name, $method_name) {
```

#### How Exceptions Are Applied (generic, not per-rule)

Exception handling is implemented ONCE, in the checker, and covers EVERY rule.
`CodeQualityChecker.php:189-195` reads the original file contents before invoking each rule
and, if the text contains `@<RULE-ID>-EXCEPTION` anywhere in the file, skips that rule for
that file. No rule has to opt in: all 133 rules honor a file-level exception comment, including
rules whose own source never mentions `EXCEPTION`.

What an individual rule can add on top is PRECISION. A rule that also looks for the marker
itself can suppress a SINGLE line (same-line or previous-line) rather than the whole file -
which is what you want for a rule that fires many times in one file. 49 of the 133 rules do
this today (grep -rl EXCEPTION Rules/, minus the abstract); the other 84 are file-granular
via the checker.

**Consequence for placement:** a file-level comment always works. Line-level placement narrows
the suppression ONLY when the rule implements line checking; otherwise the marker still
suppresses that rule for the entire file (it is in the file text either way). Use the tightest
placement the rule supports, and always state a rationale.

**To check whether a rule supports LINE-level suppression:**

1. Open the rule file in `/system/app/RSpade/CodeQuality/Rules/`
2. Search for `EXCEPTION` in the file
3. If found, the rule narrows suppression to the line/construct
4. If not found, the marker suppresses that rule for the whole file (checker-level)

**To implement line-level exception handling in a rule:**

Add a check before calling `add_violation()`. The exact implementation depends on rule structure:

**Line-by-line checking pattern:**

```php
public function check(string $file_path, string $contents, array $metadata = []): void
{
    $lines = explode("\n", $contents);

    foreach ($lines as $line_number => $line) {
        $actual_line_number = $line_number + 1;

        // Skip if line has exception comment
        if (str_contains($line, '@' . $this->get_id() . '-EXCEPTION')) {
            continue;
        }

        // Check for violation
        if ($this->detect_violation($line)) {
            $this->add_violation(...);
        }
    }
}
```

**Multi-line or previous-line pattern:**

```php
// Check previous line for exception comment
$prev_line_index = $line_number - 1;
if ($prev_line_index >= 0 && str_contains($lines[$prev_line_index], '@' . $this->get_id() . '-EXCEPTION')) {
    continue;  // Skip this line
}
```

**File-level pattern:**

```php
public function check(string $file_path, string $contents, array $metadata = []): void
{
    // Check if entire file has exception
    if (str_contains($contents, '@' . $this->get_id() . '-EXCEPTION')) {
        return;  // Skip entire file
    }

    // ... rest of checking logic
}
```

#### Exception Rationale Guidelines

**Always include a rationale** explaining WHY the exception is needed:

```javascript
// @JS-DEFENSIVE-01-EXCEPTION - Browser API storage.key(i) can return null when i >= storage.length
```

**Good rationales:**
- Reference external API behavior: "Browser API returns null", "Laravel method signature requires this"
- Explain architectural necessity: "Task system uses direct queries for performance"
- Note optional/polymorphic patterns: "Array.find() returns undefined when no match"

**Bad rationales:**
- "TODO: fix later"
- "Not sure why this is needed"
- No rationale at all

#### CRITICAL: AI Agent Exception Policy

**ABSOLUTE PROHIBITION - NEVER GRANT EXCEPTIONS AUTONOMOUSLY**

When you encounter a code quality violation, you are **FORBIDDEN** from granting exceptions without explicit programmer approval.

**Required procedure:**
1. **STOP** - Do not add exception comments
2. **ANALYZE** - Determine if the violation is:
   - Invalid defensive coding (should be removed)
   - Valid duck typing (needs exception)
   - External API constraint (needs exception)
3. **REPORT** - Present findings: "Found violation X in file Y. Analysis: [your reasoning]. Options: a) Remove the check (fail-loud), b) Grant exception (provide rationale), c) Refactor differently"
4. **WAIT** - Wait for programmer to decide
5. **NEVER** add `@RULE-ID-EXCEPTION` comments without explicit approval

**Only grant exceptions when:**
- Programmer explicitly requests it: "grant an exception for this"
- Programmer approves your recommendation: "yes, add the exception"
- You are implementing a fix that the programmer has already approved

**Exception grants are permanent code changes** - they suppress violations indefinitely and become part of the codebase. Do not make this decision autonomously.

## Development

### Creating New Rules

1. Create a new class extending `CodeQualityRule_Abstract`
2. Place in appropriate Rules subdirectory
3. Implement required methods:
   - `get_id()` - Unique rule identifier
   - `get_name()` - Human-readable name
   - `check()` - Violation detection logic
4. Add to Manifest scan directories if needed

Example:

```php
namespace App\RSpade\CodeQuality\Rules\PHP;

use App\RSpade\CodeQuality\Rules\CodeQualityRule_Abstract;

class MyNew_CodeQualityRule extends CodeQualityRule_Abstract
{
    public function get_id(): string
    {
        return 'PHP-NEW-01';
    }

    public function get_name(): string
    {
        return 'My New Rule';
    }

    public function get_description(): string
    {
        return 'Description of what this rule checks';
    }

    public function get_file_patterns(): array
    {
        return ['*.php'];
    }

    /**
     * Whether this rule is called during manifest scan
     *
     * IMPORTANT: This method should ALWAYS return false unless explicitly requested
     * by the framework developer. Manifest-time checks are reserved for critical
     * framework convention violations that need immediate developer attention.
     *
     * Rules executed during manifest scan will run on every file change in development,
     * potentially impacting performance. Only enable this for rules that:
     * - Enforce critical framework conventions that would break the application
     * - Need to provide immediate feedback before code execution
     * - Have been specifically requested to run at manifest-time by framework maintainers
     *
     * DEFAULT: Always return false unless you have explicit permission to do otherwise.
     */
    public function is_called_during_manifest_scan(): bool
    {
        return false; // Always false unless explicitly approved
    }

    public function check(string $file_path, string $contents, array $metadata = []): void
    {
        // Detection logic
        if ($violation_found) {
            $this->add_violation(
                $file_path,
                $line_number,
                "Violation message",
                $code_snippet,
                "How to fix",
                'medium'
            );
        }
    }
}
```

### Testing Rules

1. Create a temporary test file with violations
2. Run `php artisan rsx:check`
3. Verify violations are detected correctly
4. Clean up test files

## Migration from Monolith

The original 1921-line `CodeStandardsChecker.php` has been:
1. Archived to `/archived/CodeStandardsChecker.old.php`
2. Split into modular rule classes
3. Enhanced with auto-discovery via Manifest
4. Improved with better caching and performance

All original rule logic has been preserved exactly, ensuring no regression in code quality checks.

## Performance

- **Caching**: Sanitized file contents are cached to avoid repeated processing
- **Incremental Linting**: Files are only linted if changed since last check
- **Efficient Scanning**: Smart directory traversal skips excluded paths

## Manifest-Time Checking

By default, code quality rules run only when `php artisan rsx:check` is executed. However, certain critical rules can be configured to run during manifest builds to provide immediate feedback.

### When to Enable Manifest-Time Checking

**DO NOT** enable manifest-time checking unless you have explicit approval. Rules should only run at manifest-time if they:

1. Enforce critical framework conventions that would break the application
2. Need to provide immediate feedback before code execution
3. Have been specifically requested by framework maintainers

### Incremental vs Cross-File Rules

Manifest-time rules support two processing modes via `is_incremental()`:

**Incremental Rules** (`is_incremental() = true`, default):
- Check each file independently
- Only changed files are processed during incremental manifest rebuilds
- More efficient for per-file validation (syntax, patterns, structure)
- Example: `JqhtmlInlineScriptRule`, `MassAssignmentRule`

**Cross-File Rules** (`is_incremental() = false`):
- Need to see relationships between files or check the full manifest
- Run once per manifest build with access to `Manifest::get_all()`
- Use for rules that validate naming across files, check for duplicates, etc.
- Example: `ScssClassScope_CodeQualityRule`, `InstanceMethods_CodeQualityRule`

To make a rule cross-file, override `is_incremental()`:

```php
public function is_incremental(): bool
{
    return false; // This rule needs cross-file context
}
```

Cross-file rules should use a static guard to ensure they only run once:

```php
public function check(string $file_path, string $contents, array $metadata = []): void
{
    static $already_checked = false;
    if ($already_checked) return;
    $already_checked = true;

    // Access all files via Manifest::get_all()
    $files = Manifest::get_all();
    // ... check relationships between files
}
```

### Current Manifest-Time Rules

**43 of the 136 rules** return `true` from `is_called_during_manifest_scan()` (verified
2026-08-13). This list drifts; regenerate it from the source of truth:

```bash
cd system/app/RSpade/CodeQuality
grep -rn -A4 "function is_called_during_manifest_scan" Rules/ | grep -B4 "return true"
```

Approved for manifest-time execution, by rule directory:

- `Blade/` - BLADE-EVENT-01, BLADE-LAYOUT-ASSETS-01, BLADE-SCRIPT-01
- `Common/` - FILE-CASE-DUP-01, FILE-SPACE-01, ROUTE-SYNTAX-01
- `Convention/` - CONV-BUNDLE-03
- `JavaScript/` - JQHTML-EVENT-01, JQHTML-IMPL-01, JS-CATCH-FALLBACK-01, JS-DECORATOR-01,
  JS-DECORATOR-IDENT-01, JS-DUPLICATE-METHOD-01, JS-LIFECYCLE-01, JS-READY-01
- `Jqhtml/` - JQHTML-CLASS-01, JQHTML-COMMENT-01, JQHTML-INLINE-01
- `Manifest/` - ABSTRACT-ATTR-01, ACTOR-01, MANIFEST-CTRL-01, MANIFEST-INST-01,
  MANIFEST-MONO-01, PHP-PARENT-CHAIN-01, PHP-SPA-01, POLY-01, RELATIONSHIP-OVERRIDE-01,
  SCSS-SCOPE-01, SEALED-01
- `Models/` - MODEL-AJAX-FETCH-01, MODEL-CARBON-01, MODEL-EXTENDS-01, MODEL-FETCH-DATE-01
- `PHP/` - CONTROLLER-STATIC-01, PHP-ALIAS-01, PHP-CONTROLLER-REQUEST-01, PHP-MASS-01,
  PHP-ROUTE-QUERY-01, PHP-RSX-FQCN-01, PHP-STATIC-PROP-01, PHP-STRUCTURE-01,
  SESSION-ID-01
- `Scss/` - SCSS-SID-01

The heaviest of these deserves its own note:

- **PHP-PARENT-CHAIN-01** (ParentCallChain_CodeQualityRule): Default parent-call chaining. A method override MUST call `parent::<same-method>()` unless the nearest manifest-visible ancestor that declares the method is abstract or marked `#[Replaceable]`. Covers static + instance methods including `__construct` and magic methods. Vendor parents are excluded (the manifest never scans `vendor/`, so overriding a vendor method is not flagged). Cross-file rule (`is_incremental() = false`); AST-based detection (nikic/php-parser), never regex, so a comment/string mention of `parent::method()` cannot spoof it.

- **POLY-01** (MorphStringPattern_CodeQualityRule): a polymorphic relation whose `*_type` discriminator is not declared as a type ref. RSpade stores the discriminator as a BIGINT type-ref id; with the declaration in place, STOCK Eloquent morph relations work over it unchanged (`Type_Ref_Registry::register_morph_map()` registers each integer id as a morph-map alias next to the class name). Without it you get Laravel's VARCHAR class-name morph, which half-works - it reads plausibly and silently matches nothing the moment anything else in the framework treats the column as a type ref, which is why it is fatal rather than advisory. Cross-file (`is_incremental() = false`), AST-based, and deliberately silent on any call whose owning model cannot be resolved statically. Migrations are NOT covered (the manifest does not scan them, and column definitions live inside raw SQL strings), so a VARCHAR `*_type` column is caught at the model that relates over it. Full contract: `rsx:man polymorphic`.

- **SESSION-ID-01** (SessionIdNullCheck_CodeQualityRule): a null-ish/zero-ish TEST on a `Session::get_session_id()` / `Portal_Session::get_session_id()` result. Both calls CREATE a session and are declared `: int`, so the test is unreachable AND the session it was meant to prevent has already been created — a defect that is completely invisible at runtime, which is why it is fatal at build time rather than advisory. Per-file (`is_incremental() = true`), AST-based (nikic/php-parser), with a single-assignment variable-dataflow model deliberately kept conservative. The two Session facade files and `/CodeQuality/` meta-code are excluded; the rule honors `@SESSION-ID-01-EXCEPTION` itself (file-level or on/above the line), since the manifest-time driver does not apply the checker's generic exception handling.

All other rules must return `false` from `is_called_during_manifest_scan()`. Adding to this set
needs explicit framework-maintainer approval - every manifest-time rule runs on every file change
in development AND can abort the build (see the poison-flag mechanism below).

### `#[Replaceable]` — the parent-call chaining opt-out

`#[Replaceable]` is a marker-only attribute (NEVER define the attribute class — the rule reads it by name from the AST, per framework convention). Placed on a PARENT method, it declares that method a genuine template/hook: overriders may fully replace it WITHOUT calling `parent::`. It is the explicit, annotated inverse of `final`, and the sanctioned way to clear a `PHP-PARENT-CHAIN-01` violation when an override is meant to replace rather than extend. The anchor is the NEAREST ancestor that declares the method; an intermediate class that re-declares the method concretely (without `#[Replaceable]`) re-establishes the chain obligation for its own descendants.

Do NOT mark chain-mandatory methods `#[Replaceable]`. The archetype is `Rsx_Site_Model_Abstract::booted()`: it installs the cross-tenant isolation controls, so an override that forgot `parent::booted()` would silently drop them — exactly the fail-open leak this rule exists to catch — so it stays plain (chain-mandatory), not `#[Replaceable]`.

### `#[Sealed]` — the property counterpart to `final`

`#[Sealed]` is a marker-only attribute (NEVER define the attribute class — SEALED-01 reads it by name from the AST, per framework convention). Placed on a PROPERTY, it declares that property the sole property of the class that declared it: no descendant may redeclare it, at any depth.

**Properties only, deliberately.** Methods already have `final`, enforced by PHP itself; adding a second mechanism there would be a dual implementation. PHP has no `final` for properties, so a subclass can silently redeclare one and detach itself from a value the parent's own logic depends on — the redeclaration compiles, runs, and reads as intentional. That is the gap `#[Sealed]` fills, and the only one.

What is flagged is a REDECLARATION, not a read or a write: `static::$prop = x` anywhere is untouched; `public static $prop = x;` in a subclass body is the violation. Redeclaring with the SAME value is still a violation — the point is that the declaration lives in exactly one place.

The archetype is `Rsx_Actor_Model_Abstract::$actor_soft_deletes`: the declared soft-delete mandate for identity models. Redeclaring it is exactly the move someone makes when trying to opt an actor out of soft deletes, which would leave every audit column that ever named that actor dangling. Full contract: `rsx:man actors`.

### When a Manifest-Time Violation Fires: save-then-check + poison flag

Manifest-time rules run AFTER the manifest is saved, and a violation keeps re-firing until the source is fixed. The full mechanism (save at `Manifest.php:1346`, check at `:1356`, `manifest_is_bad` poison flag forcing a full rebuild + re-fire on every subsequent load) is documented in `Core/Manifest/CLAUDE.md` (Code Quality Integration). In short: a `PHP-PARENT-CHAIN-01` violation aborts the build via `YoureDoingItWrongException`, sets the poison flag, and re-fires on every load — so a missing parent-call cannot be dodged by a no-op incremental pass; it stays broken until you add the `parent::` call or mark the parent `#[Replaceable]`.

## Severity Levels

- **Critical**: Must fix immediately (e.g., fallback code)
- **High**: Should fix soon (e.g., mass assignment)
- **Medium**: Fix when convenient (e.g., naming conventions)
- **Low**: Minor issues (e.g., filename case)
