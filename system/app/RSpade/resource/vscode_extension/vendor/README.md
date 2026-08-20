# Vendored editor extensions

Packaged `.vsix` files that ship with the framework rather than being installed
from a registry.

## jqhtml-vscode-extension

Syntax highlighting and language support for `.jqhtml` templates. RSpade's own
extension looks this one up by extension id and uses its API for component
navigation, so the two are companions - RSpade's works without it, but with
reduced capability.

**Why it lives here instead of npm.** It used to arrive as the npm package
`@jqhtml/vscode-extension`, which meant an editor artifact was a runtime
dependency of the application: `npm install` fetched a `.vsix` that nothing in
the application would ever load. That package is not on public npm, so keeping
it in the manifest would have made a public `npm install` fail on a file the
application does not use.

Vendoring it removes the dependency entirely and makes what is actually
happening obvious: this is a file the framework carries, and `install.sh`
installs it into your editor.

**This is a stopgap.** The proper home for an editor extension is the VS Code
Marketplace, where it can be discovered, versioned and updated by the editor
itself - see backlog **B-98**. Until then, updating it means replacing the
`.vsix` here and pointing `install.sh` at the new filename.

## Installing

    bash ../install.sh

That installs both this extension and RSpade's own.
