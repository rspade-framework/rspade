<?php

namespace App\RSpade\Core\View;

/**
 * PageData - Store page-specific data for JavaScript access
 *
 * Allows views to add data to window.rsxapp.page_data via @rsx_page_data directive.
 * Data is accumulated during view rendering and output when bundle is rendered.
 *
 * Usage in Blade views:
 *   @rsx_page_data(['user_id' => $user->id])
 *   @rsx_page_data(['key' => 'value', 'another' => $data])
 *
 * Access in JavaScript:
 *   const user_id = window.rsxapp.page_data.user_id;
 */
class PageData
{
    /**
     * Accumulated page data for current request
     *
     * @var array
     */
    protected static array $data = [];

    /**
     * Add data to page_data object
     *
     * @param array $data Associative array of data to add
     * @return void
     */
    public static function add(array $data): void
    {
        static::$data = array_merge(static::$data, $data);
    }

    /**
     * Get all accumulated page data
     *
     * @return array
     */
    public static function get(): array
    {
        return static::$data;
    }

    /**
     * Check if any page data has been set
     *
     * @return bool
     */
    public static function has_data(): bool
    {
        return !empty(static::$data);
    }
}
