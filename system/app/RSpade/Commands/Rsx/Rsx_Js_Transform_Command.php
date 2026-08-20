<?php

namespace App\RSpade\Commands\Rsx;

use App\Console\Commands\FrameworkDeveloperCommand;
use App\RSpade\Core\JsParsers\Js_Transformer;

class Rsx_Js_Transform_Command extends FrameworkDeveloperCommand
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'rsx:js:transform {file : JavaScript file to transform} {--target=modern : Target environment (modern, es6, es5)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Transform JavaScript file using Babel (decorators, etc)';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $file = $this->argument('file');
        $target = $this->option('target');

        // Make path absolute if relative
        if (!str_starts_with($file, '/')) {
            $file = base_path($file);
        }

        if (!file_exists($file)) {
            $this->error("File not found: $file");
            return 1;
        }

        if (!str_ends_with($file, '.js')) {
            $this->error("File must be a JavaScript file (.js extension)");
            return 1;
        }

        $this->info("Transforming: " . str_replace(base_path() . '/', '', $file));
        $this->info("Target: $target");
        $this->newLine();

        try {
            // This will throw on any error (missing deps, parse error, etc)
            $transformed = Js_Transformer::transform($file, $target);

            // Output to stdout for piping
            $this->getOutput()->write($transformed);

            return 0;

        } catch (\RuntimeException $e) {
            $this->error("Transformation failed!");

            // Parse error message to show location if available
            $message = $e->getMessage();

            // Check if line/column info is in the message
            if (preg_match('/at line (\d+), column (\d+)/', $message, $matches)) {
                $this->error($message);
            } else {
                $this->error($message);
            }

            // Check for common issues based on the message content
            if (str_contains($message, 'Cannot find module') ||
                str_contains($message, 'not installed')) {
                $this->newLine();
                $this->warn("Missing dependencies detected. Run:");
                $this->warn("  npm install");
            } elseif (str_contains($message, 'syntax') ||
                      str_contains($message, 'Unexpected token')) {
                $this->newLine();
                $this->warn("JavaScript syntax error in file");
            }

            return 1;
        }
    }
}