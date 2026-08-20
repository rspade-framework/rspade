# Public Files Directory

This directory contains static files that are served directly at your application's root URL.

## How It Works

Files placed in `./rsx/public/` are accessible at the root of your domain:

- `./rsx/public/cat.jpg` → `http://www.example.com/cat.jpg`
- `./rsx/public/sitemap.xml` → `http://www.example.com/sitemap.xml`

## What To Put Here

- Favicons
- Sitemaps
- Robots.txt
- Static images
- Public documents
- Font files

## Restrictions

- Files starting with `.` are not accessible via HTTP
- PHP scripts will not execute (served as plain text)
- Files are excluded from the RSX manifest system
- Files matching patterns in `public_ignore.json` are blocked for security

## Caching

Files cached for 5 minutes with revalidation. For production, use `?v=` parameter for 30-day immutable caching:

```
http://www.example.com/logo.png?v=2
```

Increment version on updates for atomic deployments.

Place your publicly accessible static files here for simple, direct URL access.
