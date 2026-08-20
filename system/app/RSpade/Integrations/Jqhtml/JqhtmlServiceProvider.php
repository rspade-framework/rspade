<?php

namespace App\RSpade\Integrations\Jqhtml;

use Illuminate\Support\Facades\Blade;
use Illuminate\Support\ServiceProvider;
use App\RSpade\Integrations\Jqhtml\JqhtmlBladeCompiler;

/**
 * Jqhtml Service Provider
 *
 * Registers jqhtml components with Laravel's Blade component system
 */
#[Instantiatable]
class JqhtmlServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap any application services
     */
    public function boot(): void
    {
        // Register the Blade precompiler for uppercase jqhtml component tags
        Blade::precompiler(function ($value) {
            return JqhtmlBladeCompiler::precompile($value);
        });

        // Register the generic x-jqhtml component for kebab-case usage
        Blade::component('jqhtml', \App\RSpade\Integrations\Jqhtml\Jqhtml_View_Component::class);

        // Also register a Blade directive for simpler syntax
        Blade::directive('jqhtml', function ($expression) {
            // Parse the expression to extract component and args
            // Example: @jqhtml('User_Card', ['name' => 'John'])
            return "<?php echo \\App\\RSpade\\Integrations\\Jqhtml\\Jqhtml::component({$expression}); ?>";
        });
    }

}