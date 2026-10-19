<?php
/**
 * CODING CONVENTION: snake_case for variable_names and function_names.
 */

namespace Rsx\Tests;

use Illuminate\Support\Facades\DB;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;
use Rsx\Models\Client_Model;

/**
 * Client_Address_Codes_Test - a client's address codes are ISO: address_country an ISO
 * 3166-1 alpha-2 code, state an ISO 3166-2 subdivision code OF THAT COUNTRY (or 'N/A').
 * Client_Model::address_code_errors() is the one rule both write paths check (the staff
 * save and the external API). Fabricated countries and subdivisions make the cases certain
 * whatever the test database's geography holds. Runs in the default per-test transaction.
 */
class Client_Address_Codes_Test extends Rsx_Test_Abstract
{
    private static function __seed(): void
    {
        DB::table('countries')->insert([
            ['alpha2' => 'XA', 'alpha3' => 'XAA', 'numeric' => '998', 'name' => 'Test Country XA', 'enabled' => 1],
            ['alpha2' => 'XB', 'alpha3' => 'XBB', 'numeric' => '997', 'name' => 'Test Country XB', 'enabled' => 1],
            ['alpha2' => 'XD', 'alpha3' => 'XDD', 'numeric' => '996', 'name' => 'Test Country XD', 'enabled' => 0],
        ]);

        DB::table('regions')->insert([
            ['country_alpha2' => 'XA', 'code' => 'XA-01', 'name' => 'Region A One', 'enabled' => 1],
            ['country_alpha2' => 'XB', 'code' => 'XB-01', 'name' => 'Region B One', 'enabled' => 1],
        ]);
    }

    public static function test_iso_codes_and_blanks_conform()
    {
        static::__seed();

        static::__assert_equals([], Client_Model::address_code_errors('XA', 'XA-01'));
        static::__assert_equals([], Client_Model::address_code_errors('XA', 'N/A'), 'N/A marks a country with no subdivisions');
        static::__assert_equals([], Client_Model::address_code_errors('XA', ''));
        static::__assert_equals([], Client_Model::address_code_errors(null, null));
    }

    public static function test_a_country_must_be_an_enabled_alpha2_code()
    {
        static::__seed();

        static::__assert_true(isset(Client_Model::address_code_errors('XAA', null)['address_country']), 'an alpha-3 code is refused');
        static::__assert_true(isset(Client_Model::address_code_errors('XD', null)['address_country']), 'a disabled country is refused');
    }

    public static function test_a_state_must_belong_to_the_country()
    {
        static::__seed();

        static::__assert_true(isset(Client_Model::address_code_errors('XA', '01')['state']), 'a bare code without its country prefix is refused');
        static::__assert_true(isset(Client_Model::address_code_errors('XA', 'XB-01')['state']), 'another country\'s subdivision is refused');
        static::__assert_true(isset(Client_Model::address_code_errors('', 'XA-01')['state']), 'a state needs a country');
        static::__assert_equals(['address_country', 'state'], array_keys(Client_Model::address_code_errors('XAA', 'XA-01')));
    }
}
