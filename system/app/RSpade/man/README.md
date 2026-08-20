# RSX Framework Manual Pages

## Overview

This directory contains technical documentation for the RSX Framework, formatted as traditional Unix manual pages from the late 1990s. Plain text format ensures easy copying and pasting of examples.

## Design Philosophy

- **Plain text format** - No fancy formatting or special characters
- **Laravel comparisons** - Explicit differences from Laravel equivalents
- **Framework philosophy** - Explains the "why" behind RSX design choices
- **Practical examples** - Real-world usage patterns
- **LLM-optimized** - Structured for easy parsing

## Available Documentation

### Core Systems

- `manifest_api.txt` - Manifest class API for file discovery and metadata
- `manifest_build.txt` - Manifest compilation process and extension system
- `bundle_api.txt` - Bundle system for asset compilation and management
- `controller.txt` - Controllers, routing, and Ajax endpoints
- `jqhtml.txt` - JQHTML component system and jQuery integration

### Framework Behavior

- `rspade.txt` - Framework philosophy and overview
- `framework_divergences.txt` - Every intentional departure from stock Laravel
  behavior (what throws, what a Laravel habit will get wrong, and the rule id
  enforcing each). **Add an entry here whenever framework code changes what an
  existing Laravel feature does** - the page's own ADDING AN ENTRY section is
  the contract.

### Full topic list

This file lists only the landmarks. `php artisan rsx:man` with no argument
enumerates every topic that exists; that listing, not this README, is the
index of record.

### Naming Convention

Files use `alphanumeric_underscore.txt` format for consistency with RSX conventions.

## Usage

Read directly or access via `rsx:man` command:
```bash
php artisan rsx:man manifest_api
php artisan rsx:man controller
```

## Format

Each manual page follows standard sections:
- NAME - Component and brief description
- SYNOPSIS - Quick usage examples
- DESCRIPTION - Overview of functionality
- Subsections for specific features
- EXAMPLES - Practical code samples
- TROUBLESHOOTING - Common issues
- SEE ALSO - Related documentation

## Contributing

When adding new documentation:
1. Use `.txt` extension with underscores in filename
2. Follow existing format structure
3. Focus on API reference, not education
4. Include real examples from codebase
5. Keep descriptions terse but complete

## Future Documentation

Planned additions:
- `middleware.txt` - Request middleware
- `commands.txt` - Artisan commands