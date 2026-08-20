# Console Commands Directory Documentation

This directory contains Artisan console commands used for maintenance, utilities, and administrative functions within the RSpade application.

## CLAUDE.md File Standards

Each CLAUDE.md file in the project follows these standards:

1. **Purpose**: The file begins with a brief synopsis of the directory's purpose and role in the application.

2. **File Index**: Contains a list of important files with their sizes and last modified dates.
   - When visiting a directory, always run `ls -la` to get current file sizes
   - If a file's current size differs from what's documented in CLAUDE.md, the file has changed
   - Before trusting information about a changed file, first read the current version and update the CLAUDE.md documentation

3. **File Documentation**: For each important file, includes:
   - Short description of purpose and functionality
   - Key points of information necessary for understanding the file
   - Important implementation details or architectural considerations

4. **Selective Coverage**: Only documents important files that contribute to understanding the codebase
   - Images, temporary files, and generated content are typically not documented
   - Configuration files, models, controllers, and other architectural components are always documented

5. **Additional Context**: May include other critical information about the directory's role in the system

Always check file sizes against the current state before relying on the documentation in CLAUDE.md files.

## Important Files

### Database Safety Guardrails

- **RestrictedDatabaseCommand.php** (2838 bytes) - Base class for restricted commands
  - Provides standardized messaging for disabled database operations
  - Enforces forward-only migration policy
  - Offers guidance on alternative approaches
  - Ensures safety for automated systems and AI agents

- **Database/WipeCommand.php** (946 bytes) - Override for db:wipe
  - Prevents complete database destruction
  - Protects against accidental data loss
  
- **Migrate/FreshCommand.php** (1528 bytes) - Override for migrate:fresh
  - Prevents dropping and recreating all tables
  - Maintains data integrity

- **Migrate/ResetCommand.php** (1109 bytes) - Override for migrate:reset
  - Prevents rollback of all migrations
  - Ensures forward-only migration strategy

- **Migrate/RefreshCommand.php** (1316 bytes) - Override for migrate:refresh
  - Prevents reset and re-run of migrations
  - Protects existing data

- **Migrate/RollbackCommand.php** (1343 bytes) - Override for migrate:rollback
  - Prevents rollback of migrations
  - Enforces schema evolution through new migrations only

### Schema Maintenance Commands

- **Maint_Migrate.php** (3482 bytes) - Enhanced migration command
  - Extends Laravel's migrate command with additional maintenance steps
  - Runs required table column checks
  - Regenerates model constants
  - Exports constants to JavaScript
  - Usage: `php artisan maint:migrate`

- **Migrate/MigrateNormalizeSchema.php** - Normalizes database schema to framework standards
  - Standardizes data types (INT→BIGINT, TEXT→LONGTEXT, FLOAT→DOUBLE)
  - Converts character encoding to UTF8MB4 for full Unicode/emoji support
  - Adds required columns (created_at, updated_at, created_by_id/_type, updated_by_id/_type)
  - Adds indexes on timestamp columns
  - Updates datetime precision to milliseconds
  - Usage: `php artisan migrate:normalize_schema`
  - **Note**: Automatically called during migrations - manual execution rarely needed

### Code Generation Commands

- **Migrate/MigrateRegenerateConstants.php** - Model constant generation
  - Scans model files for static $enums properties
  - Generates class constants for enum values
  - Updates PHPDoc comments with property and method tags
  - Adds $dates property with datetime columns
  - Validates enum definitions for consistency
  - Updates model files in-place with constants and docblocks
  - Usage: `php artisan migrate:regenerate_constants`
  - **Note**: Automatically called during migrations - manual execution rarely needed

### Content Management Commands

- **CreateSampleKnowledgeBase.php** (14626 bytes) - Sample content generation
  - Creates sample knowledge base articles for testing
  - Generates categories and content hierarchy
  - Adds formatted content with Markdown
  - Creates content relationships and metadata
  - Usage: `php artisan create:sample-kb`

- **FeatureKnowledgeBaseArticles.php** (1594 bytes) - Content curation
  - Selects and features knowledge base articles
  - Updates featured status for homepage display
  - Manages featured article ordering
  - Usage: `php artisan feature:kb-articles`

### Utility Commands

- **GenerateSitemap.php** (4206 bytes) - SEO sitemap generation
  - Creates XML sitemap for search engines
  - Includes all public routes and content pages
  - Sets priority and change frequency
  - Optimizes for search engine indexing
  - Usage: `php artisan generate:sitemap`

- **GetEnvironment.php** (579 bytes) - Environment diagnostic
  - Displays current application environment
  - Shows configuration values for debugging
  - Helps with environment-specific troubleshooting
  - Usage: `php artisan env:get`

## Command Categories

The commands are organized into several functional categories:

1. **Maintenance Commands** (prefix: `maint:`)
   - Database and schema maintenance
   - Model code generation
   - System consistency checks

2. **Utility Commands** (prefix: `utility:`)
   - Helper functions for development
   - Code generation utilities
   - Export functionality

3. **Content Commands** (no standard prefix)
   - Sample content generation
   - Content management and curation
   - Seeding and fixture creation

4. **Diagnostic Commands** (usually prefixed with the subsystem name)
   - System status and health checks
   - Configuration validation
   - Performance diagnostics

## Important Concepts

1. **Enum System Integration**: Several commands work with the model enum system, generating constants and JavaScript exports.

2. **Database Schema Management**: Commands ensure consistent schema structure and features across tables.

3. **Code Generation**: Commands generate code and documentation to reduce manual work and ensure consistency.

4. **Content Management**: Commands facilitate content seeding and management for testing and initial setup.

## Usage in Development Workflow

These commands are typically used in the following scenarios:

1. After database migrations to ensure schema consistency
2. During development to keep model constants in sync with enum definitions
3. For generating test data and sample content
4. As part of deployment processes to prepare the application

The commands can be run manually or as part of automated scripts, and many are integrated with Laravel's migration system to run automatically when migrations are executed.