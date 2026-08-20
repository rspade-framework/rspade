# RSX Framework Manual Pages

## Documentation Philosophy

These manual pages follow traditional Unix man page conventions from the late 1990s:
- **Plain text format** - No fancy formatting, boxes, or special characters
- **Simple indentation** - Code examples use 4-space indentation
- **Readable and copyable** - Easy to copy/paste examples
- **Traditional sections** - NAME, SYNOPSIS, DESCRIPTION, etc.

## Writing Style Requirements

### Format
- Use plain ASCII text only
- Code examples indented with 4 spaces
- No box drawing characters (└─┘│┌┐)
- No special Unicode symbols
- Section headers in ALL CAPS
- Subsection headers in Title Case

### Content Requirements
Each man page must include:

1. **General introduction** - Explain what the RSX feature is and its purpose
2. **RSX philosophy** - How it simplifies development compared to standard approaches
3. **Laravel differences** - Explicitly note how RSX differs from Laravel equivalents
4. **Practical examples** - Real-world usage patterns from actual RSX applications
5. **Common pitfalls** - What mistakes developers might make

### RSX vs Laravel Context
Always explain how RSX features differ from their Laravel counterparts:
- Path-agnostic class loading vs PSR-4 autoloading
- Manifest-based discovery vs service provider registration
- Static controller methods vs instance methods with DI
- Unified bundle system vs mix/vite configuration
- Automatic route discovery vs manual route files

## Manual Page Sections

Standard sections in order:

```
NAME
    Component name and one-line description

SYNOPSIS
    Quick usage examples showing the most common use case

DESCRIPTION
    Overview of the component, its philosophy, and how it differs from Laravel

BASIC USAGE
    Step-by-step introduction to using the feature

API REFERENCE
    Detailed method/function documentation

EXAMPLES
    Practical, real-world code examples

RSX VS LARAVEL
    Explicit comparison with Laravel equivalents

TROUBLESHOOTING
    Common errors and their solutions

SEE ALSO
    Related manual pages
```

## File Naming
- Use lowercase with underscores: `manifest_api.txt`
- Extensions: Always `.txt` for man pages
- No special characters or spaces

## Example Introduction Template

```
DESCRIPTION
    The RSX [feature] provides [what it does] through [how it works].
    Unlike Laravel's [equivalent], which requires [Laravel approach],
    RSX automatically [RSX approach] without any configuration.

    This path-agnostic approach means developers never need to worry
    about [common Laravel pain point]. The framework handles all
    [technical detail] transparently.

    Key differences from Laravel:
    - Laravel: [how Laravel does it]
    - RSX: [how RSX does it]

    Benefits:
    - No [configuration/boilerplate] required
    - Automatic [feature]
    - Works with [use case]
```

## Testing Documentation
After writing or updating a man page:
1. Test with `php artisan rsx:man [topic]`
2. Verify plain text output (no special characters)
3. Ensure code examples are properly indented
4. Confirm examples can be copy/pasted directly

## Future Documentation Topics
Planned manual pages to create:
- `ajax.txt` - Internal API system with auto-generated stubs
- `main.txt` - Application-wide middleware hooks