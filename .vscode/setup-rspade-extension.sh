#!/bin/bash

# RSpade Extension Setup Script
# This script automatically sets up the RSpade VS Code extension

echo "RSpade VS Code Extension Setup"
echo "=============================="

# Detect if we're in VS Code
if [ -n "$VSCODE_PID" ]; then
    echo "Running inside VS Code"
fi

# Check if the extension is already installed
if code --list-extensions 2>/dev/null | grep -q "rspade.rspade-framework"; then
    echo "✓ RSpade extension is already installed"
    exit 0
fi

# Check for development mode (unpackaged extension)
EXTENSION_DIR="/var/www/html/app/RSpade/Extension"
if [ -d "$EXTENSION_DIR" ] && [ -f "$EXTENSION_DIR/package.json" ]; then
    echo "Found RSpade extension source at: $EXTENSION_DIR"
    
    # Check if we're in development mode (no .vsix file exists)
    if ! ls "$EXTENSION_DIR"/*.vsix >/dev/null 2>&1; then
        echo ""
        echo "Extension not packaged. Options:"
        echo "1. Development mode: Create symlink to VS Code extensions folder"
        echo "2. Production mode: Package and install the extension"
        echo ""
        read -p "Choose mode (1 for development, 2 for production): " choice
        
        case $choice in
            1)
                # Development mode - create symlink
                VSCODE_EXTENSIONS_DIR="$HOME/.vscode/extensions"
                if [ "$(uname)" = "Darwin" ]; then
                    VSCODE_EXTENSIONS_DIR="$HOME/.vscode/extensions"
                elif [ -n "$APPDATA" ]; then
                    # Windows (Git Bash)
                    VSCODE_EXTENSIONS_DIR="$APPDATA/Code/User/extensions"
                fi
                
                mkdir -p "$VSCODE_EXTENSIONS_DIR"
                LINK_NAME="$VSCODE_EXTENSIONS_DIR/rspade.rspade-framework-dev"
                
                # Remove existing symlink if present
                rm -rf "$LINK_NAME"
                
                # Create symlink
                ln -s "$EXTENSION_DIR" "$LINK_NAME"
                
                echo "✓ Created development symlink: $LINK_NAME"
                echo ""
                echo "Next steps:"
                echo "1. Reload VS Code window (Ctrl+R or Cmd+R)"
                echo "2. The extension will load from source"
                echo ""
                echo "For development:"
                echo "- Edit TypeScript files in $EXTENSION_DIR/src/"
                echo "- Run 'npm run compile' in the extension directory"
                echo "- Reload VS Code to test changes"
                ;;
            2)
                # Production mode - package and install
                cd "$EXTENSION_DIR"
                if [ -f "install.sh" ]; then
                    ./install.sh
                else
                    echo "Error: install.sh not found"
                    exit 1
                fi
                ;;
            *)
                echo "Invalid choice"
                exit 1
                ;;
        esac
    else
        # .vsix file exists, install it
        VSIX_FILE=$(ls -t "$EXTENSION_DIR"/*.vsix | head -n1)
        echo "Installing packaged extension: $VSIX_FILE"
        code --install-extension "$VSIX_FILE"
    fi
else
    echo "Error: Extension source not found at $EXTENSION_DIR"
    echo "Please ensure the RSpade framework is properly installed"
    exit 1
fi