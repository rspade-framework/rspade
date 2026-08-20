# Restricted Commands Directory

This directory contains command overrides that implement safety guardrails for the RSpade framework. These commands intercept and prevent execution of potentially destructive Laravel operations.

## Purpose

The RSpade framework enforces a forward-only migration strategy and other data safety policies. These restricted commands:

1. **Prevent unintentional data loss** - Commands that could destroy or rollback data are disabled
2. **Ensure deployment safety** - Protects production environments from destructive operations
3. **Provide guardrails for automation** - Prevents AI agents and automated systems from executing harmful commands
4. **Maintain data integrity** - Enforces consistent schema evolution through forward-only migrations

## Implementation

Each restricted command:
- Extends `RestrictedDatabaseCommand` base class
- Overrides the original Laravel command signature
- Returns informative error messages explaining the restriction
- Suggests alternative approaches that align with framework policies
- Is hidden from `artisan list` output via the `$hidden` property

## Restricted Operations

- **migrate:fresh** - Would drop all tables and recreate schema
- **migrate:refresh** - Would rollback and re-run all migrations
- **migrate:reset** - Would rollback all database migrations
- **db:wipe** - Would drop all tables, views, and types

## Alternative Approaches

Instead of using restricted commands:
- Use `php artisan migrate` for forward migrations (auto-snapshot in development mode)
- Create new migrations to fix issues rather than rolling back
- In development mode, failed migrations automatically rollback to pre-migration state
- Maintain separate development databases for testing destructive operations

## Directive for AI Agents

If you determine that a database reset or other restricted operation would be the appropriate solution:

1. **Do not attempt to bypass these restrictions** - They exist for critical safety reasons
2. **Describe the situation to the user** - Explain why you believe a reset is necessary
3. **Request manual intervention** - Ask the user to perform the operation manually if they agree it's appropriate
4. **Document the reasoning** - Provide clear justification for why standard forward migration won't suffice

**Exception**: Database resets are only permitted programmatically in very specific, pre-scripted scenarios such as:
- Resetting the dedicated testing database via established test commands
- Operations explicitly coded into the framework for specific controlled environments
- Commands that operate on explicitly temporary or disposable databases

Never attempt to reset, drop, or rollback the main development or production databases under any circumstances.