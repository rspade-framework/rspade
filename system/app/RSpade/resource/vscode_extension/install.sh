#!/bin/bash
#
# Install the RSpade editor extensions into VS Code.
#
#     bash install.sh
#
# Two extensions, and both are wanted:
#
#   jqhtml   - syntax highlighting and language support for .jqhtml templates.
#              Ships PACKAGED in vendor/, so nothing is built and no registry is
#              contacted. (It used to arrive as the npm package
#              @jqhtml/vscode-extension - an editor artifact as a runtime
#              dependency of the application, which nothing ever loaded. See
#              vendor/README.md, and backlog B-98 for putting it on the
#              Marketplace where it belongs.)
#
#   rspade   - this directory's own extension: goto-definition across RSX
#              classes, LLMDIRECTIVE folding, namespace tooling. Built from
#              source here, because this is where its source lives.
#
# Run it with `bash install.sh`, never as a bare path: the exec bit does not
# survive every checkout.
#
set -u

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)" || exit 1
cd "$SCRIPT_DIR" || { echo "ERROR: cannot enter $SCRIPT_DIR" >&2; exit 1; }

say()  { echo "[extensions] $*"; }
warn() { echo "[extensions] WARNING: $*" >&2; }
die()  { echo "[extensions] ERROR: $*" >&2; exit 1; }

# -----------------------------------------------------------------------------
# The editor CLI
# -----------------------------------------------------------------------------
CODE_BIN="${VSCODE_BIN:-code}"

if ! command -v "$CODE_BIN" >/dev/null 2>&1; then
    die "the '$CODE_BIN' command is not on PATH.

  VS Code ships it but does not always add it: open VS Code, run
  'Shell Command: Install code command in PATH' from the command palette.

  Using a different editor build? Point this at it:
      VSCODE_BIN=code-insiders bash install.sh"
fi

install_vsix() {
    local label="$1" path="$2"

    [ -f "$path" ] || { warn "$label: $path is missing - skipped."; return 1; }

    say "Installing $label..."
    if "$CODE_BIN" --install-extension "$path" --force; then
        say "  $label installed."
        return 0
    fi

    warn "$label failed to install. Install it by hand: $path"
    return 1
}

# -----------------------------------------------------------------------------
# 1. jqhtml - vendored, nothing to build
# -----------------------------------------------------------------------------
JQHTML_VSIX="$(ls -t "$SCRIPT_DIR"/vendor/jqhtml-vscode-extension-*.vsix 2>/dev/null | head -n1)"

if [ -n "$JQHTML_VSIX" ]; then
    install_vsix "jqhtml" "$JQHTML_VSIX"
else
    warn "no jqhtml .vsix found in vendor/ - jqhtml template highlighting will be unavailable."
fi

# -----------------------------------------------------------------------------
# 2. rspade - built from the source beside this script
# -----------------------------------------------------------------------------
# Only attempted when the toolchain is present. A missing npm is not an error
# worth failing the whole run over: the jqhtml extension above is already
# installed, and that is the half most people came for.
if ! command -v npm >/dev/null 2>&1; then
    warn "npm is not installed, so the RSpade extension cannot be built. Skipping it."
    say "Done."
    exit 0
fi

say "Building the RSpade extension..."

npm install --silent  || die "npm install failed in $SCRIPT_DIR"
npm run compile       || die "the extension failed to compile"

if ! command -v vsce >/dev/null 2>&1; then
    say "Installing vsce (the extension packager)..."
    npm install -g vsce --silent || die "could not install vsce"
fi

vsce package --no-dependencies || die "vsce could not package the extension"

RSPADE_VSIX="$(ls -t "$SCRIPT_DIR"/*.vsix 2>/dev/null | head -n1)"
[ -n "$RSPADE_VSIX" ] || die "packaging reported success but produced no .vsix"

install_vsix "rspade" "$RSPADE_VSIX"

say "Done. Reload VS Code to activate."
