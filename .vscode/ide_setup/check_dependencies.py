#!/usr/bin/env python3
"""
Dependency checker for RSX formatters
Checks for required tools and provides installation instructions
"""

import subprocess
import sys
import platform
import json
import os


class DependencyChecker:
    def __init__(self):
        self.os_type = platform.system()
        self.missing_deps = []
        self.warnings = []
        
    def check_command(self, command, version_flag='--version'):
        """Check if a command is available"""
        try:
            result = subprocess.run(
                [command, version_flag],
                capture_output=True,
                text=True
            )
            if result.returncode == 0:
                version = result.stdout.strip().split('\n')[0]
                return True, version
            return False, None
        except FileNotFoundError:
            return False, None
        except Exception as e:
            return False, str(e)
            
    def check_python(self):
        """Check Python installation"""
        # Try python3 first, then python
        for cmd in ['python3', 'python']:
            found, version = self.check_command(cmd)
            if found:
                # Check version
                try:
                    major = int(version.split()[1].split('.')[0])
                    if major >= 3:
                        return True, cmd, version
                except:
                    pass
        
        self.missing_deps.append({
            'name': 'Python 3',
            'command': 'python3',
            'required': True
        })
        return False, None, None
        
    def check_php(self):
        """Check PHP installation"""
        found, version = self.check_command('php')
        if not found:
            self.missing_deps.append({
                'name': 'PHP',
                'command': 'php',
                'required': True
            })
        return found, version
        
    def check_node(self):
        """Check Node.js installation"""
        found, version = self.check_command('node')
        if not found:
            self.missing_deps.append({
                'name': 'Node.js',
                'command': 'node',
                'required': False  # Optional for now
            })
        return found, version
        
    def check_composer_packages(self):
        """Check if Laravel Pint is installed"""
        composer_lock = os.path.join(os.path.dirname(os.path.dirname(os.path.dirname(__file__))), 'composer.lock')
        if os.path.exists(composer_lock):
            try:
                with open(composer_lock, 'r') as f:
                    data = json.load(f)
                    
                packages = data.get('packages-dev', []) + data.get('packages', [])
                pint_found = any(p['name'] == 'laravel/pint' for p in packages)
                
                if not pint_found:
                    self.warnings.append({
                        'name': 'Laravel Pint',
                        'message': 'Laravel Pint is not installed',
                        'fix': 'Run: composer require laravel/pint --dev'
                    })
            except:
                pass
                
    def check_vscode_extensions(self):
        """Check VS Code extensions"""
        # Check if we're in VS Code
        if 'VSCODE_PID' in os.environ or 'TERM_PROGRAM' in os.environ:
            # We're in VS Code terminal
            self.warnings.append({
                'name': 'VS Code Extensions',
                'message': 'Please ensure "Run on Save" extension is installed',
                'fix': 'Install from Extensions panel: emeraldwalk.runonsave'
            })
            
    def get_installation_instructions(self):
        """Get OS-specific installation instructions"""
        instructions = []
        
        if not self.missing_deps:
            return instructions
            
        if self.os_type == 'Windows':
            instructions.append("# Windows Installation (using Chocolatey)")
            instructions.append("# First install Chocolatey if needed:")
            instructions.append("# https://chocolatey.org/install")
            instructions.append("")
            
            choco_packages = []
            for dep in self.missing_deps:
                if dep['command'] == 'python3':
                    choco_packages.append('python3')
                elif dep['command'] == 'php':
                    choco_packages.append('php')
                elif dep['command'] == 'node':
                    choco_packages.append('nodejs')
                    
            if choco_packages:
                instructions.append(f"choco install {' '.join(choco_packages)} -y")
                
        elif self.os_type == 'Darwin':  # macOS
            instructions.append("# macOS Installation (using Homebrew)")
            instructions.append("# First install Homebrew if needed:")
            instructions.append("# /bin/bash -c \"$(curl -fsSL https://raw.githubusercontent.com/Homebrew/install/HEAD/install.sh)\"")
            instructions.append("")
            
            brew_packages = []
            for dep in self.missing_deps:
                if dep['command'] == 'python3':
                    brew_packages.append('python@3')
                elif dep['command'] == 'php':
                    brew_packages.append('php')
                elif dep['command'] == 'node':
                    brew_packages.append('node')
                    
            if brew_packages:
                instructions.append(f"brew install {' '.join(brew_packages)}")
                
        else:  # Linux
            instructions.append("# Linux Installation (Ubuntu/Debian)")
            instructions.append("sudo apt update")
            
            apt_packages = []
            for dep in self.missing_deps:
                if dep['command'] == 'python3':
                    apt_packages.append('python3 python3-pip')
                elif dep['command'] == 'php':
                    apt_packages.append('php-cli')
                elif dep['command'] == 'node':
                    apt_packages.append('nodejs npm')
                    
            if apt_packages:
                instructions.append(f"sudo apt install {' '.join(apt_packages)} -y")
                
        return instructions
        
    def run_checks(self):
        """Run all checks"""
        print("🔍 Checking RSX Formatter Dependencies...\n")
        
        # Check each dependency
        py_found, py_cmd, py_version = self.check_python()
        if py_found:
            print(f"✅ Python 3: {py_version}")
        else:
            print("❌ Python 3: Not found")
            
        php_found, php_version = self.check_php()
        if php_found:
            print(f"✅ PHP: {php_version}")
        else:
            print("❌ PHP: Not found")
            
        node_found, node_version = self.check_node()
        if node_found:
            print(f"✅ Node.js: {node_version} (optional)")
        else:
            print("⚠️  Node.js: Not found (optional for JS/CSS formatting)")
            
        # Check Composer packages
        self.check_composer_packages()
        
        # Check VS Code extensions
        self.check_vscode_extensions()
        
        # Report results
        print("\n" + "="*60 + "\n")
        
        required_missing = [d for d in self.missing_deps if d['required']]
        optional_missing = [d for d in self.missing_deps if not d['required']]
        
        if not required_missing and not self.warnings:
            print("✅ All required dependencies are installed!")
            print("\n🎉 The RSX formatters are ready to use!")
            return True
            
        if required_missing:
            print("❌ Missing required dependencies:\n")
            for dep in required_missing:
                print(f"  - {dep['name']}")
                
        if optional_missing:
            print("\n⚠️  Missing optional dependencies:\n")
            for dep in optional_missing:
                print(f"  - {dep['name']}")
                
        if self.warnings:
            print("\n⚠️  Warnings:\n")
            for warning in self.warnings:
                print(f"  - {warning['message']}")
                if 'fix' in warning:
                    print(f"    Fix: {warning['fix']}")
                    
        # Show installation instructions
        instructions = self.get_installation_instructions()
        if instructions:
            print("\n📦 Installation Instructions:")
            print("="*60)
            for line in instructions:
                print(line)
                
        print("\n" + "="*60)
        print("\nAfter installing dependencies:")
        print("1. Restart VS Code")
        print("2. Run this check again: python3 .vscode/formatters/check_dependencies.py")
        
        return False


def main():
    checker = DependencyChecker()
    success = checker.run_checks()
    
    # Create a status file for VS Code to read
    status_file = os.path.join(os.path.dirname(__file__), '.deps_status.json')
    status = {
        'checked': True,
        'success': success,
        'missing_required': [d for d in checker.missing_deps if d['required']],
        'missing_optional': [d for d in checker.missing_deps if not d['required']],
        'warnings': checker.warnings
    }
    
    with open(status_file, 'w') as f:
        json.dump(status, f, indent=2)
        
    sys.exit(0 if success else 1)


if __name__ == '__main__':
    main()