# Routes Directory

This CLAUDE.md file contains a brief synopsis of the purpose of this directory, then a list of files in this directory with the file sizes of each file, and a short description and relevant key points of information for every file which is important in this directory. Unimportant files like images or temporary data directories are not listed in this file. When visiting this directory, the AI agent is instructed to do an ls on the directory to get the directory contents and file sizes - and if the file size diverges from the size in CLAUDE.md, that means the file has changed, and the description in CLAUDE.md is not up to date. This doesn't trigger this to be regenerated immediately, but let's say we wanted to know about a specific file by viewing CLAUDE.md and we discovered it was out of date, we would need to reread and update the documentation for that file in the CLAUDE.md at that time before we considered any details about it. CLAUDE.md might also contain other bits of information that is critical to know if you are looking at notes in the directory where the CLAUDE.md file lives.

## Directory Purpose

The Routes directory contains Laravel route definition files that map URLs to controllers or closures. These files determine how the application responds to HTTP requests and organize the application's API, web, and console endpoints.

## File Index

| File | Size | Description |
|------|------|-------------|
| web.php | 11,500 bytes | All routes that render HTML pages or handle form submissions. Organized into sections for public routes, authenticated routes, user routes, site-specific routes, admin routes, and root administration routes. Uses 'web' middleware group for session support and CSRF protection. |
| ajax_api.php | 1,900 bytes | Routes for AJAX requests made by client-side JavaScript. Uses 'web' middleware for session/CSRF protection and are accessed via the `/ajax` prefix. Organized by functionality (notifications, messages, todos, etc.). |
| user_api.php | 1,700 bytes | External API routes for clients to interact with the application programmatically. Uses 'api' middleware and Sanctum for authentication. Accessed via the `/api` prefix and handles model operations and file management. |
| channels.php | 558 bytes | Defines broadcast channels for real-time events, currently containing a single user-specific channel for private broadcasting. Used with Laravel's event broadcasting system. |
| console.php | 592 bytes | Defines custom Artisan console commands that can be run from the command line. Currently contains only the default inspirational quote command. |

## Implementation Notes

1. **Route Organization**:
   - Routes are organized by client type (web browser, JavaScript, external API)
   - The RouteServiceProvider in app/Providers loads these files with appropriate middleware
   - Web routes (web.php) are further organized by access level and context

2. **Route Patterns**:
   - Resource Controllers are used extensively for CRUD operations
   - Route groups apply common prefixes, middleware, and name prefixes
   - Controller namespaces follow a logical organization (Admin, Root, RSpade, Api)

3. **Middleware Usage**:
   - 'auth' middleware protects authenticated routes
   - Access level middleware ('user', 'admin', 'root') restrict access based on user role
   - 'site' middleware enforces site context for site-specific functionality
   - 'auth:sanctum' secures API routes for external clients

4. **Naming Conventions**:
   - Routes use snake_case for controller method names and parameters
   - Route names use dot notation for organization (admin.sites.edit)
   - Parameter naming follows Laravel conventions (e.g., {todo_list})

5. **Access Control Implementation**:
   - Access is controlled through a combination of middleware and route groups
   - User roles are hierarchical (root > admin > user > read-only)
   - Site context is maintained through the Session class and middleware
   - Root users can access all sites and functionality (with special exceptions)