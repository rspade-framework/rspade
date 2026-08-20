<?php

namespace App\RSpade\Core\Database\Schema\Grammars;

/**
 * Custom MySQL Schema Grammar with millisecond precision for timestamps
 *
 * This extends Laravel's MySqlGrammar to support microsecond precision
 * in date formats for schema operations, ensuring consistency with the
 * Query grammar.
 */
#[Instantiatable]
class Schema_MySqlGrammar extends \Illuminate\Database\Schema\Grammars\MySqlGrammar
{
    /**
     * Get the format for database stored dates with millisecond precision
     *
     * @return string
     */
    public function getDateFormat()
    {
        return 'Y-m-d H:i:s.u';
    }
}