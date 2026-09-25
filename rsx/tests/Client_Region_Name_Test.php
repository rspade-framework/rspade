<?php
/**
 * CODING CONVENTION: snake_case for variable_names and function_names.
 */

namespace Rsx\Tests;

use Illuminate\Support\Facades\DB;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;
use Rsx\Models\Client_Model;

/**
 * Client_Region_Name_Test - a client's region is resolved within its OWN country.
 *
 * A region code is unique only per country (regions.unique_country_code): 'WA' is
 * Washington in one country and Western Australia in another. region_name() - which rides
 * every client payload as an appended property - therefore keys on the country AND the
 * code; keyed on the code alone it returned whichever country's row came first.
 *
 * Two fabricated countries sharing a region code make the ambiguity certain whatever the
 * test database's geography holds. Runs in the default per-test transaction.
 */
class Client_Region_Name_Test extends Rsx_Test_Abstract
{
    private const COUNTRY_A = 'XA';
    private const COUNTRY_B = 'XB';

    private static function __seed(): void
    {
        foreach ([self::COUNTRY_A => 'XAA', self::COUNTRY_B => 'XBB'] as $alpha2 => $alpha3) {
            DB::table('countries')->insert([
                'alpha2' => $alpha2,
                'alpha3' => $alpha3,
                'numeric' => '999',
                'name' => 'Test Country ' . $alpha2,
            ]);
        }

        DB::table('regions')->insert([
            ['country_alpha2' => self::COUNTRY_A, 'code' => 'WA', 'name' => 'Region A West'],
            ['country_alpha2' => self::COUNTRY_B, 'code' => 'WA', 'name' => 'Region B West'],
        ]);
    }

    private static function __client(?string $country, ?string $state): Client_Model
    {
        $client = new Client_Model();
        $client->address_country = $country;
        $client->state = $state;

        return $client;
    }

    public static function test_the_region_is_looked_up_within_the_clients_country()
    {
        static::__seed();

        static::__assert_equals('Region A West', static::__client(self::COUNTRY_A, 'WA')->region_name());
        static::__assert_equals(
            'Region B West',
            static::__client(self::COUNTRY_B, 'WA')->region_name(),
            'the same code in another country names that country\'s region'
        );
    }

    public static function test_a_code_the_clients_country_does_not_have_reads_back_as_the_code()
    {
        static::__seed();

        static::__assert_equals(
            'WA',
            static::__client('XC', 'WA')->region_name(),
            'another country\'s WA is never borrowed; the raw code is shown'
        );
    }
}
