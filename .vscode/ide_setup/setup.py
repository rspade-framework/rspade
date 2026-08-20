#!/usr/bin/env python3
"""
RSX Formatter Setup Script
Runs on first open to guide users through setup
"""

import os
import sys
import json
import subprocess
import platform
from pathlib import Path


def check_first_run():
    """Check if this is the first time setup is running"""
    setup_file = Path(__file__).parent / '.setup_complete'
    return not setup_file.exists()


def mark_setup_complete():
    """Mark that setup has been completed"""
    setup_file = Path(__file__).parent / '.setup_complete'
    setup_file.touch()


def check_extension_installed():
    """Check if Run on Save extension is installed"""
    # This is tricky to detect programmatically
    # We'll provide instructions instead
    return None


def show_welcome_message():
    """Show welcome message and setup instructions"""
    print("🚀 Welcome to RSX Framework!")
    print("="*60)
    print("\nThis appears to be your first time opening this project.")
    print("Let's set up the automatic code formatting tools.\n")
    

def prompt_extension_install():
    """Prompt user to install the required VS Code extension"""
    print("📦 Step 1: Install Required VS Code Extension")
    print("-"*60)
    print("\nThe RSX formatter requires the 'Run on Save' extension.")
    print("\nTo install it:")
    print("1. Press Ctrl+Shift+X (Cmd+Shift+X on Mac) to open Extensions")
    print("2. Search for: emeraldwalk.runonsave")
    print("3. Click Install")
    print("\nAlternatively, VS Code should prompt you to install")
    print("recommended extensions. Click 'Install All' when prompted.\n")
    
    input("Press Enter after installing the extension...")
    

def run_dependency_check():
    """Run the dependency checker"""
    print("\n🔍 Step 2: Checking System Dependencies")
    print("-"*60)
    print()
    
    checker_path = Path(__file__).parent / 'check_dependencies.py'
    result = subprocess.run([sys.executable, str(checker_path)])
    
    if result.returncode != 0:
        print("\n⚠️  Some dependencies are missing.")
        print("Please install them using the instructions above.\n")
        return False
    return True


def create_local_settings():
    """Create local VS Code settings if needed"""
    workspace_root = Path(__file__).parent.parent.parent
    local_settings_dir = workspace_root / '.vscode' / 'settings.local.json'
    
    # Check if we need to adjust Python command for Windows
    if platform.system() == 'Windows':
        # Check if python3 exists, if not, we might need to use 'python'
        try:
            subprocess.run(['python3', '--version'], capture_output=True)
            python_cmd = 'python3'
        except:
            python_cmd = 'python'
            
        if python_cmd == 'python':
            print("\n💡 Tip: On Windows, you might need to adjust the Python command.")
            print(f"   Current command: python3")
            print(f"   If formatting doesn't work, change it to: python")
            print(f"   In settings.json, update the emeraldwalk.runonsave command.\n")


def test_formatter():
    """Offer to test the formatter"""
    print("\n✨ Step 3: Test the Formatter")
    print("-"*60)
    print("\nWould you like to test the formatter now?")
    response = input("Test formatter? (y/n): ").lower().strip()
    
    if response == 'y':
        print("\n1. Open any PHP file in /app/ or /rsx/ directory")
        print("2. Add some messy formatting (extra spaces, bad indentation)")
        print("3. Save the file (Ctrl+S / Cmd+S)")
        print("4. The file should be automatically formatted!\n")
        print("You can also run the formatter manually:")
        print("  python3 .vscode/formatters/orchestrator.py path/to/file.php\n")


def show_quick_reference():
    """Show quick reference guide"""
    print("\n📋 Quick Reference")
    print("="*60)
    print("\nFormatter Behavior:")
    print("  • /app/*.php  → Laravel Pint formatting only")
    print("  • /rsx/*.php  → Full RSX formatting (namespace, use statements, Pint)")
    print("  • Other files → No formatting\n")
    
    print("VS Code Commands (Ctrl+Shift+P / Cmd+Shift+P):")
    print("  • 'Tasks: Run Task' → 'RSX: Check Formatter Dependencies'")
    print("  • 'Tasks: Run Task' → 'RSX: Test PHP Formatter'\n")
    
    print("Manual Commands:")
    print("  • Check deps:  python3 .vscode/formatters/check_dependencies.py")
    print("  • Format file: python3 .vscode/formatters/orchestrator.py <file>\n")


def main():
    """Main setup flow"""
    if not check_first_run():
        # Not first run, just do a quick dependency check
        print("🔍 Running dependency check...")
        checker_path = Path(__file__).parent / 'check_dependencies.py'
        subprocess.run([sys.executable, str(checker_path)])
        return
    
    # First run - show full setup
    show_welcome_message()
    
    # Step 1: Extension
    prompt_extension_install()
    
    # Step 2: Dependencies
    deps_ok = run_dependency_check()
    
    # Step 3: Local settings
    create_local_settings()
    
    # Step 4: Test
    if deps_ok:
        test_formatter()
    
    # Show quick reference
    show_quick_reference()
    
    # Mark setup as complete
    mark_setup_complete()
    
    print("\n✅ Setup Complete!")
    print("="*60)
    print("\nThe RSX formatter is now configured.")
    print("Happy coding! 🎉\n")


if __name__ == '__main__':
    main()