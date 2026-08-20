<?php

namespace App\RSpade\Core\Database\Query\Grammars;

/**
 * Custom MySQL Grammar with millisecond precision for timestamps
 *
 * This extends Laravel's MySqlGrammar to support microsecond precision
 * in date formats, allowing for more precise timestamp storage and
 * comparison in MySQL databases.
 */
#[Instantiatable]
class Query_MySqlGrammar extends \Illuminate\Database\Query\Grammars\MySqlGrammar
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