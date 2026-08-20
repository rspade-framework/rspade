# Project Manual Pages

## Purpose

This directory contains project-specific technical documentation in the style of Unix man pages. These documents provide detailed implementation specifications for features specific to this application.

## When to Create a Man Page

Create a man page when:
- A feature has non-obvious implementation details
- Multiple components interact in ways that need explanation
- Configuration options or patterns need documentation
- AI agents or future developers need reference material

## File Format

- **Extension**: `.txt` (plain text)
- **Naming**: `lowercase_with_underscores.txt` (e.g., `responsive.txt`, `user_permissions.txt`)
- **Encoding**: ASCII only, no Unicode

## Structure

Follow the standard Unix man page sections:

```
NAME
    feature_name - one-line description

SYNOPSIS
    Quick usage example showing the most common pattern

DESCRIPTION
    What the feature does, why it exists, key design decisions.
    Include rationale for non-obvious choices.

USAGE
    Step-by-step instructions for common use cases

CONFIGURATION
    Available options, defaults, and how to customize

EXAMPLES
    Real code examples from this project

IMPLEMENTATION NOTES
    Internal details relevant to maintenance

TROUBLESHOOTING
    Common issues and solutions

SEE ALSO
    Related man pages, files, or external docs
```

## Writing Style

- **Terse**: Say only what needs to be said
- **Complete**: Cover all aspects, don't leave gaps
- **Expert audience**: Assume familiarity with the framework
- **Patterns over prose**: Show code examples, not paragraphs
- **Plain text**: No markdown formatting, no special characters
- **4-space indentation**: For all code blocks

## Example

```
NAME
    responsive - two-tier responsive breakpoint system

SYNOPSIS
    SCSS Mixins:
        @include mobile { ... }   // 0 - 1023px
        @include desktop { ... }  // 1024px+
        @include phone { ... }    // 0 - 799px

    Bootstrap Classes:
        .col-6 .col-desktop-4
        .d-none .d-desktop-block

DESCRIPTION
    The responsive system provides a two-tier approach to handling
    screen sizes:

    Tier 1 (Semantic): mobile vs desktop
        mobile:  0 - 1023px (phone + tablet)
        desktop: 1024px+

    Tier 2 (Granular): specific device classes
        phone:      0 - 799px
        tablet:     800 - 1023px
        desktop-sm: 1024 - 1199px
        desktop-md: 1200 - 1639px
        desktop-lg: 1640 - 2199px
        desktop-xl: 2200px+

    [continues...]
```

## Relationship to Framework Man Pages

Framework documentation lives in `/system/app/RSpade/man/`. Those pages document framework features available to all RSpade applications.

This directory documents features specific to THIS project that build on or extend the framework.
