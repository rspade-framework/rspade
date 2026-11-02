<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace Rsx\Companions\Hello;

use Illuminate\Http\Request;
use App\RSpade\Core\Companion\Companion_Dispatcher;
use App\RSpade\Core\Companion\Companion_Service_Abstract;
use App\RSpade\Core\Session\Session;

/**
 * Hello_Companion_Service - the smallest companion application: a Node process that heartbeats, sets a
 * status line, calls hello() every 30 seconds, honours pause and resume, and exits cleanly on
 * SIGTERM. A worked example, and stopped until an administrator starts it. See
 * rsx/companions/CLAUDE.md.
 */
class Hello_Companion_Service extends Companion_Service_Abstract
{
    public static function name(): string
    {
        return 'hello';
    }

    public static function description(): string
    {
        return 'The worked example: heartbeats and calls one endpoint every 30 seconds';
    }

    public static function command(): array
    {
        return ['node', 'resource/main.js'];
    }

    /**
     * Answers what the process is to the application. Writes nothing, so a call repeated after
     * a companion is killed mid-call does no harm: every endpoint is built to be repeated.
     */
    #[Companion_Endpoint('hello')]
    public static function hello(Request $request, array $params = [])
    {
        return [
            'greeting' => 'hello',
            'companion' => Companion_Dispatcher::current_name(),
            'site_id' => Session::get_site_id(),
            'signed_in' => Session::is_logged_in(),
        ];
    }
}
