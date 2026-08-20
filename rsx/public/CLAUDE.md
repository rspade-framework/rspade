# RSX Public Directory

## Purpose

This directory contains static files that are served directly at the root URL of your application. Files placed here are accessible without any URL prefix.

## URL Mapping

Files in this directory are exposed at the root of your domain:

```
./rsx/public/cat.jpg        → http://www.example.com/cat.jpg
./rsx/public/logo.png       → http://www.example.com/logo.png
./rsx/public/docs/guide.pdf → http://www.example.com/docs/guide.pdf
```

## Important Restrictions

1. **Excluded from Manifest**: Files in this directory are NOT scanned by the RSX manifest system
2. **Dot Files Inaccessible**: Files starting with `.` (like `.htaccess`) cannot be accessed via HTTP
3. **PHP Scripts Not Executable**: PHP files are served as plain text and will not execute
4. **Public Ignore List**: Files matching patterns in `public_ignore.json` are blocked from HTTP access for security

## Security Notes

- Do not place sensitive configuration files here
- Do not place executable scripts that should run server-side
- All files here are publicly accessible without authentication

## Common Use Cases

- Favicon files
- Sitemap files (sitemap.xml)
- Static images and media
- Public documents (PDFs, etc.)
- Font files
- Icons

## Framework Integration

The framework automatically serves files from this directory through its routing system. No additional configuration is needed - simply place files here and they become accessible at the root URL.

## Caching Behavior

Files are served with different cache strategies based on URL parameters:

**Without cache-busting parameter:**
- Cache duration: 5 minutes
- Browser revalidates with server after expiration (304 Not Modified responses)
- Use for files that may change frequently

**With `?v=` parameter (recommended):**
- Cache duration: 30 days (immutable)
- No revalidation - browser uses cached copy until parameter changes
- Example: `http://www.example.com/logo.png?v=2`
- **Recommended for production** - enables efficient caching and atomic deployments

When deploying updates, increment the version parameter to force browsers to fetch the new file.
