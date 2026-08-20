# Docker Configuration

## Purpose

This directory contains Docker-specific configuration for RSX applications.

## configure.sh

**Executed**: During Docker container startup, before the web server starts
**Runs as**: Root user with full system access
**Purpose**: Install packages and perform initialization tasks specific to your application

### Common Use Cases

- Installing system packages (e.g., image processing libraries, additional utilities)
- Installing PHP extensions not included in the base image
- Initializing application services
- Running database migrations or seeders
- Configuring environment-specific settings

### Example

```bash
#!/bin/bash
set -e

# Install ImageMagick for image processing
apt-get update && apt-get install -y imagemagick

# Install PHP Redis extension
docker-php-ext-install redis

# Run migrations on first startup
php artisan migrate --force
```

## Notes

- The script must be executable (`chmod +x configure.sh`)
- Use `set -e` to exit on any error
- The framework's base Docker image already includes common dependencies
- Only add application-specific requirements here
