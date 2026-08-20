#!/usr/bin/env python3
"""
Check formatter setup when VS Code opens
Creates a notification file if setup is needed
"""

import os
import json
import subprocess
import sys
from pathlib import Path


def check_setup_needed():
    """Check if setup is needed"""
    issues = []
    
    # Check if setup has been completed
    setup_file = Path(__file__).parent / '.setup_complete'
    if not setup_file.exists():
        issues.append({
            'type': 'first_run',
            'message': 'First time setup needed',
            'action': 'Run setup wizard'
        })
    
    # Quick dependency check
    try:
        # Check Python
        subprocess.run(['python3', '--version'], capture_output=True, check=True)
    except:
        try:
            subprocess.run(['python', '--version'], capture_output=True, check=True)
        except:
            issues.append({
                'type': 'missing_dep',
                'message': 'Python not found',
                'action': 'Install Python 3'
            })
    
    try:
        # Check PHP
        subprocess.run(['php', '--version'], capture_output=True, check=True)
    except:
        issues.append({
            'type': 'missing_dep',
            'message': 'PHP not found',
            'action': 'Install PHP'
        })
    
    # Check if Pint is installed
    composer_lock = Path(__file__).parent.parent.parent / 'composer.lock'
    if composer_lock.exists():
        try:
            with open(composer_lock, 'r') as f:
                data = json.load(f)
            packages = data.get('packages-dev', []) + data.get('packages', [])
            if not any(p['name'] == 'laravel/pint' for p in packages):
                issues.append({
                    'type': 'missing_package',
                    'message': 'Laravel Pint not installed',
                    'action': 'Run: composer require laravel/pint --dev'
                })
        except:
            pass
    
    return issues


def create_notification():
    """Create a notification for VS Code to display"""
    issues = check_setup_needed()
    
    notification_file = Path(__file__).parent.parent.parent / '.vscode' / '.formatter_notification.json'
    
    if issues:
        notification = {
            'show': True,
            'issues': issues,
            'message': 'RSX Formatter setup required',
            'actions': [
                {
                    'label': 'Run Setup',
                    'task': 'RSX: Setup Formatter (First Run)'
                },
                {
                    'label': 'Check Dependencies',
                    'task': 'RSX: Check Formatter Dependencies'
                }
            ]
        }
    else:
        notification = {
            'show': False,
            'message': 'RSX Formatter is ready'
        }
    
    with open(notification_file, 'w') as f:
        json.dump(notification, f, indent=2)
    
    return len(issues) > 0


def main():
    """Main entry point"""
    has_issues = create_notification()
    
    if has_issues:
        print("⚠️  RSX Formatter setup required")
        print("Run 'Tasks: Run Task' → 'RSX: Setup Formatter' to get started")
    else:
        # Silent success
        pass


if __name__ == '__main__':
    main()