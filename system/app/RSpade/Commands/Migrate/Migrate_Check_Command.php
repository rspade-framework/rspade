<?php

namespace App\RSpade\Commands\Migrate;

use Illuminate\Console\Command;
use App\RSpade\SchemaQuality\SchemaQualityChecker;

class Migrate_Check_Command extends Command
{
    protected $signature = 'migrate:check';
    protected $description = 'Check database schema for standards compliance';
    
    public function handle()
    {
        $this->info(' Checking database schema standards...');
        $this->info('');
        
        $checker = new SchemaQualityChecker();
        $violations = $checker->check();
        
        if (!$checker->has_violations()) {
            $this->info('[OK] Database schema passes all standards checks!');
            return 0;
        }
        
        // Display violations
        $this->error('[ERROR] Found ' . $checker->get_violation_count() . ' schema violation(s):');
        $this->info('');
        
        $grouped = $checker->get_violations_by_severity();
        
        // Display by severity
        foreach (['critical', 'high', 'medium', 'low'] as $severity) {
            if (!empty($grouped[$severity])) {
                $this->line(strtoupper($severity) . ' VIOLATIONS:');
                foreach ($grouped[$severity] as $violation) {
                    $this->line($violation->format_output());
                }
            }
        }
        
        $this->info('');
        $this->warn('[WARNING]  Fix these violations before committing migrations.');
        
        // Return non-zero exit code if violations found
        return 1;
    }
}