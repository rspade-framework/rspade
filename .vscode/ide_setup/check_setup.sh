#!/bin/bash

echo "RSpade Extension Installer"
echo ""

SCRIPT_DIR="$( cd "$( dirname "${BASH_SOURCE[0]}" )" && pwd )"
PROJECT_ROOT="$(dirname "$(dirname "$SCRIPT_DIR")")"
needs_reload=false

# Check for workspace template and copy if needed
WORKSPACE_DIST="$PROJECT_ROOT/rspade.code-workspace.dist"
WORKSPACE_FILE="$PROJECT_ROOT/rspade.code-workspace"

if [ -f "$WORKSPACE_DIST" ] && [ ! -f "$WORKSPACE_FILE" ]; then
    echo "Creating workspace file from template..."
    cp "$WORKSPACE_DIST" "$WORKSPACE_FILE"
fi

# RSPADE EXTENSION
# Find expected version
rspade_path="$(dirname "$(dirname "$SCRIPT_DIR")")/system/app/RSpade/resource/vscode_extension"
rspade_vsix="$rspade_path/rspade-framework.vsix"
expected_version=$(node -p "require('$rspade_path/package.json').version")

echo "RSpade expected version: $expected_version"

# Check installed version
installed_extensions=$(code --list-extensions --show-versions)
installed_version=$(echo "$installed_extensions" | grep -E "^rspade\.rspade-framework@" | cut -d'@' -f2)

echo "RSpade installed version: $installed_version"

needs_install=false

if [ -n "$installed_version" ]; then
    if [ "$installed_version" != "$expected_version" ]; then
        echo "Outdated - uninstalling"
        code --uninstall-extension rspade.rspade-framework
        needs_install=true
    else
        echo "Already up to date"
    fi
else
    echo "Not installed"
    needs_install=true
fi

if [ "$needs_install" = true ]; then
    echo "Installing $rspade_vsix"
    code --install-extension "$rspade_vsix" --force
    needs_reload=true
fi

# JQHTML EXTENSION
# Find expected version
jqhtml_path="$(dirname "$(dirname "$SCRIPT_DIR")")/system/node_modules/@jqhtml/vscode-extension"
jqhtml_vsix=$(ls "$jqhtml_path"/jqhtml-vscode-extension-*.vsix 2>/dev/null | head -1)
vsix_filename=$(basename "$jqhtml_vsix")
expected_version=$(echo "$vsix_filename" | sed -E 's/jqhtml-vscode-extension-(.+)\.vsix$/\1/')

echo "JQHTML expected version: $expected_version"

# Check installed version
installed_extensions=$(code --list-extensions --show-versions)
installed_version=$(echo "$installed_extensions" | grep -E "^jqhtml\.jqhtml-vscode-extension@" | cut -d'@' -f2)

echo "JQHTML installed version: $installed_version"

needs_install=false

if [ -n "$installed_version" ]; then
    if [ "$installed_version" != "$expected_version" ]; then
        echo "Outdated - uninstalling"
        code --uninstall-extension jqhtml.jqhtml-vscode-extension
        needs_install=true
    else
        echo "Already up to date"
    fi
else
    echo "Not installed"
    needs_install=true
fi

if [ "$needs_install" = true ]; then
    echo "Installing $jqhtml_vsix"
    code --install-extension "$jqhtml_vsix" --force
    needs_reload=true
fi

if [ "$needs_reload" = true ]; then
    echo ""
    echo "Extensions have been installed."
    echo "If the window does not reload automatically, press Ctrl+Shift+P and select 'Reload Window'"

    marker_file="$(dirname "$SCRIPT_DIR")/.rspade-extension-updated"
    touch "$marker_file"
else
    # Check if workspace file exists and we're in folder mode
    if [ -f "$WORKSPACE_FILE" ]; then
        # Check if we're already in workspace mode
        current_workspace="$VSCODE_WORKSPACE_FILE"

        # Normalize paths for comparison (resolve to absolute)
        workspace_file_resolved="$(cd "$(dirname "$WORKSPACE_FILE")" && pwd)/$(basename "$WORKSPACE_FILE")"
        current_workspace_resolved=""
        if [ -n "$current_workspace" ] && [ -f "$current_workspace" ]; then
            current_workspace_resolved="$(cd "$(dirname "$current_workspace")" && pwd)/$(basename "$current_workspace")"
        fi

        # Only switch if not already in this workspace
        if [ -z "$current_workspace_resolved" ] || [ "$current_workspace_resolved" != "$workspace_file_resolved" ]; then
            # Close this window and open workspace
            code --reuse-window "$WORKSPACE_FILE"
        fi
    fi

    echo ""
    echo "RSpade extensions valid"
    sleep 1
fi
