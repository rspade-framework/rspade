# RuntimeChecks - Convention Enforcement System

## CRITICAL DISTINCTION: Runtime Requirements vs Convention Enforcement

This directory contains **convention enforcement checks** that throw `YoureDoingItWrongException` when developers violate framework conventions. These are NOT runtime requirements.

### What BELONGS Here (Convention Enforcement)

Convention checks are "best practice" validations that:
- **Could technically work** if not enforced (the code would still execute)
- Ensure **consistency and uniformity** across the codebase
- Prevent **bad coding patterns** before they become technical debt
- Are primarily checked **in development mode only**
- Provide **verbose remediation guidance** explaining the right way

Examples:
- Bundle doesn't include the view/controller directory (organizational convention)
- Views having inline `<style>` or `<script>` tags (asset organization convention)
- Layouts missing `rsx_body_class()` or bundle includes (scoping convention)
- Using absolute paths instead of RSX IDs for blade includes
- Hardcoding URLs instead of using route helpers

### What does NOT Belong Here (Runtime Requirements)

Runtime sanity checks are **actual execution requirements** that:
- **Would break the code** if not enforced
- Are **algorithmic requirements** for the code to function
- Document **what the algorithm expects** to operate correctly
- Must be checked **in all environments** (dev and production)
- Usually have **brief error messages** (the fix is obvious or situation unexpected)

Examples:
- File doesn't exist that needs to be loaded
- Duplicate class names (breaks autoloading)
- Manifest not initialized when required
- Required configuration missing
- Invalid data types that would cause crashes

**These MUST stay inline with their related code** because:
1. They document the algorithm's requirements
2. A developer reading the code needs to understand these constraints
3. They're part of the logic flow, not optional conventions

## Decision Framework

When deciding whether to create a RuntimeCheck or keep inline:

### Create a RuntimeCheck if:
- ✅ The code would still technically execute without the check
- ✅ You're enforcing a "preferred way" when multiple ways exist
- ✅ The check is primarily for development mode
- ✅ The error message needs extensive explanation and remediation steps
- ✅ You're preventing future problems, not current execution failures

### Keep Inline if:
- ❌ The code would fail/crash without the check
- ❌ It's a fundamental requirement of the algorithm
- ❌ The check documents what the code expects to operate
- ❌ It must run in production too
- ❌ The error is unexpected with no clear remediation

**When in doubt, keep it inline.** It's better to have too much information about an algorithm's requirements visible than to hide critical constraints in a separate file.

## The Cardinal Sin

**NEVER remove critical algorithm information** by moving runtime requirements to this directory. If a check explains what an algorithm needs to function correctly, it MUST stay with that algorithm.

## Creating New RuntimeChecks

1. **Group by feature domain** (BundleErrors, ViewErrors, RouteErrors, etc.)
2. **Use static methods** that build and throw comprehensive error messages
3. **Always throw `YoureDoingItWrongException`** to make it clear this is a convention violation
4. **Include verbose remediation** with examples and exact steps to fix
5. **Name methods descriptively** (e.g., `view_has_inline_assets`, `bundle_missing_controller`)

### Example Structure

```php
<?php

namespace App\RSpade\CodeQuality\RuntimeChecks;

class BundleErrors
{
    /**
     * Bundle doesn't include the view directory (convention for organization)
     */
    public static function view_not_covered(
        string $view_path,
        string $view_dir,
        string $bundle_class,
        array $include_paths
    ): void {
        $error_message = "RSX Bundle Path Coverage Error: ...\n\n";

        // Detailed explanation of the problem
        $error_message .= "View file: {$view_path}\n";
        $error_message .= "Bundle: {$bundle_class}\n\n";

        // Clear remediation steps
        $error_message .= "To fix this, either:\n";
        $error_message .= "1. Add '{$view_dir}' to the bundle's 'include' array\n";
        $error_message .= "2. Use a different bundle that covers this module\n\n";

        // Why this convention exists
        $error_message .= "This ensures bundles are properly scoped to the modules they serve.";

        throw new YoureDoingItWrongException($error_message);
    }
}
```

## Usage Pattern

In your code where you want to enforce a convention:

```php
// In development mode only
if (!app()->environment('production')) {
    // Check convention (bundle should include view directory)
    if (!$bundle_includes_view) {
        // Verbose error explaining the convention
        BundleErrors::view_not_covered($view_path, $view_dir, $bundle_class, $include_paths);
    }
}
```

## File Organization

- `YoureDoingItWrongException.php` - The base exception class
- `BundleErrors.php` - Bundle-related convention violations
- `ViewErrors.php` - View/template convention violations
- `RouteErrors.php` - Routing convention violations
- etc. (grouped by feature domain)

## Remember

These checks are about **teaching developers the right way**, not preventing crashes. They're educational guardrails that can be removed without breaking functionality - but shouldn't be, because they prevent bad patterns from taking root.

The key test: **"Would the code still run if I commented out this check?"**
- Yes → RuntimeCheck (convention)
- No → Keep inline (requirement)