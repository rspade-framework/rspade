<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Core\Exceptions;

use Illuminate\Http\Request;
use Throwable;
use App\RSpade\Core\Exceptions\Rsx_Exception_Handler_Abstract;

/**
 * Cli_ExceptionHandler - Handle exceptions in CLI mode with formatted output
 *
 * PRIORITY: 10 (high priority - runs first)
 *
 * This handler processes exceptions when running in CLI mode (artisan commands).
 * It provides formatted, colored output optimized for terminal display with:
 * - Exception type and location
 * - Wrapped error message
 * - Stack trace (last 10 calls)
 * - Console debug messages (if any)
 *
 * When this handler handles an exception, it outputs to STDERR and exits with code 1.
 */
class Cli_Exception_Handler extends Rsx_Exception_Handler_Abstract
{
    /**
     * Get priority - CLI handlers run first
     *
     * @return int
     */
    public static function get_priority(): int
    {
        return 10;
    }

    /**
     * Handle exception if in CLI mode
     *
     * @param Throwable $e
     * @param Request $request
     * @return mixed Response if handled (exits), null if not CLI mode
     */
    public function handle(Throwable $e, Request $request)
    {
        // Only handle in CLI mode
        if (!app()->runningInConsole()) {
            return null;
        }

        // ANSI color codes
        $reset = "\033[0m";
        $bold = "\033[1m";
        $bold_orange = "\033[1;38;5;208m";
        $amber = "\033[33m";
        $white = "\033[37m";
        $bold_white = "\033[1;37m";

        // Get exception class name (without namespace)
        $exception_type = (new \ReflectionClass($e))->getShortName();

        // Format file path (remove base path for readability)
        $file = str_replace(base_path() . '/', '', $e->getFile());

        // Build formatted error output
        $error_output = "\n";
        $error_output .= "Fatal {$bold_orange}{$exception_type}{$reset} on {$amber}{$file}{$white}:{$amber}{$e->getLine()}{$reset}\n";
        $error_output .= "\n";

        // Format error message with word wrapping if terminal width detected
        $error_message = $e->getMessage();
        $terminal_width = 0;
        try {
            // Try to get terminal width
            $terminal = new \Symfony\Component\Console\Terminal();
            $terminal_width = $terminal->getWidth();
        } catch (\Exception $ex) {
            // Ignore, use default formatting
        }

        if ($terminal_width > 50) { // Valid width detected
            // Word wrap with 2-space indent
            $max_width = $terminal_width - 2; // Account for indent
            $wrapped_lines = [];
            $words = explode(' ', $error_message);
            $current_line = '';

            foreach ($words as $word) {
                $test_line = $current_line ? $current_line . ' ' . $word : $word;
                if (strlen($test_line) > $max_width) {
                    if ($current_line) {
                        $wrapped_lines[] = $current_line;
                        $current_line = $word;
                    } else {
                        // Single word longer than max width, force break
                        $wrapped_lines[] = $word;
                        $current_line = '';
                    }
                } else {
                    $current_line = $test_line;
                }
            }
            if ($current_line) {
                $wrapped_lines[] = $current_line;
            }

            // Add each line with indent and color
            foreach ($wrapped_lines as $line) {
                $error_output .= "  {$bold_white}{$line}{$reset}\n";
            }
        } else {
            // Default: no wrapping, no indent
            $error_output .= "{$bold_white}{$error_message}{$reset}\n";
        }
        $error_output .= "\n";
        $error_output .= "Stack Trace (last 10 calls):\n";

        // Get stack trace
        $trace = $e->getTrace();
        $count = 0;
        foreach ($trace as $frame) {
            if ($count >= 10) {
                break;
            }

            $file = $frame['file'] ?? 'unknown';
            $line = $frame['line'] ?? 0;
            $function = $frame['function'] ?? 'unknown';
            $class = $frame['class'] ?? '';
            $type = $frame['type'] ?? '';

            if ($class) {
                $function = $class . $type . $function;
            }

            $error_output .= sprintf(
                "  #%d %s:%d %s()\n",
                $count,
                $file,
                $line,
                $function
            );
            $count++;
        }

        // Output console debug messages if enabled and any exist
        if (!app()->environment('production')) {
            $console_messages = \App\RSpade\Core\Debug\Debugger::_get_console_messages();
            if (!empty($console_messages)) {
                $error_output .= "\nConsole Debug Messages:\n";
                foreach ($console_messages as $message) {
                    // Messages are structured arrays with channel and arguments
                    if (is_array($message) && count($message) >= 2) {
                        $channel_line = $message[0];
                        $arguments = $message[1];
                        $error_output .= '  ' . $channel_line;
                        foreach ($arguments as $arg) {
                            if (is_scalar($arg) || is_null($arg)) {
                                $output = is_bool($arg) ? ($arg ? 'true' : 'false') :
                                         (is_null($arg) ? 'null' : (string)$arg);
                                $error_output .= ' ' . $output;
                            }
                        }
                        $error_output .= "\n";
                    }
                }
            }
        }

        // Output to STDERR and exit with error code
        fwrite(STDERR, $error_output);
        exit(1);
    }
}
