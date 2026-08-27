<?php

namespace Rsx\App\Api\V1;

use Illuminate\Http\Request;
use App\RSpade\Core\Api\Rsx_Api;
use App\RSpade\Core\Api\Rsx_Api_Controller_Abstract;
use App\RSpade\Core\Files\File_Attachment_Model;
use Rsx\Models\Client_Model;

/**
 * Clients API - external REST CRUD for client (company) records.
 *
 * All endpoints operate within the authenticated key's site (the global site scope
 * stamps and filters site_id automatically - there is no manual site_id handling).
 * Records serialize through the model's redacting toArray() (enum __label fields and
 * __MODEL included). Delete is a soft delete, mirroring the staff clients feature.
 *
 * The staff form's free-form "tags" array is not exposed here: v1 #[Api_Param] types
 * are scalar only (array/json params are backlogged), so tags are left unchanged.
 *
 * AUTH. Gated 'is_logged_in': the Bearer key establishes a headless staff identity,
 * and that identity plus the automatic site scope IS the authorization boundary for
 * v1. The 'can_use_api' gate (PERM_API_ACCESS) exists in rsx/permission.php and is
 * the natural tightening, but is deliberately NOT applied here - keys minted before
 * the permission existed belong to users who may not carry it, and adding it would
 * silently break live keys.
 */
#[Auth('is_logged_in')]
class Clients_Api_Controller extends Rsx_Api_Controller_Abstract
{
    /**
     * List clients.
     *
     * Returns a paginated list of clients for the current site, ordered by name.
     * Optional case-insensitive search matches name or email.
     *
     * @api-response
     * {
     *   "items": [
     *     {
     *       "id": 3,
     *       "site_id": 1,
     *       "name": "Acme Corp",
     *       "email": "hello@acme.example.com",
     *       "status_id": 1,
     *       "status_id__label": "Active",
     *       "priority": 2,
     *       "priority__label": "Medium",
     *       "__MODEL": "Client_Model"
     *     }
     *   ],
     *   "meta": { "page": 1, "per_page": 20, "total": 1, "total_pages": 1 }
     * }
     */
    #[Api_Endpoint('/api/v1/clients', methods: ['GET'])]
    #[Api_Param('page', type: 'int', default: 1, description: 'Page number (1-based)')]
    #[Api_Param('per_page', type: 'int', default: 20, description: 'Results per page (max 100)')]
    #[Api_Param('search', type: 'string', description: 'Search by name or email')]
    public static function list(Request $request, array $params = [])
    {
        $page = max(1, (int) $params['page']);
        $per_page = min(100, max(1, (int) $params['per_page']));
        $search = $params['search'] ?? null;

        $query = Client_Model::query();

        if ($search !== null && $search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%");
            });
        }

        $total = $query->count();
        $clients = $query->orderBy('name')
            ->skip(($page - 1) * $per_page)
            ->take($per_page)
            ->get();

        return [
            'items' => $clients,
            'meta' => [
                'page' => $page,
                'per_page' => $per_page,
                'total' => $total,
                'total_pages' => (int) ceil($total / $per_page),
            ],
        ];
    }

    /**
     * Get a single client.
     *
     * Returns the full client record. A soft-deleted or cross-site id is not found.
     *
     * @api-response
     * {
     *   "id": 3,
     *   "site_id": 1,
     *   "name": "Acme Corp",
     *   "email": "hello@acme.example.com",
     *   "website": "acme.com",
     *   "phone": "(555) 100-2000",
     *   "status_id": 1,
     *   "status_id__label": "Active",
     *   "priority": 2,
     *   "priority__label": "Medium",
     *   "__MODEL": "Client_Model"
     * }
     */
    #[Api_Endpoint('/api/v1/clients/:id', methods: ['GET'])]
    #[Api_Param('id', type: 'int', required: true, description: 'Client ID')]
    public static function get(Request $request, array $params = [])
    {
        $client = Client_Model::find($params['id']);

        if (!$client) {
            return Rsx_Api::not_found('Client not found');
        }

        return $client;
    }

    /**
     * Create a client.
     *
     * Requires name (mirrors the staff save form). A supplied email must be valid and
     * a supplied website must be a valid URL (stored in short form). site_id is stamped
     * automatically from the authenticated key.
     *
     * @api-response
     * {
     *   "id": 22,
     *   "site_id": 1,
     *   "name": "Globex",
     *   "email": "contact@globex.example.com",
     *   "website": "globex.com",
     *   "address_country": "USA",
     *   "status_id": 1,
     *   "status_id__label": "Active",
     *   "newsletter_opt_in": false,
     *   "__MODEL": "Client_Model"
     * }
     */
    #[Api_Endpoint('/api/v1/clients/create', methods: ['POST'])]
    #[Api_Param('name', type: 'string', required: true, description: 'Company name')]
    #[Api_Param('email', type: 'string', description: 'Primary email address')]
    #[Api_Param('website', type: 'string', description: 'Website URL')]
    #[Api_Param('phone', type: 'string', description: 'Phone number')]
    #[Api_Param('fax', type: 'string', description: 'Fax number')]
    #[Api_Param('address_street', type: 'string', description: 'Street address')]
    #[Api_Param('city', type: 'string', description: 'City')]
    #[Api_Param('state', type: 'string', description: 'State / region code')]
    #[Api_Param('zip', type: 'string', description: 'ZIP / postal code')]
    #[Api_Param('address_country', type: 'string', default: 'USA', description: 'Country code')]
    #[Api_Param('industry', type: 'string', description: 'Industry')]
    #[Api_Param('company_size', type: 'string', description: 'Company size')]
    #[Api_Param('established_year', type: 'int', description: 'Year established')]
    #[Api_Param('revenue_range', type: 'string', description: 'Revenue range')]
    #[Api_Param('facebook_url', type: 'string', description: 'Facebook URL')]
    #[Api_Param('twitter_handle', type: 'string', description: 'Twitter handle')]
    #[Api_Param('linkedin_url', type: 'string', description: 'LinkedIn URL')]
    #[Api_Param('instagram_handle', type: 'string', description: 'Instagram handle')]
    #[Api_Param('preferred_contact_method', type: 'string', default: 'email', description: 'Preferred contact method')]
    #[Api_Param('status_id', type: 'int', default: 1, description: 'Status (1=Active,2=Inactive,3=Prospect,4=Archived)')]
    #[Api_Param('newsletter_opt_in', type: 'bool', default: false, description: 'Newsletter opt-in')]
    #[Api_Param('notes', type: 'string', description: 'Internal notes')]
    public static function create(Request $request, array $params = [])
    {
        $errors = [];

        if (isset($params['email']) && $params['email'] !== '' && !filter_var($params['email'], FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'Please enter a valid email address';
        }

        // Website is stored in short-url form (protocol stripped), validated like the staff form.
        $website = null;
        if (isset($params['website']) && $params['website'] !== '') {
            $website = full_url_to_short_url($params['website']);
            if (!validate_short_url($website)) {
                $errors['website'] = 'Please enter a valid website URL';
            }
        }

        if (!empty($errors)) {
            return Rsx_Api::validation_error($errors);
        }

        $client = new Client_Model();

        // Explicit field assignment (no mass assignment). site_id is stamped by the
        // site-scoped model on save.
        $client->name = $params['name'];
        $client->email = $params['email'] ?? null;
        $client->website = $website;
        $client->phone = $params['phone'] ?? null;
        $client->fax = $params['fax'] ?? null;
        $client->address_street = $params['address_street'] ?? null;
        $client->city = $params['city'] ?? null;
        $client->state = $params['state'] ?? null;
        $client->zip = $params['zip'] ?? null;
        $client->address_country = $params['address_country'];
        $client->industry = $params['industry'] ?? null;
        $client->company_size = $params['company_size'] ?? null;
        $client->established_year = isset($params['established_year']) ? (int) $params['established_year'] : null;
        $client->revenue_range = $params['revenue_range'] ?? null;
        $client->facebook_url = $params['facebook_url'] ?? null;
        $client->twitter_handle = $params['twitter_handle'] ?? null;
        $client->linkedin_url = $params['linkedin_url'] ?? null;
        $client->instagram_handle = $params['instagram_handle'] ?? null;
        $client->preferred_contact_method = $params['preferred_contact_method'];
        $client->status_id = $params['status_id'];
        $client->newsletter_opt_in = $params['newsletter_opt_in'] ? 1 : 0;
        $client->notes = $params['notes'] ?? null;

        $client->save();

        return Rsx_Api::created($client);
    }

    /**
     * Update a client.
     *
     * Applies only the fields present in the request; omitted fields are left
     * unchanged. A present name must be non-empty, a present email must be valid, and
     * a present website must be a valid URL (stored in short form).
     *
     * @api-response
     * {
     *   "id": 22,
     *   "site_id": 1,
     *   "name": "Globex International",
     *   "email": "contact@globex.example.com",
     *   "status_id": 3,
     *   "status_id__label": "Prospect",
     *   "__MODEL": "Client_Model"
     * }
     */
    #[Api_Endpoint('/api/v1/clients/:id/update', methods: ['POST'])]
    #[Api_Param('id', type: 'int', required: true, description: 'Client ID')]
    #[Api_Param('name', type: 'string', description: 'Company name')]
    #[Api_Param('email', type: 'string', description: 'Primary email address')]
    #[Api_Param('website', type: 'string', description: 'Website URL')]
    #[Api_Param('phone', type: 'string', description: 'Phone number')]
    #[Api_Param('fax', type: 'string', description: 'Fax number')]
    #[Api_Param('address_street', type: 'string', description: 'Street address')]
    #[Api_Param('city', type: 'string', description: 'City')]
    #[Api_Param('state', type: 'string', description: 'State / region code')]
    #[Api_Param('zip', type: 'string', description: 'ZIP / postal code')]
    #[Api_Param('address_country', type: 'string', description: 'Country code')]
    #[Api_Param('industry', type: 'string', description: 'Industry')]
    #[Api_Param('company_size', type: 'string', description: 'Company size')]
    #[Api_Param('established_year', type: 'int', description: 'Year established')]
    #[Api_Param('revenue_range', type: 'string', description: 'Revenue range')]
    #[Api_Param('facebook_url', type: 'string', description: 'Facebook URL')]
    #[Api_Param('twitter_handle', type: 'string', description: 'Twitter handle')]
    #[Api_Param('linkedin_url', type: 'string', description: 'LinkedIn URL')]
    #[Api_Param('instagram_handle', type: 'string', description: 'Instagram handle')]
    #[Api_Param('preferred_contact_method', type: 'string', description: 'Preferred contact method')]
    #[Api_Param('status_id', type: 'int', description: 'Status (1=Active,2=Inactive,3=Prospect,4=Archived)')]
    #[Api_Param('newsletter_opt_in', type: 'bool', description: 'Newsletter opt-in')]
    #[Api_Param('notes', type: 'string', description: 'Internal notes')]
    public static function update(Request $request, array $params = [])
    {
        $client = Client_Model::find($params['id']);

        if (!$client) {
            return Rsx_Api::not_found('Client not found');
        }

        $errors = [];
        if (array_key_exists('name', $params) && trim($params['name']) === '') {
            $errors['name'] = 'Company name is required';
        }
        if (array_key_exists('email', $params) && $params['email'] !== '' && !filter_var($params['email'], FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'Please enter a valid email address';
        }

        $website_present = array_key_exists('website', $params);
        $website_value = null;
        if ($website_present && $params['website'] !== '') {
            $website_value = full_url_to_short_url($params['website']);
            if (!validate_short_url($website_value)) {
                $errors['website'] = 'Please enter a valid website URL';
            }
        }

        if (!empty($errors)) {
            return Rsx_Api::validation_error($errors);
        }

        // Apply only fields present in the request; omitted fields are untouched.
        if (array_key_exists('name', $params)) {
            $client->name = $params['name'];
        }
        if (array_key_exists('email', $params)) {
            $client->email = $params['email'];
        }
        if ($website_present) {
            $client->website = $website_value;
        }
        if (array_key_exists('phone', $params)) {
            $client->phone = $params['phone'];
        }
        if (array_key_exists('fax', $params)) {
            $client->fax = $params['fax'];
        }
        if (array_key_exists('address_street', $params)) {
            $client->address_street = $params['address_street'];
        }
        if (array_key_exists('city', $params)) {
            $client->city = $params['city'];
        }
        if (array_key_exists('state', $params)) {
            $client->state = $params['state'];
        }
        if (array_key_exists('zip', $params)) {
            $client->zip = $params['zip'];
        }
        if (array_key_exists('address_country', $params)) {
            $client->address_country = $params['address_country'];
        }
        if (array_key_exists('industry', $params)) {
            $client->industry = $params['industry'];
        }
        if (array_key_exists('company_size', $params)) {
            $client->company_size = $params['company_size'];
        }
        if (array_key_exists('established_year', $params)) {
            $client->established_year = (int) $params['established_year'];
        }
        if (array_key_exists('revenue_range', $params)) {
            $client->revenue_range = $params['revenue_range'];
        }
        if (array_key_exists('facebook_url', $params)) {
            $client->facebook_url = $params['facebook_url'];
        }
        if (array_key_exists('twitter_handle', $params)) {
            $client->twitter_handle = $params['twitter_handle'];
        }
        if (array_key_exists('linkedin_url', $params)) {
            $client->linkedin_url = $params['linkedin_url'];
        }
        if (array_key_exists('instagram_handle', $params)) {
            $client->instagram_handle = $params['instagram_handle'];
        }
        if (array_key_exists('preferred_contact_method', $params)) {
            $client->preferred_contact_method = $params['preferred_contact_method'];
        }
        if (array_key_exists('status_id', $params)) {
            $client->status_id = $params['status_id'];
        }
        if (array_key_exists('newsletter_opt_in', $params)) {
            $client->newsletter_opt_in = $params['newsletter_opt_in'] ? 1 : 0;
        }
        if (array_key_exists('notes', $params)) {
            $client->notes = $params['notes'];
        }

        $client->save();

        return $client;
    }

    /**
     * Delete a client.
     *
     * Soft delete (mirrors the staff feature); the record is retained but excluded
     * from every API read. Returns 204 No Content.
     *
     * @api-response
     * (empty body, HTTP 204)
     */
    #[Api_Endpoint('/api/v1/clients/:id/delete', methods: ['POST'])]
    #[Api_Param('id', type: 'int', required: true, description: 'Client ID')]
    public static function delete(Request $request, array $params = [])
    {
        $client = Client_Model::find($params['id']);

        if (!$client) {
            return Rsx_Api::not_found('Client not found');
        }

        $client->delete();

        return Rsx_Api::no_content();
    }

    // =====================================================================
    // ATTACHMENTS
    //
    // THE APP OWNS "ATTACH", THE FRAMEWORK OWNS THE BYTES. An integration uploads to
    // POST /api/v1/files (framework), gets back an unclaimed key, and then calls the
    // endpoint below to claim that key onto a record. Which record, which category and who
    // may do it are application policy, which is exactly why the framework does not ship
    // this endpoint - it cannot know the answer for your app.
    //
    // Files land in Client_Model::DOCUMENTS_CATEGORY, the SAME category the staff
    // Documents tab reads, so a document uploaded over the API shows up in the UI and
    // vice versa. That is the point of the example: one store, two surfaces.
    // =====================================================================

    /**
     * List a client's documents.
     *
     * Every document attached to this client, with the framework's file URLs. The URLs
     * accept the same `Authorization: Bearer` key this endpoint did, so a client can
     * download bytes without a browser session.
     *
     * @api-response
     * {
     *   "items": [
     *     {
     *       "attachment_id": 8,
     *       "key": "191dc2fb9c9eb2c503b0e294434efe009d14f314adb0ec29abc51f596a4764de",
     *       "file_name": "contract.pdf",
     *       "mime_type": "application/pdf",
     *       "size": 51200,
     *       "uploaded_at": "2026-08-27T15:30:00.000Z",
     *       "urls": {
     *         "download": "/_download/191dc2fb...",
     *         "inline": "/_inline/191dc2fb...",
     *         "thumbnail": "/_thumbnail/dynamic/191dc2fb.../fit/400",
     *         "preview": "/_preview/pdf/191dc2fb..."
     *       }
     *     }
     *   ],
     *   "meta": { "total": 1 }
     * }
     */
    #[Api_Endpoint('/api/v1/clients/:id/attachments', methods: ['GET'])]
    #[Api_Param('id', type: 'int', required: true, description: 'Client ID')]
    public static function attachments_list(Request $request, array $params = [])
    {
        $client = Client_Model::find($params['id']);

        if (!$client) {
            return Rsx_Api::not_found('Client not found');
        }

        // get_attachments() returns an Rsx_Result_Set - iterate it. It is not a Collection
        // and has no ->map(); the set is walked a page at a time because one record's
        // attachment count has no ceiling.
        $items = [];
        foreach ($client->get_attachments(Client_Model::DOCUMENTS_CATEGORY) as $attachment) {
            $items[] = static::__attachment_payload($attachment);
        }

        return [
            'items' => $items,
            'meta' => ['total' => count($items)],
        ];
    }

    /**
     * Attach an uploaded file to a client.
     *
     * Two-step, and the first step is the framework's: POST the bytes to
     * /api/v1/files, then pass the `key` it returned here. A key may be claimed ONCE -
     * claiming an already-attached key is refused.
     *
     * @api-response
     * {
     *   "attachment_id": 42,
     *   "key": "191dc2fb9c9eb2c503b0e294434efe009d14f314adb0ec29abc51f596a4764de",
     *   "file_name": "contract.pdf",
     *   "mime_type": "application/pdf",
     *   "size": 51200,
     *   "uploaded_at": "2026-08-27T15:30:00.000Z",
     *   "urls": { "download": "...", "inline": "...", "thumbnail": "...", "preview": "..." }
     * }
     */
    #[Api_Endpoint('/api/v1/clients/:id/attachments/attach', methods: ['POST'])]
    #[Api_Param('id', type: 'int', required: true, description: 'Client ID')]
    #[Api_Param('key', type: 'string', required: true, description: 'File key returned by POST /api/v1/files')]
    public static function attachments_attach(Request $request, array $params = [])
    {
        $client = Client_Model::find($params['id']);

        if (!$client) {
            return Rsx_Api::not_found('Client not found');
        }

        $attachment = File_Attachment_Model::find_by_key($params['key']);

        // can_user_assign_this_file() is STRUCTURAL: the file is still unclaimed and is in
        // this tenant. It is not a per-user permission check - that is this endpoint's job,
        // and here it is the class-level #[Auth] gate plus the site scope that found the
        // client. A missing key and an already-claimed one are ONE answer, so a caller
        // cannot probe for which keys exist.
        if (!$attachment || !$attachment->can_user_assign_this_file()) {
            return Rsx_Api::validation_error(
                ['key' => 'Not found, or already attached to something else'],
                'File not available to attach'
            );
        }

        // add_to(), not attach_to(): a client has MANY documents. attach_to() is the
        // single-file form and would detach whatever was already there.
        $attachment->add_to($client, Client_Model::DOCUMENTS_CATEGORY);

        return Rsx_Api::created(static::__attachment_payload($attachment));
    }

    /**
     * Remove a document from a client.
     *
     * Revokes every share of the document and soft-deletes it into the framework's
     * retention window - the bytes are not destroyed and the attachment stays
     * recoverable from the staff UI. Returns 204 No Content.
     *
     * @api-response
     * (empty body, HTTP 204)
     */
    #[Api_Endpoint('/api/v1/clients/:id/attachments/:attachment_id/delete', methods: ['POST'])]
    #[Api_Param('id', type: 'int', required: true, description: 'Client ID')]
    #[Api_Param('attachment_id', type: 'int', required: true, description: 'Attachment ID to remove')]
    public static function attachments_delete(Request $request, array $params = [])
    {
        $client = Client_Model::find($params['id']);

        if (!$client) {
            return Rsx_Api::not_found('Client not found');
        }

        // THE OWNERSHIP RE-VERIFICATION. find_attachment() returns null unless this
        // attachment is a document of THIS client - without it, any attachment id in the
        // tenant could be deleted through any client's URL.
        $attachment = $client->find_attachment($params['attachment_id'], Client_Model::DOCUMENTS_CATEGORY);

        if (!$attachment) {
            return Rsx_Api::not_found('Document not found for this client');
        }

        $client->remove_document($attachment);

        return Rsx_Api::no_content();
    }

    /**
     * One document's wire shape.
     *
     * Field names deliberately echo the framework's own file payload
     * (Files_Api_Controller), so an integration reads one vocabulary across both.
     *
     * @param File_Attachment_Model $attachment
     * @return array
     */
    private static function __attachment_payload($attachment): array
    {
        return [
            'attachment_id' => (int) $attachment->id,
            'key' => $attachment->key,
            'file_name' => $attachment->file_name,
            'mime_type' => $attachment->mime_type,
            'size' => $attachment->get_size(),
            'uploaded_at' => $attachment->created_at,
            'urls' => [
                'download' => $attachment->get_download_url(),
                'inline' => $attachment->get_url(),
                'thumbnail' => $attachment->get_thumbnail_url(),
                'preview' => '/_preview/pdf/' . $attachment->key,
            ],
        ];
    }
}
