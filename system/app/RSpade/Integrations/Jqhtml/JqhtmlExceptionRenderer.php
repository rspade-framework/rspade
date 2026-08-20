<?php

namespace App\RSpade\Integrations\Jqhtml;

use Illuminate\Contracts\Foundation\ExceptionRenderer;
use App\RSpade\Integrations\Jqhtml\Jqhtml_ErrorPageRenderer;

/**
 * Custom exception renderer that uses our modified error page renderer
 */
#[Instantiatable]
class JqhtmlExceptionRenderer implements ExceptionRenderer
{
    protected Jqhtml_ErrorPageRenderer $errorPageHandler;

    public function __construct(Jqhtml_ErrorPageRenderer $errorPageHandler)
    {
        $this->errorPageHandler = $errorPageHandler;
    }

    public function render($throwable)
    {
        ob_start();

        $this->errorPageHandler->render($throwable);

        return ob_get_clean();
    }
}