<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace Rsx\App\Frontend\Revisions;

use Illuminate\Http\Request;
use App\RSpade\Core\Ajax\Ajax;
use App\RSpade\Core\Controller\Rsx_Controller_Abstract;
use App\RSpade\Core\Revisions\Revision_Model;
use App\RSpade\Core\Revisions\Transaction_Model;
use Rsx\Models\Client_Model;
use Rsx\Models\Contact_Model;
use Rsx\Models\Task_Model;

/**
 * Frontend_Revisions_Controller - the read side of the revision history for the staff app.
 *
 * ONE endpoint feeding ONE component (<Revision_History>), for every entity whose history
 * this application exposes. The alternative - a history endpoint per feature controller -
 * would be the same query written three times with three chances to get the record gate
 * wrong.
 *
 * See: php artisan rsx:man revisions
 */
#[Auth('is_logged_in')]
class Frontend_Revisions_Controller extends Rsx_Controller_Abstract
{
    /**
     * The models whose history this application will serve, by simple name.
     *
     * AN ALLOWLIST, not a lookup. `record_type` arrives from the browser, and resolving it
     * to a class by string would turn one endpoint into "read the history of any table in
     * the database" - including the framework's own. A model appears here only once a
     * screen has been built for it.
     */
    private const HISTORY_MODELS = [
        'Client_Model' => Client_Model::class,
        'Contact_Model' => Contact_Model::class,
        'Task_Model' => Task_Model::class,
    ];

    /**
     * How many TRANSACTIONS one page carries.
     *
     * Bounded because `_transactions` grows with customer activity and a busy record has
     * no ceiling: a client edited daily for three years is a thousand transactions, and
     * every one of them decodes N compressed documents to render. 50 is one screenful of
     * timeline plus scroll, and `before_id` walks the rest - so the endpoint returns a
     * PAGE the caller asked for, never a silently truncated "all".
     */
    private const DEFAULT_LIMIT = 50;

    /** The hard ceiling on a caller-supplied limit. */
    private const MAX_LIMIT = 200;

    /**
     * Ajax endpoint: the revision history of one record, newest transaction first.
     *
     * The record is loaded through the MODEL'S OWN fetch(), so every record-level rule that
     * model enforces (site scope, ownership, record state) governs its history too, and a
     * denial is indistinguishable from a missing row - the same anti-enumeration answer the
     * ORM seam gives. There is deliberately no second gate here that could drift from it.
     *
     * @param Request $request
     * @param array $params record_type, record_id, and optionally limit / before_id
     * @return mixed
     */
    #[Ajax_Endpoint]
    public static function history(Request $request, array $params = [])
    {
        $record_type = (string) ($params['record_type'] ?? '');
        $record_id = (int) ($params['record_id'] ?? 0);

        if (!isset(self::HISTORY_MODELS[$record_type])) {
            return response_error(Ajax::ERROR_VALIDATION, 'No revision history is published for "' . $record_type . '".');
        }

        if ($record_id <= 0) {
            return response_error(Ajax::ERROR_VALIDATION, 'record_id is required');
        }

        $model_class = self::HISTORY_MODELS[$record_type];

        if ($model_class::fetch($record_id) === false) {
            return response_error(Ajax::ERROR_NOT_FOUND, 'Record not found');
        }

        $limit = (int) ($params['limit'] ?? self::DEFAULT_LIMIT);
        $limit = max(1, min($limit, self::MAX_LIMIT));
        $before_id = (int) ($params['before_id'] ?? 0);

        // Match the record pair OR the root pair - the same predicate
        // Revision::transactions_for() uses, spelled out here because this screen pages and
        // that helper returns the whole set.
        $transaction_ids = Revision_Model::query()
            ->where(function ($query) use ($record_type, $record_id) {
                $query->where(function ($inner) use ($record_type, $record_id) {
                    $inner->where('record_type', $record_type)->where('record_id', $record_id);
                })->orWhere(function ($inner) use ($record_type, $record_id) {
                    $inner->where('root_type', $record_type)->where('root_id', $record_id);
                });
            })
            ->select('transaction_id');

        $query = Transaction_Model::whereIn('id', $transaction_ids)->orderBy('id', 'desc');

        if ($before_id > 0) {
            $query->where('id', '<', $before_id);
        }

        // One extra row answers "is there another page" without a second COUNT query.
        $rows = $query->limit($limit + 1)->get();
        $has_more = $rows->count() > $limit;
        $rows = $rows->take($limit);

        $transactions = [];
        foreach ($rows as $transaction) {
            $transactions[] = self::_transaction_payload($transaction);
        }

        return [
            'transactions' => $transactions,
            'has_more' => $has_more,
            'next_before_id' => $has_more && !empty($transactions) ? end($transactions)['id'] : null,
        ];
    }

    /**
     * One transaction plus its revisions, decoded.
     *
     * The actor display comes from get_created_by_author(): the transaction row is itself
     * stamped by the audit matrix that resolved its actor pair, so the two name the same
     * identity, and the framework's own resolver already handles a soft-deleted actor and
     * a profile URL the current viewer may not reach. That is also the exact
     * {name, url} shape <Record_Author> takes.
     *
     * @return array
     */
    private static function _transaction_payload(Transaction_Model $transaction): array
    {
        $revisions = [];

        foreach ($transaction->revisions()->orderBy('sequence', 'asc')->get() as $revision) {
            $revisions[] = [
                'id' => (int) $revision->id,
                'record_type' => $revision->record_type,
                'record_id' => (int) $revision->record_id,
                'operation_id' => (int) $revision->operation_id,
                'operation_id__label' => $revision->operation_id__label,
                'sequence' => (int) $revision->sequence,
                'diff' => $revision->diff(),
            ];
        }

        return [
            'id' => (int) $transaction->id,
            'created_at' => $transaction->created_at,
            'source_id' => (int) $transaction->source_id,
            'source_id__label' => $transaction->source_id__label,
            'endpoint' => $transaction->endpoint,
            'description' => $transaction->description,
            'revision_count' => (int) $transaction->revision_count,
            'get_created_by_author' => $transaction->get_created_by_author(),
            'revisions' => $revisions,
        ];
    }
}
