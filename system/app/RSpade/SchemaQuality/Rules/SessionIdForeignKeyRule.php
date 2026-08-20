<?php

namespace App\RSpade\SchemaQuality\Rules;

class SessionIdForeignKeyRule extends Schema_Rule_Abstract
{
    public function get_id(): string
    {
        return 'SCHEMA-FK-01';
    }
    
    public function get_name(): string
    {
        return 'Session ID Foreign Key Rule';
    }
    
    public function get_description(): string
    {
        return 'Ensures session_id columns are nullable. Foreign key constraints to sessions table are NOT enforced - session IDs are ephemeral tracking identifiers that should not have referential integrity constraints.';
    }

    public function check(array $schema): void
    {
        foreach ($schema['tables'] as $table_name => $table_info) {
            if ($this->is_excluded_table($table_name)) {
                continue;
            }

            // Check each column for session_id
            foreach ($table_info['columns'] as $column) {
                if ($column['name'] === 'session_id') {
                    // Check if nullable - session_id must always be nullable
                    if ($column['nullable'] !== 'YES') {
                        $this->add_violation(
                            $table_name,
                            'session_id',
                            'Column session_id must be nullable (ephemeral tracking identifier)',
                            'ALTER TABLE ' . $table_name . ' MODIFY session_id VARCHAR(255) NULL'
                        );
                    }

                    // NOTE: We do NOT enforce foreign key constraints for session_id columns.
                    // Session IDs are ephemeral tracking identifiers used for:
                    // - Temporary file upload tracking (file_attachments)
                    // - Short-term security validation
                    // - Session-scoped data that doesn't need referential integrity
                    //
                    // Adding FK constraints would:
                    // - Prevent cleanup of old sessions (cascade deletes unwanted)
                    // - Create unnecessary coupling between ephemeral and persistent data
                    // - Violate the principle that sessions are temporary, data is permanent
                }
            }
        }
    }
}