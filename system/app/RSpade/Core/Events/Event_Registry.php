<?php

namespace App\RSpade\Core\Events;

/**
 * Event_Registry
 *
 * Manages event handler registration and dispatching.
 * Loads handlers from manifest and provides fast lookup.
 */
class Event_Registry
{
    private static $handlers = [];
    private static $loaded = false;

    /**
     * TEST SEAM ONLY - per-event handler overrides.
     *
     * An event present as a key REPLACES its manifest-discovered handler list, so an empty
     * array simulates "no handler is registered for this event" (which a manifest-driven
     * registry cannot otherwise express in a live app). Never populated outside a test run;
     * tests MUST clear their overrides (see _clear_test_handlers()).
     *
     * @var array<string, array<callable>>
     */
    private static $test_handler_overrides = [];

    /**
     * Get handlers for an event
     *
     * @param string $event Event name
     * @return array Array of callables
     */
    public static function get_handlers(string $event): array
    {
        if (array_key_exists($event, self::$test_handler_overrides)) {
            return self::$test_handler_overrides[$event];
        }

        if (!self::$loaded) {
            self::__load_from_manifest();
        }

        return self::$handlers[$event] ?? [];
    }

    /**
     * Is ANY handler registered for an event?
     *
     * Lets a caller distinguish "the gate/filter chain passed" from "nobody is listening".
     * Rsx::trigger_gate() deliberately returns true when no handler exists (an unregistered
     * gate defaults OPEN), so a MANDATORY gate - one where an unhandled event means the
     * application is misconfigured rather than permissive - asks this first and fails loud.
     *
     * @param string $event Event name
     * @return bool
     */
    public static function has_handlers(string $event): bool
    {
        return count(static::get_handlers($event)) > 0;
    }

    /**
     * TEST SEAM ONLY - force an event's handler list.
     *
     * Pass [] to simulate an event with NO registered handler. Pass callables to stand in for
     * app handlers without registering a live #[OnEvent] (which would be manifest-discovered
     * and therefore active in the running application).
     *
     * @param string $event Event name
     * @param array $handlers Callables taking the event data
     * @return void
     */
    public static function _set_test_handlers(string $event, array $handlers): void
    {
        self::$test_handler_overrides[$event] = $handlers;
    }

    /**
     * TEST SEAM ONLY - drop every override set by _set_test_handlers().
     *
     * @return void
     */
    public static function _clear_test_handlers(): void
    {
        self::$test_handler_overrides = [];
    }

    /**
     * Load event handlers from manifest
     */
    private static function __load_from_manifest(): void
    {
        $manifest = \App\RSpade\Core\Manifest\Manifest::get_full_manifest();
        $event_handlers = $manifest['data']['event_handlers'] ?? [];

        foreach ($event_handlers as $event_name => $handlers) {
            self::$handlers[$event_name] = [];

            foreach ($handlers as $handler_info) {
                $class = $handler_info['class'];
                $method = $handler_info['method'];

                // Create closure that calls the static method
                self::$handlers[$event_name][] = function ($data) use ($class, $method) {
                    return $class::$method($data);
                };
            }
        }

        self::$loaded = true;
    }

    /**
     * Reset registry (for testing)
     */
    public static function reset(): void
    {
        self::$handlers = [];
        self::$loaded = false;
        self::$test_handler_overrides = [];
    }
}
