#!/bin/bash
# Setup script for RSX JavaScript parser

echo "Setting up JavaScript parser dependencies..."

# Check if node is installed
if ! command -v node &> /dev/null; then
    echo "Node.js is not installed. Please install Node.js first."
    exit 1
fi

# Check if npm is installed
if ! command -v npm &> /dev/null; then
    echo "npm is not installed. Please install npm first."
    exit 1
fi

# Navigate to parser directory
cd "$(dirname "$0")"

# Install dependencies
echo "Installing Babel parser dependencies..."
npm install

echo "JavaScript parser setup complete!"