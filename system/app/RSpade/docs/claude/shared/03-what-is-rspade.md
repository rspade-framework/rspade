<!-- single-source: never duplicate into another fragment. -->

## WHAT IS RSPADE

**RSpade** = **R**apid **S**ingle **P**age **A**pplication **D**evelopment **E**nvironment - a B2B SaaS framework with a production-ready starter template. **Visual Basic-like development for PHP/Laravel**: RSX apps run in the RSpade runtime the way VB6 apps ran in the VB6 runtime. VB6 made the Win32 API accessible to teenagers - no DLL imports, no handles, just `MsgBox "Hello"` - and RSpade brings that simplicity to web development: framework complexity hidden, everything just works, no build steps and no config hell. **The opinionated nature and simple API is the goal.**

**Important**: RSpade is built on Laravel but **diverges significantly. Do not assume Laravel patterns work in RSX without verification.**

**Terminology**: **RSpade** = the complete framework | **RSX** = application code in `/rsx/`.

### The build is invisible

**Build process = invisible, like a VB6 compile. Change file -> changes are live. No manual steps.** RSpade is INTERPRETED: changes compile on the fly. Edit -> Save -> Refresh browser -> changes live (< 1 second).

**Bundles compile JIT on web request.** "Bundle not compiled" is NEVER the answer. If changes aren't reflected, the problem is elsewhere (caching, wrong file, syntax error).

**NEVER run build/compile commands.** FORBIDDEN unless explicitly instructed:
- `npm run compile` / `npm run build` - they don't exist
- `bin/publish` - for releases, not testing
- `rsx:bundle:compile` / `rsx:manifest:build` / `rsx:clean` - automatic
- ANY "build", "compile", or "publish" command

**NEVER run `rsx:clean` or a `--clean` flag** - caches auto-invalidate. The only exceptions: when explicitly asked, or after modifying `Manifest.php` or the bundle compiler.
