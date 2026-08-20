# Application Documentation

This directory is for developer documentation specific to your RSX application.

## Purpose

Store project-specific documentation here including:
- Architecture decisions and design documents
- API documentation
- Development workflows and processes
- Deployment procedures
- Database schema documentation
- Testing strategies
- Troubleshooting guides
- Team onboarding materials

## Organization

Organize documentation however suits your project:

```
rsx/resource/docs/
├── README.md           # This file
├── api/                # API endpoint documentation
├── architecture/       # System design documents
├── deployment/         # Deployment procedures
├── database/           # Schema and migration notes
└── workflows/          # Development processes
```

## Markdown Format

Use Markdown (`.md`) files for easy version control and readability. Documentation lives alongside your code and evolves with your application.

## Framework Documentation

For RSX framework documentation, use:
```bash
php artisan rsx:man <topic>
```

This directory is for **your application's documentation**, not framework documentation.
