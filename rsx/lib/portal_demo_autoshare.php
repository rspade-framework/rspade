<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace Rsx\Lib;

use App\RSpade\Core\Files\File_Attachment_Model;
use App\RSpade\Core\Rsx;
use App\RSpade\Core\Session\Session;
use Rsx\Models\Client_Model;
use Rsx\Models\Contact_Model;
use Rsx\Models\Shared_Item_Model;

/**
 * Portal_Demo_Autoshare
 *
 * DEMONSTRATION-ONLY behavior, active on dev sites only and never during the automated
 * test suite (see _is_demo_active()).
 *
 * On the test/demo site every uploaded client document is automatically shared with the
 * client, so a reviewer sees a populated portal Documents tab without sharing each file
 * by hand. A real deployment shares explicitly through
 * Frontend_Clients_Controller::documents_share() and this class does nothing (the guard
 * returns early).
 *
 * SHARING MODEL (current, demo): documents are shared at the CLIENT level — a shared
 * document is visible to EVERY portal user of that client (visibility is membership-
 * based; the per-contact Shared_Item rows only record who was specifically invited and
 * emailed). This helper marks a document "shared with the client" by creating a
 * Shared_Item for each of the client's contacts. End-user applications will likely
 * replace this with a more granular per-document / per-contact sharing model.
 */
class Portal_Demo_Autoshare
{
    /** Far-future expiry for demo shares (~10 years; stays within the TIMESTAMP range). */
    private const DEMO_EXPIRY_DAYS = 3650;

    /**
     * Demo auto-share is active only on dev sites, and never during the automated test
     * suite (which swaps the default DB connection to 'test') — so it cannot perturb
     * test fixtures that create memberships or upload documents.
     */
    private static function _is_demo_active(): bool
    {
        return Rsx::is_dev_site() && config('database.default') !== 'test';
    }

    /**
     * Mark a newly-uploaded document "shared with the client" so every portal user of
     * the client can see it. Called after a staff upload (documents_add). No-op off dev
     * sites. (New portal members need no backfill: client-level visibility means they
     * see every already-shared document the moment they gain access.)
     */
    public static function share_document_with_client(Client_Model $client, File_Attachment_Model $attachment): void
    {
        if (!static::_is_demo_active()) {
            return;
        }

        $shared_by = (int) (Session::get_user_id() ?? 0);
        foreach (Contact_Model::where('client_id', (int) $client->id)->get() as $contact) {
            if (empty($contact->email)) {
                continue;
            }
            $exists = Shared_Item_Model::where('item_type', 'File_Attachment_Model')
                ->where('item_id', $attachment->id)
                ->where('contact_id', (int) $contact->id)
                ->exists();
            if ($exists) {
                continue;
            }

            Shared_Item_Model::create_share(
                (int) $client->site_id,
                $shared_by,
                (int) $contact->id,
                'File_Attachment_Model',
                (int) $attachment->id,
                null,
                self::DEMO_EXPIRY_DAYS
            );
        }
    }
}
