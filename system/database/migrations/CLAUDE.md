# Migrations Directory

This CLAUDE.md file contains a brief synopsis of the purpose of this directory, then a list of files in this directory with the file sizes of each file, and a short description and relevant key points of information for every file which is important in this directory. Unimportant files like images or temporary data directories are not listed in this file. When visiting this directory, the AI agent is instructed to do an ls on the directory to get the directory contents and file sizes - and if the file size diverges from the size in CLAUDE.md, that means the file has changed, and the description in CLAUDE.md is not up to date. This doesn't trigger this to be regenerated immediately, but let's say we wanted to know about a specific file by viewing CLAUDE.md and we discovered it was out of date, we would need to reread and update the documentation for that file in the CLAUDE.md at that time before we considered any details about it. CLAUDE.md might also contain other bits of information that is critical to know if you are looking at notes in the directory where the CLAUDE.md file lives.

## Directory Purpose

The Migrations directory contains database migration files that define the database schema and its evolution over time. Laravel migrations provide a version control system for the database, allowing developers to modify the database schema in a structured and organized way. This directory includes migrations for all aspects of the application, from user authentication to content management and multi-tenant functionality.

## Directory Organization

This directory contains approximately 60 migration files in the root directory and additional migrations in two subdirectories:

1. **multi_tenant/** - Contains 10 migrations specific to multi-tenant functionality
2. **rspade/** - Contains 10 migrations for the RSpade application component

Due to the large number of migration files, this document provides a categorical overview rather than listing each file individually.

## Migration Categories

| Category | Date Range | Description |
|----------|------------|-------------|
| **Authentication/User System** | 2014-2019 | Laravel's default auth tables including users, password resets, failed jobs, and personal access tokens. Forms the foundation of the authentication system. |
| **Core DMR System** | 2025-05-10 | Initial system functionality including bridges, operators, talkgroups, and bridge links. These form the core domain objects of the application. |
| **Content Management** | 2025-05-10/11 | Text blocks, static blocks, and blog posts that provide content management capabilities throughout the application. |
| **Communication Features** | 2025-05-11 | Messages and notifications system for user-to-user communication and system notifications. |
| **Knowledge Base** | 2025-05-11 | Knowledge base articles and related functionality for information sharing. |
| **To-do System** | 2025-05-15 | Todo lists, todo items, and sharing functionality in the RSpade subdirectory. |
| **Multi-tenant Architecture** | 2025-05-15/16 | Sites, organizations, invitations, and user associations in the multi_tenant subdirectory. |
| **File Management** | 2024-05-15 | File uploads and management system for handling various file types. |

## Implementation Patterns

1. **SQL Implementation Approaches**:
   - Earlier migrations use raw SQL with `DB::unprepared()`
   - Later migrations use Laravel's Schema Builder for better readability and maintainability

2. **Common Features**:
   - Soft deletes (`deleted_at` columns) for all major entities
   - Tracking columns (created_at, updated_at) for audit purposes
   - Extensive use of indexes for performance optimization
   - Foreign key constraints for data integrity
   - Timestamp-based naming convention (YYYY_MM_DD_HHMMSS_action_table.php)

3. **Migration Flow**:
   - Forward-only migrations (down methods often minimal)
   - Progressive schema evolution with additive changes
   - Fix migrations to address issues in previous migrations

## Notable Migration Files

While there are too many files to list individually, some key migrations include:

1. `2014_10_12_000000_create_users_table.php` - Initial users table
2. `2025_05_10_045605_create_bridges_table.php` - Core bridge functionality
3. `2025_05_11_081546_create_knowledge_base_articles_table.php` - Knowledge base system
4. `2025_05_15_000011_create_sites_table.php` - Multi-tenant site functionality
5. `2025_05_15_000001_create_todo_lists_table.php` - Todo list functionality

## Working with Migrations

1. To create a new migration: `php artisan make:migration:safe name_of_migration`
2. To run migrations: `php artisan migrate`
3. To view pending migrations: `php artisan migrate:status`

In development mode, `migrate` automatically creates a database snapshot and rolls back on failure. No manual rollback command exists - forward-only migrations are enforced.