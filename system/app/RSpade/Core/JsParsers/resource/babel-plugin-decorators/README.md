# @rspade/babel-plugin-decorators

Vendored, patched fork of `@babel/plugin-proposal-decorators`, bundled into a single
self-contained CommonJS file (`index.js`) with `@babel/core` and the shared AST
infrastructure (`@babel/types`, `@babel/traverse`, `@babel/template`, `@babel/parser`)
externalized so the fork always shares AST node identity with the host `@babel/core`.

`index.js` is generated third-party code. Do NOT hand-edit or reformat it. All of our
logic lives in `upstream.patch` plus the regeneration steps below.

## Why this fork exists

Babel's decorator transform (implemented in
`@babel/helper-create-class-features-plugin/lib/decorators.js`) drops the module-scope
name binding of a decorated CLASS DECLARATION that has static members. Internally
`replaceClassWithVar()` renames the public name to a truncated uid everywhere, and the
statics branch replaces the whole statement with a `new (...)()` expression -- so after
transform there is no `Foo` binding in scope.

RSpade concatenates transform output into shared-scope, non-module bundles. Other files,
jqhtml templates, and the SPA registry reference decorated classes by their bare name, so
that module-scope binding MUST exist. When it is dropped the class is never bound and the
downstream reference throws a ReferenceError -- a white screen with no build-time signal.

This is a long-standing upstream defect, verified NOT fixed through Babel 8.0.1
(npm latest at time of writing):
- https://github.com/babel/babel/issues/12689 (decorators + class fields)
- https://github.com/evanw/esbuild/issues/3823 (same IIFE-pattern issue in esbuild)

### The workaround this fork retires

Before this fork, `js-transformer-server.js` carried an output-shape matcher: it looked for
the `[_ClassName, _initClass] = _applyDecs(...).c` destructuring shape in the transformed
output and appended `var ClassName = _hash_ClassName;` to re-create the binding.

That matcher was fragile because it depended on the exact output shape. `@babel/compat-data`
7.29.3 moved the transform-destructuring baseline to Safari 14.1. Because preset-env is run
with a Safari 14 target, that change makes preset-env rewrite the `[..] = ..` array-pattern
BEFORE the matcher ran -- the matcher silently missed, the binding was never emitted, and the
page white-screened. The `es5` target was ALWAYS broken this way. Matching the CONTRACT at the
source (this fork) instead of the OUTPUT SHAPE (the retired matcher) is immune to that drift.

## What the patch does

`upstream.patch` adds, in the statics branch of the decorator transform, right after the
class statement is replaced with the `new (...)()` expression: when the source was a class
DECLARATION (`needsDeclarationForClassBinding`, i.e. the binding was expected in scope) with
a named id, emit `var <OriginalName> = <uid>;` immediately after the replaced statement. This
restores exactly the module-scope binding upstream drops -- at the producer, in AST form,
independent of any downstream output shape or preset-env target.

The non-statics / member-decorator / non-decorated paths are untouched: their class
declaration survives normally, so no extra binding is emitted.

## Regeneration (rebasing onto a newer Babel)

`index.js` is produced from a clean npm install of the pinned upstream packages, with
`upstream.patch` applied to `@babel/helper-create-class-features-plugin/lib/decorators.js`,
then bundled with esbuild. To rebuild (e.g. after a Babel upgrade):

1. Clean scratch install (OUTSIDE the repo), pinned to the versions in `package.json`'s
   `rspade.bundled_packages` (update those pins first when moving to a new Babel):

       mkdir /tmp/babel-fork-build && cd /tmp/babel-fork-build
       npm init -y
       npm install @babel/plugin-proposal-decorators@7.29.7

   (installing the plugin pulls its exact-matching helper/syntax closure).

2. Apply the patch to the scratch copy of decorators.js:

       cd node_modules/@babel/helper-create-class-features-plugin
       patch -p1 < /path/to/babel-plugin-decorators/upstream.patch
       cd -

   If the patch does not apply cleanly (upstream refactored the anchor), re-derive it:
   find the statics branch in `lib/decorators.js` -- the two lines

       const [newPath] = path.replaceWith(newExpr);
       originalClassPath = newPath.get("callee").get("body").get("body.0.key");

   -- and insert, immediately after them:

       if (needsDeclarationForClassBinding && originalClass.id) {
         const bindingStmt = newPath.getStatementParent();
         if (bindingStmt) {
           bindingStmt.insertAfter(_core.types.variableDeclaration("var", [_core.types.variableDeclarator(_core.types.identifier(originalClass.id.name), _core.types.cloneNode(classIdLocal))]));
         }
       }

   Then regenerate `upstream.patch` with `diff -u` against a pristine copy.

3. Create an entry file `entry.js`:

       module.exports = require("@babel/plugin-proposal-decorators").default;

4. Bundle with esbuild (from the RSpade repo's committed toolchain):

       /var/www/html/system/node_modules/.bin/esbuild entry.js \
         --bundle --format=cjs --platform=node \
         --external:@babel/core --external:@babel/types --external:@babel/traverse \
         --external:@babel/template --external:@babel/parser \
         --legal-comments=none \
         --outfile=/var/www/html/system/app/RSpade/Core/JsParsers/resource/babel-plugin-decorators/index.js

5. Bump `version` and `rspade.patch_revision` in `package.json`, then run the
   `js_transform` framework test concern to confirm the binding is emitted at every target.

## Babel 8 layout note

In Babel 8 the decorator transform helper is bundled directly into the plugin's own
`lib/index.js` rather than living in a separate `@babel/helper-create-class-features-plugin`
package. When rebasing onto Babel 8, the patch anchor moves into the plugin package itself;
locate the same `path.replaceWith(newExpr)` / `originalClassPath = ...` anchor there and
apply the same insertion. The externalization list and esbuild command are unchanged.
