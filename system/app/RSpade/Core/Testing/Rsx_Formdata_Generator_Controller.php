<?php

namespace App\RSpade\Core\Testing;

use Exception;
use Illuminate\Http\Request;
use App\RSpade\Core\Controller\Rsx_Controller_Abstract;

/**
 * RSX:USE
 * Rsx_Formdata_Generator_Controller - Generate random test data for form fields
 *
 * Provides Ajax endpoints that return randomized test data for form seeding.
 * All methods fatal error in production mode - this is strictly a development tool.
 *
 * Usage from widgets:
 *     let value = await Rsx_Formdata_Generator_Controller.first_name();
 *     let email = await Rsx_Formdata_Generator_Controller.email();
 *
 * AUTHORIZATION: the class gate is 'public' and the real policy stays in pre_dispatch,
 * because that policy is "CLI caller OR (logged in AND ROLE_DEVELOPER)" - a composite
 * that the two built-in checks cannot express. It needs a named check of its own (a
 * developer-tools gate, which must live on the APP's Permission class since
 * ROLE_DEVELOPER is an application constant). Declaring 'is_logged_in' here instead
 * would silently break the CLI path, which the gate seam evaluates first.
 */
#[Auth('public')]
class Rsx_Formdata_Generator_Controller extends Rsx_Controller_Abstract
{
    /**
     * Authorization: requires developer role or CLI access
     *
     * This is a dev tool that generates test data. Access is restricted to:
     * 1. CLI users (artisan commands, tests)
     * 2. Authenticated users with ROLE_DEVELOPER
     *
     * See the class docblock: this stays inline until a developer-tools auth check
     * exists to express it declaratively.
     */
    public static function pre_dispatch(Request $request, array $params = [])
    {
        // Allow CLI access (artisan commands, tests)
        if (app()->runningInConsole()) {
            return null;
        }

        // Require authentication
        if (!\App\RSpade\Core\Session\Session::is_logged_in()) {
            return response_unauthorized();
        }

        // Require developer role
        if (!Permission::has_role(\User_Model::ROLE_DEVELOPER)) {
            return response_unauthorized('Developer access required');
        }

        return null;
    }

    private static $first_names = null;
    private static $last_names = null;
    private static $cities = null;
    private static $street_names = null;
    private static $words = null;

    /**
     * Check if running in production and throw if true
     */
    private static function __check_production(): void
    {
        if (app()->environment() === 'production') {
            throw new Exception('Rsx_Formdata_Generator_Controller cannot be used in production environment');
        }
    }

    /**
     * Load word list from resource file
     */
    private static function __load_wordlist(string $filename): array
    {
        $path = __DIR__ . '/resource/' . $filename;
        if (!file_exists($path)) {
            throw new Exception("Word list not found: {$filename}");
        }

        $content = file_get_contents($path);
        $lines = explode("\n", trim($content));
        return array_filter($lines, fn($line) => !empty(trim($line)));
    }

    /**
     * Get random item from word list
     */
    private static function __random_from_list(string $list_name): string
    {
        if (self::${$list_name} === null) {
            self::${$list_name} = self::__load_wordlist($list_name . '.txt');
        }

        $list = self::${$list_name};
        return $list[array_rand($list)];
    }

    /**
     * Generate random first name
     */
    #[Ajax_Endpoint]
    public static function first_name(Request $request, array $params = [])
    {
        self::__check_production();
        return self::__random_from_list('first_names');
    }

    /**
     * Generate random last name
     */
    #[Ajax_Endpoint]
    public static function last_name(Request $request, array $params = [])
    {
        self::__check_production();
        return self::__random_from_list('last_names');
    }

    /**
     * Generate random company name
     */
    #[Ajax_Endpoint]
    public static function company_name(Request $request, array $params = [])
    {
        self::__check_production();

        $patterns = [
            // Word + Industries/Solutions/Systems/Technologies
            fn() => self::__random_from_list('words') . ' ' . ['Industries', 'Solutions', 'Systems', 'Technologies', 'Corporation', 'Group', 'Enterprises'][rand(0, 6)],
            // LastName + FirstName + LLC
            fn() => self::__random_from_list('last_names') . ' ' . self::__random_from_list('first_names') . ' LLC',
            // Word + Word + Inc
            fn() => ucfirst(self::__random_from_list('words')) . ' ' . ucfirst(self::__random_from_list('words')) . ' Inc',
            // City + Industries
            fn() => self::__random_from_list('cities') . ' ' . ['Consulting', 'Services', 'Partners', 'Associates', 'Ventures'][rand(0, 4)],
        ];

        return $patterns[array_rand($patterns)]();
    }

    /**
     * Generate random street address
     */
    #[Ajax_Endpoint]
    public static function address(Request $request, array $params = [])
    {
        self::__check_production();

        $number = rand(1, 9999);
        $street = self::__random_from_list('street_names');
        $suffix = ['Street', 'Avenue', 'Road', 'Drive', 'Lane', 'Way', 'Boulevard', 'Court', 'Place'][rand(0, 8)];

        return "{$number} {$street} {$suffix}";
    }

    /**
     * Generate random city name
     */
    #[Ajax_Endpoint]
    public static function city(Request $request, array $params = [])
    {
        self::__check_production();
        return self::__random_from_list('cities');
    }

    /**
     * Generate random US state code
     */
    #[Ajax_Endpoint]
    public static function state(Request $request, array $params = [])
    {
        self::__check_production();

        $states = [
            'AL', 'AK', 'AZ', 'AR', 'CA', 'CO', 'CT', 'DE', 'FL', 'GA',
            'HI', 'ID', 'IL', 'IN', 'IA', 'KS', 'KY', 'LA', 'ME', 'MD',
            'MA', 'MI', 'MN', 'MS', 'MO', 'MT', 'NE', 'NV', 'NH', 'NJ',
            'NM', 'NY', 'NC', 'ND', 'OH', 'OK', 'OR', 'PA', 'RI', 'SC',
            'SD', 'TN', 'TX', 'UT', 'VT', 'VA', 'WA', 'WV', 'WI', 'WY'
        ];

        return $states[array_rand($states)];
    }

    /**
     * Generate random ZIP code
     */
    #[Ajax_Endpoint]
    public static function zip(Request $request, array $params = [])
    {
        self::__check_production();
        return str_pad((string)rand(10000, 99999), 5, '0', STR_PAD_LEFT);
    }

    /**
     * Generate random phone number in 555-XXX-XXXX format
     * Avoids 555-000-0000 and any sequence containing 911
     */
    #[Ajax_Endpoint]
    public static function phone(Request $request, array $params = [])
    {
        self::__check_production();

        $attempts = 0;
        do {
            $middle = str_pad((string)rand(100, 999), 3, '0', STR_PAD_LEFT);
            $last = str_pad((string)rand(1, 9999), 4, '0', STR_PAD_LEFT);
            $phone = "555-{$middle}-{$last}";

            $has_911 = strpos($phone, '911') !== false;
            $is_all_zeros = $phone === '555-000-0000';

            $attempts++;
            if ($attempts > 100) {
                // Return safe number after max attempts
                return '555-' . rand(100, 899) . '-' . rand(1000, 9999);
            }

        } while ($has_911 || $is_all_zeros);

        return $phone;
    }

    /**
     * Generate random email address
     * Format: wordwordnumbers+test@gmail.com
     */
    #[Ajax_Endpoint]
    public static function email(Request $request, array $params = [])
    {
        self::__check_production();

        $word1 = self::__random_from_list('words');
        $word2 = self::__random_from_list('words');
        $numbers = rand(100, 9999);

        return "{$word1}{$word2}{$numbers}+test@gmail.com";
    }

    /**
     * Generate random website URL
     */
    #[Ajax_Endpoint]
    public static function website(Request $request, array $params = [])
    {
        self::__check_production();

        $word1 = self::__random_from_list('words');
        $word2 = self::__random_from_list('words');
        $tld = ['com', 'net', 'org', 'io', 'co'][rand(0, 4)];

        return "https://{$word1}{$word2}.{$tld}";
    }

    /**
     * Generate random text/paragraph
     */
    #[Ajax_Endpoint]
    public static function text(Request $request, array $params = [])
    {
        self::__check_production();

        $sentence_count = rand(3, 6);
        $sentences = [];

        for ($i = 0; $i < $sentence_count; $i++) {
            $word_count = rand(5, 12);
            $words = [];

            for ($j = 0; $j < $word_count; $j++) {
                $words[] = self::__random_from_list('words');
            }

            $sentence = ucfirst(implode(' ', $words)) . '.';
            $sentences[] = $sentence;
        }

        return implode(' ', $sentences);
    }
}
