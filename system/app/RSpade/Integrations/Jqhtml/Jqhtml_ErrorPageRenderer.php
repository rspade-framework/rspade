<?php

namespace App\RSpade\Integrations\Jqhtml;

use Spatie\LaravelIgnition\Renderers\ErrorPageRenderer;
use Throwable;

/**
 * Custom error page renderer that extends Ignition's error renderer
 * to properly display multi-line error messages without truncation
 */
#[Instantiatable]
class Jqhtml_ErrorPageRenderer extends ErrorPageRenderer
{
    /**
     * Render the error page with custom JavaScript to fix multi-line display
     */
    public function render(Throwable $throwable): void
    {
        // Create custom JavaScript to convert newlines to <br> tags in error messages
        $customScript = <<<'JS'
<script>
// Fix multi-line error messages in Ignition by converting \n to <br>
document.addEventListener('DOMContentLoaded', function() {
    // Wait for React to render
    setTimeout(function() {
        // Find all elements that might contain error messages
        const selectors = [
            '.message',
            '.exception-message',
            '[class*="message"]',
            '.line-clamp-2',
            'h1',
            'title'
        ];

        selectors.forEach(function(selector) {
            document.querySelectorAll(selector).forEach(function(element) {
                const text = element.textContent || element.innerText || '';

                // Check if element contains newlines
                if (text.includes('\n')) {
                    // Convert newlines to <br> tags
                    const html = text
                        .split('\n')
                        .map(line => {
                            // Escape HTML but preserve spaces at start of lines
                            const escaped = line
                                .replace(/&/g, '&amp;')
                                .replace(/</g, '&lt;')
                                .replace(/>/g, '&gt;')
                                .replace(/"/g, '&quot;')
                                .replace(/'/g, '&#039;');
                            // Convert leading spaces to non-breaking spaces
                            return escaped.replace(/^( +)/, function(match) {
                                return '&nbsp;'.repeat(match.length);
                            });
                        })
                        .join('<br>');

                    element.innerHTML = html;

                    // Remove truncation styles
                    element.classList.remove('line-clamp-2', 'truncate');
                    element.style.overflow = 'visible';
                    element.style.display = 'block';
                    element.style.webkitLineClamp = 'none';
                    element.style.webkitBoxOrient = 'horizontal';
                }
            });
        });

        // Do it again after a longer delay for any lazy-loaded content
        setTimeout(arguments.callee, 500);
    }, 100);
});
</script>
JS;

        // Call parent render method with our custom script added
        app(\Spatie\Ignition\Ignition::class)
            ->resolveDocumentationLink(
                fn (Throwable $throwable) => (new \Spatie\LaravelIgnition\Support\LaravelDocumentationLinkFinder())->findLinkForThrowable($throwable)
            )
            ->setFlare(app(\Spatie\FlareClient\Flare::class))
            ->setConfig(app(\Spatie\Ignition\Config\IgnitionConfig::class))
            ->setSolutionProviderRepository(app(\Spatie\ErrorSolutions\Contracts\SolutionProviderRepository::class))
            ->setContextProviderDetector(new \Spatie\LaravelIgnition\ContextProviders\LaravelContextProviderDetector())
            ->setSolutionTransformerClass(\Spatie\LaravelIgnition\Solutions\SolutionTransformers\LaravelSolutionTransformer::class)
            ->applicationPath(base_path())
            ->addCustomHtmlToHead($customScript)
            ->renderException($throwable);
    }
}