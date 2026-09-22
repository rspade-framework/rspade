<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace Rsx\Tests;

use App\RSpade\Core\Database\TextTypes\Rsx_Text_Abstract;
use App\RSpade\Core\Database\TextTypes\Rsx_Text_Request_Value;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;
use Rsx\Lib\TextTypes\RawText\Raw_Text;
use Rsx\Lib\TextTypes\RichText\Rich_Text;
use Rsx\Models\Project_Model;

/**
 * Declared text types at the COLUMN: what assignment does, on a real model.
 *
 * The framework suite pins the type contract against fixtures; it has no model to assign
 * to. This pins the half that only a declared column can show, and each case is a way the
 * feature could be silently wrong rather than loudly broken:
 *
 *   - Assigning a typeless request value and reading it BACK must yield the column's type,
 *     filtered - not the wrapper. Eloquent's default is to cache the assigned object and
 *     hand it straight back, which would make assign-then-validate (the pattern every
 *     endpoint is told to use) validate an unfiltered, untyped value. Rsx_Text_Cast turns
 *     that caching off; this is the test that notices if it comes back.
 *   - The client's claimed type is IGNORED. A wrapper claiming a class that does not exist
 *     stores fine, typed by the column.
 *   - The filter runs on assignment, once, with the column's type.
 *   - A BARE string is plain text: escaped into the column's encoding, never read as markup.
 *   - A typed value of the WRONG type is refused, never converted.
 */
class Text_Type_Assignment_Test extends Rsx_Test_Abstract
{
    private const SITE_ID = 1;

    public static function setup(): void
    {
        static::__acting_as_site(self::SITE_ID);
    }

    /**
     * A project to assign to. Saved, so the round trip through storage is real.
     */
    private static function __seed_project(): Project_Model
    {
        $project = new Project_Model();
        $project->name = 'TextType ' . str_replace('.', '', uniqid('', true));
        $project->client_id = 1;
        $project->status = Project_Model::STATUS_ACTIVE;
        $project->priority = Project_Model::PRIORITY_MEDIUM;
        $project->save();

        return $project;
    }

    /**
     * What the Ajax boundary hands an endpoint for a declared column.
     */
    private static function __request_value(string $claimed, string $raw, bool $empty = false): Rsx_Text_Request_Value
    {
        return Rsx_Text_Abstract::hydrate_request_value([
            'v' => ['__TEXT' => $claimed, 'raw' => $raw, 'empty' => $empty],
        ])['v'];
    }

    public static function test_assigning_a_request_value_reads_back_as_the_columns_type_not_the_wrapper()
    {
        $project = static::__seed_project();

        $project->description = static::__request_value('Rich_Text', '<p>hello</p>');

        // THE assertion. Before Rsx_Text_Cast disabled object caching this returned the
        // Rsx_Text_Request_Value that was assigned, and assign-then-validate was a lie.
        static::__assert_instance_of(Rich_Text::class, $project->description, 'read-back is typed by the column');
        static::__assert_false($project->description->is_empty(), 'and it is the real value, not the wrapper');
    }

    public static function test_the_clients_claimed_type_is_ignored_and_the_column_decides()
    {
        $project = static::__seed_project();

        // A claim naming nothing at all. If the claim were resolved this could not even
        // reach assignment; if it were trusted the column would hold the wrong type.
        $project->description = static::__request_value('Totally_Fake_Class', '<p>ok</p>');
        $project->save();

        $reloaded = Project_Model::find($project->id);

        static::__assert_instance_of(Rich_Text::class, $reloaded->description);
        static::__assert_equals('<p>ok</p>', $reloaded->description->to_storage());
    }

    public static function test_the_columns_filter_runs_on_an_encoded_value()
    {
        $project = static::__seed_project();
        $hostile = '<p>hi</p><script>alert(1)</script><img src=x onerror=alert(2)>';

        $project->description = static::__request_value('Rich_Text', $hostile);
        $via_wrapper = $project->description->to_storage();

        $project->description = Rich_Text::from_untrusted($hostile);
        $via_explicit = $project->description->to_storage();

        static::__assert_equals($via_explicit, $via_wrapper, 'the wrapper path and the explicit encoded path are the same filter');
        static::__assert_false(str_contains($via_wrapper, '<script'), 'the script is gone');
        static::__assert_false(str_contains($via_wrapper, 'onerror'), 'the handler is gone');
    }

    public static function test_a_bare_string_is_plain_text_escaped_into_the_encoding()
    {
        $project = static::__seed_project();

        // An import or a plain API param: nothing says this is markup, so it is text. The
        // angle-bracketed address must survive as text instead of being purified away as
        // an unknown tag, and the line break must survive as a break.
        $project->description = "Contact <john@acme.com>\nre: <b>billing</b>";

        static::__assert_equals(
            Rich_Text::from_string("Contact <john@acme.com>\nre: <b>billing</b>")->to_storage(),
            $project->description->to_storage(),
            'assignment of a bare string is from_string()'
        );
        static::__assert_true(str_contains($project->description->to_text(), 'Contact <john@acme.com>'), 'the address survives as text');
        static::__assert_true(str_contains($project->description->to_text(), 're: <b>billing</b>'), 'the tag survives as literal text');
        static::__assert_true(str_contains($project->description->to_storage(), '<br'), 'the line break survives as a break');
    }

    public static function test_a_raw_text_column_keeps_markup_as_literal_text()
    {
        $project = static::__seed_project();

        $project->notes = static::__request_value('Raw_Text', "line one\nline <b>two</b>");
        $project->save();

        $reloaded = Project_Model::find($project->id);

        static::__assert_instance_of(Raw_Text::class, $reloaded->notes);
        // Raw_Text's filter is a declared passthrough: the tag is CONTENT here, and the
        // printer escapes it. Nothing was stripped, nothing was interpreted.
        static::__assert_equals("line one\nline <b>two</b>", $reloaded->notes->to_storage());
    }

    public static function test_a_typed_value_of_the_wrong_type_is_refused_not_converted()
    {
        $project = static::__seed_project();

        static::__assert_throws(
            \InvalidArgumentException::class,
            function () use ($project) {
                $project->description = Raw_Text::from_storage('plain');
            },
            'do not convert'
        );
    }

    public static function test_a_typed_value_refuses_to_be_a_string_so_a_lossy_write_cannot_happen()
    {
        $project = static::__seed_project();
        $project->description = Rich_Text::from_untrusted('<p>keep <b>this</b></p>');

        // The write this guards against: concatenation would strip the markup and store
        // something that looks fine. It must throw before it can reach the cast.
        static::__assert_throws(
            \LogicException::class,
            function () use ($project) {
                $project->description = $project->description . ' appended';
            },
            'cannot be used as a string'
        );
    }

    public static function test_assign_then_validate_sees_an_encoding_empty_document_as_empty()
    {
        $project = static::__seed_project();

        // An emptied WYSIWYG: a non-empty string holding an empty document. The wrapper
        // carries the browser's verdict; after assignment the COLUMN'S type answers from
        // the content itself.
        $project->description = static::__request_value('Rich_Text', '<p><br></p>', true);

        static::__assert_true($project->description->is_empty(), 'the typed value knows its own encoding');
    }
}
