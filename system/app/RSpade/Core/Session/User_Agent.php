<?php

namespace App\RSpade\Core\Session;

/**
 * User Agent Parser - Static helper for parsing user agent strings
 *
 * Provides simple parsing of user agent strings into human-readable
 * browser, OS, and device type information.
 *
 * This is intentionally simple and doesn't aim to be comprehensive.
 * For more detailed parsing, consider a dedicated library like
 * WhichBrowser or ua-parser.
 */
class User_Agent
{
    /**
     * Parse a user agent string into components
     *
     * @param string|null $user_agent
     * @return array{browser: string, os: string, device: string, summary: string}
     */
    public static function parse(?string $user_agent): array
    {
        if (empty($user_agent)) {
            return [
                'browser' => 'Unknown',
                'os' => 'Unknown',
                'device' => 'Unknown',
                'summary' => 'Unknown',
            ];
        }

        $browser = self::_detect_browser($user_agent);
        $os = self::_detect_os($user_agent);
        $device = self::_detect_device($user_agent);

        return [
            'browser' => $browser,
            'os' => $os,
            'device' => $device,
            'summary' => "{$browser} on {$os}",
        ];
    }

    /**
     * Detect browser from user agent
     *
     * @param string $user_agent
     * @return string
     */
    private static function _detect_browser(string $user_agent): string
    {
        // Order matters - check more specific browsers first
        $browsers = [
            'Edg/' => 'Edge',
            'Edge/' => 'Edge',
            'OPR/' => 'Opera',
            'Opera' => 'Opera',
            'Brave' => 'Brave',
            'Vivaldi' => 'Vivaldi',
            'Firefox/' => 'Firefox',
            'Chrome/' => 'Chrome',
            'Safari/' => 'Safari',
            'MSIE ' => 'Internet Explorer',
            'Trident/' => 'Internet Explorer',
        ];

        foreach ($browsers as $pattern => $name) {
            if (stripos($user_agent, $pattern) !== false) {
                // Special case: Safari check - Chrome/Edge/Opera all contain Safari
                if ($name === 'Safari') {
                    if (stripos($user_agent, 'Chrome') !== false ||
                        stripos($user_agent, 'Chromium') !== false ||
                        stripos($user_agent, 'Edg') !== false ||
                        stripos($user_agent, 'OPR') !== false) {
                        continue;
                    }
                }
                return $name;
            }
        }

        return 'Unknown Browser';
    }

    /**
     * Detect operating system from user agent
     *
     * @param string $user_agent
     * @return string
     */
    private static function _detect_os(string $user_agent): string
    {
        // Order matters - check more specific patterns first
        $os_patterns = [
            'iPhone' => 'iOS',
            'iPad' => 'iPadOS',
            'Android' => 'Android',
            'Windows NT 10' => 'Windows',
            'Windows NT 6.3' => 'Windows',
            'Windows NT 6.2' => 'Windows',
            'Windows NT 6.1' => 'Windows',
            'Windows' => 'Windows',
            'Mac OS X' => 'macOS',
            'Macintosh' => 'macOS',
            'CrOS' => 'Chrome OS',
            'Ubuntu' => 'Ubuntu',
            'Fedora' => 'Fedora',
            'Linux' => 'Linux',
        ];

        foreach ($os_patterns as $pattern => $name) {
            if (stripos($user_agent, $pattern) !== false) {
                return $name;
            }
        }

        return 'Unknown OS';
    }

    /**
     * Detect device type from user agent
     *
     * @param string $user_agent
     * @return string
     */
    private static function _detect_device(string $user_agent): string
    {
        // Check for mobile indicators
        $mobile_patterns = [
            'iPhone',
            'Android.*Mobile',
            'Windows Phone',
            'BlackBerry',
            'Opera Mini',
            'IEMobile',
        ];

        foreach ($mobile_patterns as $pattern) {
            if (preg_match('/' . $pattern . '/i', $user_agent)) {
                return 'Mobile';
            }
        }

        // Check for tablet indicators
        $tablet_patterns = [
            'iPad',
            'Android(?!.*Mobile)',
            'Tablet',
        ];

        foreach ($tablet_patterns as $pattern) {
            if (preg_match('/' . $pattern . '/i', $user_agent)) {
                return 'Tablet';
            }
        }

        return 'Desktop';
    }

    /**
     * Check if the user agent is an automated/headless browser
     *
     * Detects Playwright, Puppeteer, headless Chrome/Firefox, and similar.
     *
     * @param string|null $user_agent
     * @return bool
     */
    public static function is_automated(?string $user_agent): bool
    {
        if (empty($user_agent)) {
            return false;
        }

        return (bool) preg_match('/HeadlessChrome|Headless|Playwright|Puppeteer/i', $user_agent);
    }

    /**
     * Get a short summary suitable for display
     * e.g., "Chrome on Windows"
     *
     * @param string|null $user_agent
     * @return string
     */
    public static function get_summary(?string $user_agent): string
    {
        return self::parse($user_agent)['summary'];
    }
}
