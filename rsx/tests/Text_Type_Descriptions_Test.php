<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace Rsx\Tests;

use Illuminate\Support\Facades\DB;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;
use Rsx\Lib\TextTypes\RichText\Rich_Text;
use Rsx\Models\Demo_Product_Model;
use Rsx\Models\Task_Model;
use Rsx\Models\User_Group_Model;

/**
 * The three `description` columns that were converted from plain text to Rich_Text, and
 * the migration that re-encoded the rows they already held.
 *
 * Text_Type_Assignment_Test pins what a declared column DOES, on Project_Model. This pins
 * the three columns that joined it, plus the one thing only a conversion can get wrong:
 * the migration rewrote existing rows in SQL, and SQL has to produce exactly what
 * Rich_Text::from_string() produces. A drift there is silent - the row is valid HTML
 * either way, it is just not the HTML the type would have written, and a user's literal
 * `5 < 6` turns into a broken tag with nothing to report it.
 */
class Text_Type_Descriptions_Test extends Rsx_Test_Abstract
{
    private const SITE_ID = 1;

    /**
     * The expression the migration applies to a plain-text description, restated as a
     * SELECT over one bound value. It must stay identical to
     * rsx/resource/migrations/2026_09_15_052838_convert_description_columns_to_rich_text.php
     * - that is what this test exists to check.
     */
    private const MIGRATION_EXPRESSION = "CONCAT(
        '<p>',
        REPLACE(
            REPLACE(
                REPLACE(
                    REPLACE(
                        REPLACE(
                            REPLACE(
                                REPLACE(?, '&', '&amp;'),
                            '<', '&lt;'),
                        '>', '&gt;'),
                    CONCAT(CHAR(13), CHAR(10)), CHAR(10)),
                CONCAT(CHAR(10), CHAR(13)), CHAR(10)),
            CHAR(13), CHAR(10)),
        CHAR(10), CONCAT('<br />', CHAR(10))),
        '</p>'
    )";

    public static function setup(): void
    {
        static::__acting_as_site(self::SITE_ID);
    }

    private static function __unique(string $prefix): string
    {
        return $prefix . ' ' . str_replace('.', '', uniqid('', true));
    }

    private static function __seed_task(): Task_Model
    {
        $task = new Task_Model();
        $task->site_id = self::SITE_ID;
        $task->title = static::__unique('TextType task');
        $task->status = Task_Model::STATUS_PENDING;
        $task->priority = Task_Model::PRIORITY_MEDIUM;
        $task->save();

        return $task;
    }

    private static function __seed_group(): User_Group_Model
    {
        $group = new User_Group_Model();
        $group->site_id = self::SITE_ID;
        $group->name = static::__unique('TextType group');
        $group->save();

        return $group;
    }

    private static function __seed_product(): Demo_Product_Model
    {
        $product = new Demo_Product_Model();
        $product->name = static::__unique('TextType product');
        $product->price = 1.00;
        $product->status_id = Demo_Product_Model::STATUS_AVAILABLE;
        $product->category_id = Demo_Product_Model::CATEGORY_BOOKS;
        $product->save();

        return $product;
    }

    /**
     * Every converted column: a bare string assigned to it reads back as the column's
     * type, not as a string, and survives a round trip through storage.
     */
    public static function test_a_bare_string_reads_back_as_rich_text_on_every_converted_column()
    {
        $task = static::__seed_task();
        $task->description = '<p>a task <b>description</b></p>';
        $task->save();

        $group = static::__seed_group();
        $group->description = '<p>a group description</p>';
        $group->save();

        $product = static::__seed_product();
        $product->description = '<p>a product description</p>';
        $product->save();

        static::__assert_instance_of(Rich_Text::class, Task_Model::find($task->id)->description, 'tasks.description');
        static::__assert_instance_of(Rich_Text::class, User_Group_Model::find($group->id)->description, 'user_groups.description');
        static::__assert_instance_of(Rich_Text::class, Demo_Product_Model::find($product->id)->description, 'demo_products.description');
    }

    /**
     * The column's filter runs on assignment, on every one of them. Before the
     * declaration these columns were stored exactly as submitted.
     */
    public static function test_the_filter_strips_a_script_on_every_converted_column()
    {
        $hostile = '<p>keep</p><script>alert(1)</script><img src=x onerror=alert(2)>';

        $records = [
            'tasks' => static::__seed_task(),
            'user_groups' => static::__seed_group(),
            'demo_products' => static::__seed_product(),
        ];

        foreach ($records as $table => $record) {
            $record->description = $hostile;

            $stored = $record->description->to_storage();

            static::__assert_contains('keep', $stored, $table . ': the content survives');
            static::__assert_false(str_contains($stored, '<script'), $table . ': the script is gone');
            static::__assert_false(str_contains($stored, 'onerror'), $table . ': the handler is gone');
        }
    }

    /**
     * The plain rendition a CSV cell, a list excerpt or a search index asks for. The
     * groups grid and the groups CSV export both depend on this.
     */
    public static function test_to_text_reduces_a_stored_value_to_plain_text()
    {
        $group = static::__seed_group();
        $group->description = '<p>first block</p><p>second <b>block</b></p>';
        $group->save();

        $text = User_Group_Model::find($group->id)->description->to_text();

        static::__assert_false(str_contains($text, '<'), 'no markup survives');
        static::__assert_contains('first block', $text);
        static::__assert_contains('second block', $text);
    }

    /**
     * An emptied editor stores a document that is empty by CONTENT, and the list and view
     * templates ask is_empty() rather than testing the string.
     */
    public static function test_an_empty_document_is_empty_by_content_not_by_string()
    {
        $task = static::__seed_task();
        $task->description = '<p><br></p>';

        static::__assert_true($task->description->is_empty(), 'the type knows its own encoding');
        static::__assert_false($task->description->to_storage() === '', 'and the string it holds is not empty');
    }

    /**
     * THE MIGRATION. A row is seeded in the OLD plain-text form with a raw UPDATE (which
     * bypasses the cast exactly as a pre-conversion row did), the migration's own SQL
     * expression is applied to it, and the result must equal what Rich_Text::from_string()
     * produces for the same input - character for character.
     *
     * The fixture carries every character the expression treats specially: an ampersand,
     * both angle brackets, both quote characters, a single newline and a blank line.
     */
    public static function test_the_migrations_sql_re_encodes_exactly_as_from_string_does()
    {
        $plain = "a & b < c > d \"quoted\" and 'single'\nsecond line\n\nfourth line";

        $product = static::__seed_product();

        // The pre-conversion state: plain text sitting in the column, written the way it
        // was written before the type was declared.
        DB::update('UPDATE demo_products SET description = ? WHERE id = ?', [$plain, $product->id]);

        // The migration's transformation, applied to that one row.
        DB::update(
            'UPDATE demo_products SET description = ' . str_replace('?', 'description', self::MIGRATION_EXPRESSION)
            . ' WHERE id = ?',
            [$product->id]
        );

        $migrated = DB::table('demo_products')->where('id', $product->id)->value('description');

        static::__assert_equals(
            Rich_Text::from_string($plain)->to_storage(),
            $migrated,
            'the SQL and the type agree on what plain text becomes'
        );

        // And the re-encoded row reads back as a real value that still carries every
        // character of the original. Not an equality against $plain: to_text() reads a
        // <br /> AND the newline beside it as block boundaries, so the rendition of a
        // re-encoded value has the paragraph shape of the HTML, not of the plain text it
        // came from. What must not happen is content going missing or an escape leaking
        // through as markup.
        $reloaded = Demo_Product_Model::find($product->id);

        static::__assert_instance_of(Rich_Text::class, $reloaded->description);

        $text = $reloaded->description->to_text();

        foreach (['a & b < c > d "quoted" and \'single\'', 'second line', 'fourth line'] as $fragment) {
            static::__assert_contains($fragment, $text, 'the re-encode kept: ' . $fragment);
        }
    }

    /**
     * The same expression, bound rather than applied to a column, over the awkward
     * characters one at a time. A failure here names WHICH character drifted.
     */
    public static function test_the_sql_expression_matches_from_string_character_by_character()
    {
        $cases = [
            'plain' => 'a simple description',
            'ampersand' => 'tea & biscuits',
            'angle brackets' => '5 < 6 and 7 > 3',
            'quotes' => 'she said "hello" and it\'s fine',
            'an entity the user typed' => 'already &amp; encoded',
            'one newline' => "line one\nline two",
            'a blank line' => "para one\n\npara two",
            'a windows newline' => "line one\r\nline two",
            'a lone carriage return' => "line one\rline two",
            'markup the user typed' => '<script>alert(1)</script>',
        ];

        foreach ($cases as $label => $plain) {
            $sql = DB::selectOne('SELECT ' . self::MIGRATION_EXPRESSION . ' AS v', [$plain])->v;

            static::__assert_equals(Rich_Text::from_string($plain)->to_storage(), $sql, $label);
        }
    }
}
