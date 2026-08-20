<?php

namespace App\RSpade\Commands\Database;

use Illuminate\Database\Console\Seeds\SeederMakeCommand as LaravelSeederMakeCommand;
use App\RSpade\Core\Database\SeederPaths;

/**
 * Override Laravel's make:seeder command to use /rsx/resource/seeders
 *
 * Creates seeders as simple classes in /rsx/resource/seeders without namespace nesting.
 * Path-agnostic: seeders are discovered by simple class name, not namespace.
 */
class Make_Seeder_Command extends LaravelSeederMakeCommand
{
    /**
     * Parse the class name and format according to the root namespace
     * Override to prevent namespace qualification for RSX seeders
     *
     * @param  string  $name
     * @return string
     */
    protected function qualifyClass($name)
    {
        // Just return the simple class name without any namespace qualification
        return class_basename($name);
    }

    /**
     * Get the destination class path
     *
     * @param  string  $name
     * @return string
     */
    protected function getPath($name)
    {
        // Ensure seeder directories exist
        SeederPaths::ensure_directories_exist();

        // Extract just the class name (no namespace)
        $name = class_basename(str_replace('\\', '/', $name));

        return SeederPaths::get_default_path() . '/' . $name . '.php';
    }

    /**
     * Get the stub file for the generator
     *
     * @return string
     */
    protected function getStub()
    {
        return __DIR__ . '/stubs/seeder.stub';
    }

    /**
     * Build the class with the given name
     *
     * @param  string  $name
     * @return string
     */
    protected function buildClass($name)
    {
        $stub = $this->files->get($this->getStub());

        return $this->replaceClass($stub, $name);
    }

    /**
     * Replace the class name for the given stub
     *
     * @param  string  $stub
     * @param  string  $name
     * @return string
     */
    protected function replaceClass($stub, $name)
    {
        // Name is already just the class name from qualifyClass()
        return str_replace('{{ class }}', $name, $stub);
    }
}
