# Providers Directory

This CLAUDE.md file contains a brief synopsis of the purpose of this directory, then a list of files in this directory with the file sizes of each file, and a short description and relevant key points of information for every file which is important in this directory. Unimportant files like images or temporary data directories are not listed in this file. When visiting this directory, the AI agent is instructed to do an ls on the directory to get the directory contents and file sizes - and if the file size diverges from the size in CLAUDE.md, that means the file has changed, and the description in CLAUDE.md is not up to date. This doesn't trigger this to be regenerated immediately, but let's say we wanted to know about a specific file by viewing CLAUDE.md and we discovered it was out of date, we would need to reread and update the documentation for that file in the CLAUDE.md at that time before we considered any details about it. CLAUDE.md might also contain other bits of information that is critical to know if you are looking at notes in the directory where the CLAUDE.md file lives.

## Directory Purpose

The Providers directory contains Laravel service providers which are the central configuration mechanism in Laravel applications. Service providers bootstrap the application by binding services in the service container, registering events, middleware, routes, and configuration.

## File Index

| File | Size | Description |
|------|------|-------------|
| AppServiceProvider.php | 940 bytes | General application service provider that refreshes session lifetime with each page request and shares constants (states and date formats) with all views. |
| AuthServiceProvider.php | 751 bytes | Authentication provider that defines model-to-policy mappings for authorization, registers Bridge and Talkgroup policies, and calls registerPolicies() to load these mappings. |
| BroadcastServiceProvider.php | 380 bytes | Broadcasting provider that registers routes for broadcasting and loads the routes/channels.php file for channel authorization. |
| EventServiceProvider.php | 926 bytes | Event handler provider that maps the Registered event to SendEmailVerificationNotification listener and disables automatic event discovery. |
| RouteServiceProvider.php | 1,452 bytes | Route configuration provider that defines HOME constant for redirection after authentication, configures rate limiting for API routes, and loads API, multi-tenant, and RSpade routes with appropriate middleware. |

## Implementation Notes

1. All service providers follow the Laravel provider pattern with register() and boot() methods.
2. The register() method happens before all providers are loaded, while boot() happens after all providers are loaded.
3. The RouteServiceProvider has been extended to support multi-tenant and RSpade route files.
4. AppServiceProvider handles session management and constants sharing with views.