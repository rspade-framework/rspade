<?php
/**
 * CODING CONVENTION: snake_case for variable_names and function_names.
 */

namespace Rsx\Tests;

use Illuminate\Http\Request;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;
use Rsx\App\Frontend\Party\Frontend_Party_Controller;
use Rsx\Models\Party_Company_Detail_Model;
use Rsx\Models\Party_Model;
use Rsx\Models\Party_Person_Detail_Model;

/**
 * Party_Test - end-to-end coverage of the Class-Table Inheritance demo (see man
 * detail_tables): the controller writes base + detail atomically, name is composed for a
 * Person, a Group has no detail, fetch() embeds the active detail, wrong-type access throws,
 * and editing updates the detail in place (no double-insert).
 */
class Party_Test extends Rsx_Test_Abstract
{
    private const SITE_ID = 1;

    public static function setup(): void
    {
        static::__acting_as_site(self::SITE_ID);
        // fetch() gates on a logged-in session (audit remediation 2026-08-05)
        static::__acting_as_user(1);
    }

    private static function __save(array $params)
    {
        return Frontend_Party_Controller::save(new Request(), $params);
    }

    public static function test_save_person_creates_base_and_detail_with_composed_name()
    {
        $result = static::__save([
            'type_id' => Party_Model::TYPE_PERSON,
            'first_name' => 'Jane',
            'last_name' => 'Doe',
            'title' => 'CEO',
            'email' => 'jane@example.com',
        ]);

        static::__assert_array_has_key('party_id', $result, 'person save succeeds');

        $party = Party_Model::find($result['party_id']);
        static::__assert_equals(Party_Model::TYPE_PERSON, (int) $party->type_id);
        static::__assert_equals('Jane Doe', $party->name, 'base name is composed from first + last');

        $detail = Party_Person_Detail_Model::for_parent($party->id);
        static::__assert_not_null($detail, 'person detail row created');
        static::__assert_equals('CEO', $detail->title);
    }

    public static function test_save_company_uses_legal_name_as_base_name()
    {
        $result = static::__save([
            'type_id' => Party_Model::TYPE_COMPANY,
            'legal_name' => 'Acme Corporation',
            'tax_identifier' => '12-3456789',
            'industry' => 'Manufacturing',
            'employee_count' => 250,
        ]);

        $party = Party_Model::find($result['party_id']);
        static::__assert_equals('Acme Corporation', $party->name);

        $detail = Party_Company_Detail_Model::for_parent($party->id);
        static::__assert_not_null($detail, 'company detail row created');
        static::__assert_equals('12-3456789', $detail->tax_identifier);
        static::__assert_equals(250, (int) $detail->employee_count);
    }

    public static function test_save_group_has_no_detail()
    {
        $result = static::__save([
            'type_id' => Party_Model::TYPE_GROUP,
            'name' => 'Regional Partners',
        ]);

        $party = Party_Model::find($result['party_id']);
        static::__assert_equals('Regional Partners', $party->name);
        static::__assert_null($party->active_detail(), 'a Group has no detail row');
    }

    public static function test_fetch_embeds_active_detail()
    {
        $result = static::__save([
            'type_id' => Party_Model::TYPE_PERSON,
            'first_name' => 'Embed',
            'last_name' => 'Test',
        ]);

        $data = Party_Model::fetch($result['party_id']);
        static::__assert_array_has_key('__details', $data, 'fetch payload carries embedded details');
        static::__assert_equals('Embed', $data['__details']['party_person']['first_name']);
    }

    public static function test_wrong_type_accessor_throws()
    {
        $result = static::__save([
            'type_id' => Party_Model::TYPE_PERSON,
            'first_name' => 'Wrong',
            'last_name' => 'Type',
        ]);
        $party = Party_Model::find($result['party_id']);

        static::__assert_throws(\RuntimeException::class, function () use ($party) {
            return $party->party_company;
        }, 'not valid');
    }

    public static function test_edit_updates_detail_in_place()
    {
        $created = static::__save([
            'type_id' => Party_Model::TYPE_PERSON,
            'first_name' => 'Edit',
            'last_name' => 'Before',
        ]);

        $updated = static::__save([
            'id' => $created['party_id'],
            'type_id' => Party_Model::TYPE_PERSON,
            'first_name' => 'Edit',
            'last_name' => 'After',
            'title' => 'Director',
        ]);

        static::__assert_array_has_key('party_id', $updated, 'edit succeeds without a double-insert');

        $party = Party_Model::find($created['party_id']);
        static::__assert_equals('Edit After', $party->name, 'name recomposed on edit');

        $detail = Party_Person_Detail_Model::for_parent($party->id);
        static::__assert_equals('After', $detail->last_name);
        static::__assert_equals('Director', $detail->title);
        static::__assert_count(1, Party_Person_Detail_Model::where('party_id', $party->id)->get(), 'exactly one detail row (no double-insert)');
    }

    public static function test_delete_soft_deletes_party()
    {
        $created = static::__save([
            'type_id' => Party_Model::TYPE_PERSON,
            'first_name' => 'Delete',
            'last_name' => 'Me',
        ]);

        $result = Frontend_Party_Controller::delete(new Request(), ['id' => $created['party_id']]);
        static::__assert_true($result['deleted'], 'delete reports success');
        static::__assert_null(Party_Model::find($created['party_id']), 'party is soft-deleted (excluded from default scope)');
    }

    public static function test_validation_requires_type_specific_fields()
    {
        $result = static::__save([
            'type_id' => Party_Model::TYPE_PERSON,
            'first_name' => 'NoLastName',
        ]);

        static::__assert_false(is_array($result) && isset($result['party_id']), 'missing required detail field is rejected at the controller layer');
    }
}
