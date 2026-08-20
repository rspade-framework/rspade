# Config Directory

This CLAUDE.md file contains a brief synopsis of the purpose of this directory, then a list of files in this directory with the file sizes of each file, and a short description and relevant key points of information for every file which is important in this directory. Unimportant files like images or temporary data directories are not listed in this file. When visiting this directory, the AI agent is instructed to do an ls on the directory to get the directory contents and file sizes - and if the file size diverges from the size in CLAUDE.md, that means the file has changed, and the description in CLAUDE.md is not up to date. This doesn't trigger this to be regenerated immediately, but let's say we wanted to know about a specific file by viewing CLAUDE.md and we discovered it was out of date, we would need to reread and update the documentation for that file in the CLAUDE.md at that time before we considered any details about it. CLAUDE.md might also contain other bits of information that is critical to know if you are looking at notes in the directory where the CLAUDE.md file lives.

## Directory Purpose

The Config directory contains Laravel configuration files that define how the application behaves. These files are loaded by the application during bootstrap and provide settings for everything from database connections to session handling. Many of these configuration values can be overridden with environment variables via the .env file.

## File Index

| File | Size | Description |
|------|------|-------------|
| app.php | 7,836 bytes | Core application configuration including application name ('RSpade'), environment settings, debug mode, timezone (UTC), and service providers. Registers all service providers and class aliases used throughout the application. |
| auth.php | 3,665 bytes | Authentication configuration with web guard as default, App\Models\User model, 60-minute password reset timeout, and 3-hour password confirmation window. Defines authentication guards, user providers, and password reset options. |
| broadcasting.php | 2,091 bytes | Event broadcasting configuration supporting Pusher, Ably, Redis, and other drivers. Used for real-time events and notifications. |
| cache.php | 3,272 bytes | Cache system settings with support for file, database, Redis, Memcached, and other cache stores. Configures default cache driver and store options. |
| cors.php | 846 bytes | Cross-Origin Resource Sharing settings for API routes, specifying allowed origins, methods, and headers for cross-domain requests. |
| database.php | 5,289 bytes | Database connection settings for MySQL, PostgreSQL, SQLite, and SQL Server, plus Redis configuration. Defines connection parameters, pool settings, and migration table name. |
| filesystems.php | 2,370 bytes | File storage configuration with local, public, and S3 disk options. Sets up where uploaded files, public assets, and private data are stored. |
| hashing.php | 1,572 bytes | Password hashing settings with bcrypt and Argon2 options, defining algorithm and computational costs for secure password storage. |
| logging.php | 3,749 bytes | Application logging setup with stack, single file, daily, Slack, and other channels. Configures how and where application logs are stored. |
| mail.php | 3,600 bytes | Email service configuration supporting SMTP, Mailgun, SES, and other mail drivers. Defines mail sender information and delivery settings. |
| models.php | 480 bytes | Custom registry of application models used for dynamic model loading and auto-discovery. |
| multi-tenant.php | 2,631 bytes | Custom multi-tenant system configuration with single-user tenant mode option, 48-hour invitation expiry, session key for current site ID ('current_site_id'), and site creation permissions. |
| queue.php | 2,906 bytes | Queue system configuration with sync, database, Redis, SQS, and other queue drivers for asynchronous task processing. |
| rspade.php | 1,223 bytes | RSpade application-specific settings including app name, description, 10 items per page default, todo list limits, and feature toggles for public profiles, sharing, and markdown support. |
| sanctum.php | 2,294 bytes | Laravel Sanctum configuration for API token authentication with token expiration and middleware settings. |
| services.php | 2,668 bytes | Third-party service credentials for Mailgun, Postmark, AWS, and Twilio. |
| session.php | 7,079 bytes | Session handling configuration with 365-day session lifetime, HTTP-only cookies, and 'lax' same-site policy. Defines session driver, storage, and cookie settings. |
| view.php | 1,053 bytes | View compilation path settings, defining where compiled Blade templates are stored. |

## Implementation Notes

1. **Custom Configuration Files**:
   - **models.php**: Application-specific model registry
   - **multi-tenant.php**: Multi-tenant functionality configuration
   - **rspade.php**: Application-specific settings for the RSpade platform

2. **Configuration Loading**:
   - Configuration values are accessed using the `config()` helper
   - Environment-specific values can override config files via `.env`
   - Configuration is cached in production using `php artisan config:cache`

3. **Key Configuration Patterns**:
   - Service providers are registered in app.php
   - Third-party API keys are stored in services.php
   - Feature toggles are defined in rspade.php
   - Most files follow Laravel's standard configuration structure
   - Default values are provided for all settings with environment variable fallbacks