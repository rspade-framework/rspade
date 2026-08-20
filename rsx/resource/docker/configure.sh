#!/bin/bash

# RSX Project Docker Container Configuration
#
# This script is executed during Docker container startup, before the web server starts.
# Use this file to install additional packages or perform initialization tasks specific
# to your RSX application.
#
# Examples:
# - Install system packages: apt-get update && apt-get install -y package-name
# - Install PHP extensions: docker-php-ext-install extension-name
# - Initialize application services
# - Run database seeders or migrations
# - Configure environment-specific settings
#
# The script runs as root with full system access.

# Exit on any error
set -e

# Add your custom initialization commands below:
