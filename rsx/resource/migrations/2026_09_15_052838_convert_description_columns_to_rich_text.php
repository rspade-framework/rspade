<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Re-encode three plain-text `description` columns into the storage form Rich_Text holds,
 * so that Task_Model, User_Group_Model and Demo_Product_Model may declare
 * `'description' => Rich_Text::class`.
 *
 * ACT ONE OF TWO. Changing a column's type is a re-encode followed by the declaration;
 * declaring first would leave every existing row claiming an encoding it does not have -
 * plain text with a literal `5 < 6` in it would be read as markup, and a user's `&` would
 * print as the start of an entity.
 *
 * WHAT THE RE-ENCODE IS. Rich_Text::from_string() is the plain-text-to-HTML path:
 * htmlspecialchars(ENT_QUOTES | ENT_HTML5), then nl2br(), then a <p> wrapper, then the
 * type's own filter_set() (HTMLPurifier). The SQL below reproduces that, and it is written
 * out rather than calling the class because a migration runs against a schema, not against
 * an application: the class may be renamed, moved or deleted long before this file stops
 * running on a fresh database.
 *
 * The transformation, in the order the PHP applies it:
 *
 *   1. & -> &amp;, then < -> &lt;, then > -> &gt;. The `&` pass runs FIRST so the
 *      ampersands the later passes introduce are not escaped a second time.
 *      htmlspecialchars also encodes the two quote characters, but HTMLPurifier decodes
 *      `&quot;` and `&#039;` back to literal quotes in text content, so the stored form
 *      carries literal quotes and the SQL does not encode them.
 *   2. Line-break normalization: CRLF, LFCR and a lone CR each become one LF. nl2br()
 *      treats each of those as ONE break, and HTMLPurifier normalizes the CR out of the
 *      output it keeps.
 *   3. Each remaining LF becomes "<br />\n" - nl2br() inserts the tag BEFORE the newline
 *      and keeps the newline.
 *   4. The whole thing is wrapped in <p>...</p>.
 *
 * Nothing else survives that pipeline differently: HTMLPurifier is a passthrough for
 * already-escaped text inside a single <p>, which is verified for these exact cases by
 * rsx/tests/Text_Type_Descriptions_Test.php (it asserts the SQL expression and
 * Rich_Text::from_string() agree, character for character, on a fixture carrying &, <, >,
 * quotes and newlines).
 *
 * ONE SEQUENCE IS NOT REPRODUCED CHARACTER FOR CHARACTER: an LF immediately followed by a
 * CR. nl2br() treats that pair as a single break and keeps both characters, so PHP leaves
 * a stray newline inside the paragraph that this SQL normalizes away. Both forms carry the
 * same single <br />, so they render identically; no browser textarea submits the pair
 * (they submit CRLF), and LF, CRLF and a lone CR are all exact.
 *
 * NULL stays NULL and '' stays '': an absent description has nothing to encode, and
 * Rich_Text reads both back as an empty value.
 */
return new class extends Migration
{
    /**
     * @return void
     */
    public function up()
    {
        foreach (['tasks', 'user_groups', 'demo_products'] as $table) {
            DB::statement("
                UPDATE {$table}
                SET description = CONCAT(
                    '<p>',
                    REPLACE(
                        REPLACE(
                            REPLACE(
                                REPLACE(
                                    REPLACE(
                                        REPLACE(
                                            REPLACE(description, '&', '&amp;'),
                                        '<', '&lt;'),
                                    '>', '&gt;'),
                                CONCAT(CHAR(13), CHAR(10)), CHAR(10)),
                            CONCAT(CHAR(10), CHAR(13)), CHAR(10)),
                        CHAR(13), CHAR(10)),
                    CHAR(10), CONCAT('<br />', CHAR(10))),
                    '</p>'
                )
                WHERE description IS NOT NULL
                  AND description <> ''
            ");
        }
    }

    /**
     * down() method is prohibited in RSpade framework
     * Migrations should only move forward, never backward
     */
};
