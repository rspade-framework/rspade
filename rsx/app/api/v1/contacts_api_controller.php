<?php

namespace Rsx\App\Api\V1;

use Illuminate\Http\Request;
use App\RSpade\Core\Api\Rsx_Api;
use App\RSpade\Core\Api\Rsx_Api_Controller_Abstract;
use Rsx\Models\Contact_Model;

/**
 * Contacts API - external REST CRUD for contact records.
 *
 * All endpoints operate within the authenticated key's site (the global site scope
 * stamps and filters site_id automatically - there is no manual site_id handling).
 * Records serialize through the model's redacting toArray() (enum __label fields and
 * __MODEL included). Delete is a soft delete, mirroring the staff contacts feature.
 *
 * AUTH. Gated 'is_logged_in': the Bearer key establishes a headless staff identity,
 * and that identity plus the automatic site scope IS the authorization boundary for
 * v1. The 'can_use_api' gate (PERM_API_ACCESS) exists in rsx/permission.php and is
 * the natural tightening, but is deliberately NOT applied here - keys minted before
 * the permission existed belong to users who may not carry it, and adding it would
 * silently break live keys.
 */
#[Auth('is_logged_in')]
class Contacts_Api_Controller extends Rsx_Api_Controller_Abstract
{
    /**
     * List contacts.
     *
     * Returns a paginated list of contacts for the current site, newest name first.
     * Optional case-insensitive search matches first name, last name or email.
     *
     * @api-response
     * {
     *   "items": [
     *     {
     *       "id": 1,
     *       "site_id": 1,
     *       "client_id": 3,
     *       "first_name": "Ada",
     *       "last_name": "Lovelace",
     *       "email": "ada@example.com",
     *       "priority": 2,
     *       "priority__label": "Medium",
     *       "is_active": true,
     *       "__MODEL": "Contact_Model"
     *     }
     *   ],
     *   "meta": { "page": 1, "per_page": 20, "total": 1, "total_pages": 1 }
     * }
     */
    #[Api_Endpoint('/api/v1/contacts', methods: ['GET'])]
    #[Api_Param('page', type: 'int', default: 1, description: 'Page number (1-based)')]
    #[Api_Param('per_page', type: 'int', default: 20, description: 'Results per page (max 100)')]
    #[Api_Param('search', type: 'string', description: 'Search by first name, last name or email')]
    public static function list(Request $request, array $params = [])
    {
        $page = max(1, (int) $params['page']);
        $per_page = min(100, max(1, (int) $params['per_page']));
        $search = $params['search'] ?? null;

        $query = Contact_Model::query();

        if ($search !== null && $search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('first_name', 'like', "%{$search}%")
                  ->orWhere('last_name', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%");
            });
        }

        $total = $query->count();
        $contacts = $query->orderBy('first_name')
            ->skip(($page - 1) * $per_page)
            ->take($per_page)
            ->get();

        return [
            'items' => $contacts,
            'meta' => [
                'page' => $page,
                'per_page' => $per_page,
                'total' => $total,
                'total_pages' => (int) ceil($total / $per_page),
            ],
        ];
    }

    /**
     * Get a single contact.
     *
     * Returns the full contact record. A soft-deleted or cross-site id is not found.
     *
     * @api-response
     * {
     *   "id": 1,
     *   "site_id": 1,
     *   "client_id": 3,
     *   "first_name": "Ada",
     *   "last_name": "Lovelace",
     *   "title": "Engineer",
     *   "email": "ada@example.com",
     *   "phone_work": "(555) 111-2222",
     *   "priority": 2,
     *   "priority__label": "Medium",
     *   "is_active": true,
     *   "__MODEL": "Contact_Model"
     * }
     */
    #[Api_Endpoint('/api/v1/contacts/:id', methods: ['GET'])]
    #[Api_Param('id', type: 'int', required: true, description: 'Contact ID')]
    public static function get(Request $request, array $params = [])
    {
        $contact = Contact_Model::find($params['id']);

        if (!$contact) {
            return Rsx_Api::not_found('Contact not found');
        }

        return $contact;
    }

    /**
     * Create a contact.
     *
     * Requires first_name, last_name, client_id and a valid email (mirrors the staff
     * save form). site_id is stamped automatically from the authenticated key.
     *
     * @api-response
     * {
     *   "id": 42,
     *   "site_id": 1,
     *   "client_id": 3,
     *   "first_name": "Grace",
     *   "last_name": "Hopper",
     *   "email": "grace@example.com",
     *   "priority": 2,
     *   "priority__label": "Medium",
     *   "is_active": true,
     *   "__MODEL": "Contact_Model"
     * }
     */
    #[Api_Endpoint('/api/v1/contacts/create', methods: ['POST'])]
    #[Api_Param('first_name', type: 'string', required: true, description: 'First name')]
    #[Api_Param('last_name', type: 'string', required: true, description: 'Last name')]
    #[Api_Param('client_id', type: 'int', required: true, description: 'Owning client ID')]
    #[Api_Param('email', type: 'string', required: true, description: 'Primary email address')]
    #[Api_Param('title', type: 'string', description: 'Job title')]
    #[Api_Param('email_secondary', type: 'string', description: 'Secondary email address')]
    #[Api_Param('phone_work', type: 'string', description: 'Work phone')]
    #[Api_Param('phone_cell', type: 'string', description: 'Cell phone')]
    #[Api_Param('phone_other', type: 'string', description: 'Other phone')]
    #[Api_Param('address', type: 'string', description: 'Street address')]
    #[Api_Param('city', type: 'string', description: 'City')]
    #[Api_Param('state', type: 'string', description: 'State')]
    #[Api_Param('zip', type: 'string', description: 'ZIP / postal code')]
    #[Api_Param('notes', type: 'string', description: 'Internal notes')]
    #[Api_Param('is_active', type: 'bool', default: true, description: 'Whether the contact is active')]
    #[Api_Param('priority', type: 'int', default: 2, description: 'Priority (1=Low..4=Urgent; 2=Medium)')]
    public static function create(Request $request, array $params = [])
    {
        if (!filter_var($params['email'], FILTER_VALIDATE_EMAIL)) {
            return Rsx_Api::validation_error(['email' => 'Please enter a valid email address']);
        }

        $contact = new Contact_Model();

        // Explicit field assignment (no mass assignment). site_id is stamped by the
        // site-scoped model on save.
        $contact->first_name = $params['first_name'];
        $contact->last_name = $params['last_name'];
        $contact->client_id = $params['client_id'];
        $contact->email = $params['email'];
        $contact->title = $params['title'] ?? null;
        $contact->email_secondary = $params['email_secondary'] ?? null;
        $contact->phone_work = $params['phone_work'] ?? null;
        $contact->phone_cell = $params['phone_cell'] ?? null;
        $contact->phone_other = $params['phone_other'] ?? null;
        $contact->address = $params['address'] ?? null;
        $contact->city = $params['city'] ?? null;
        $contact->state = $params['state'] ?? null;
        $contact->zip = $params['zip'] ?? null;
        $contact->notes = $params['notes'] ?? null;
        $contact->is_active = $params['is_active'] ? 1 : 0;
        $contact->priority = $params['priority'];

        $contact->save();

        return Rsx_Api::created($contact);
    }

    /**
     * Update a contact.
     *
     * Applies only the fields present in the request; omitted fields are left
     * unchanged. A present required field (first_name, last_name, client_id, email)
     * must be non-empty, and email must be valid.
     *
     * @api-response
     * {
     *   "id": 42,
     *   "site_id": 1,
     *   "client_id": 3,
     *   "first_name": "Grace",
     *   "last_name": "Hopper",
     *   "email": "grace@navy.example.com",
     *   "priority": 3,
     *   "priority__label": "High",
     *   "is_active": true,
     *   "__MODEL": "Contact_Model"
     * }
     */
    #[Api_Endpoint('/api/v1/contacts/:id/update', methods: ['POST'])]
    #[Api_Param('id', type: 'int', required: true, description: 'Contact ID')]
    #[Api_Param('first_name', type: 'string', description: 'First name')]
    #[Api_Param('last_name', type: 'string', description: 'Last name')]
    #[Api_Param('client_id', type: 'int', description: 'Owning client ID')]
    #[Api_Param('email', type: 'string', description: 'Primary email address')]
    #[Api_Param('title', type: 'string', description: 'Job title')]
    #[Api_Param('email_secondary', type: 'string', description: 'Secondary email address')]
    #[Api_Param('phone_work', type: 'string', description: 'Work phone')]
    #[Api_Param('phone_cell', type: 'string', description: 'Cell phone')]
    #[Api_Param('phone_other', type: 'string', description: 'Other phone')]
    #[Api_Param('address', type: 'string', description: 'Street address')]
    #[Api_Param('city', type: 'string', description: 'City')]
    #[Api_Param('state', type: 'string', description: 'State')]
    #[Api_Param('zip', type: 'string', description: 'ZIP / postal code')]
    #[Api_Param('notes', type: 'string', description: 'Internal notes')]
    #[Api_Param('is_active', type: 'bool', description: 'Whether the contact is active')]
    #[Api_Param('priority', type: 'int', description: 'Priority (1=Low..4=Urgent)')]
    public static function update(Request $request, array $params = [])
    {
        $contact = Contact_Model::find($params['id']);

        if (!$contact) {
            return Rsx_Api::not_found('Contact not found');
        }

        // Validate present required-on-model fields (a blank would break the record).
        $errors = [];
        if (array_key_exists('first_name', $params) && trim($params['first_name']) === '') {
            $errors['first_name'] = 'First name is required';
        }
        if (array_key_exists('last_name', $params) && trim($params['last_name']) === '') {
            $errors['last_name'] = 'Last name is required';
        }
        if (array_key_exists('email', $params)) {
            if (trim($params['email']) === '') {
                $errors['email'] = 'Email address is required';
            } elseif (!filter_var($params['email'], FILTER_VALIDATE_EMAIL)) {
                $errors['email'] = 'Please enter a valid email address';
            }
        }
        if (!empty($errors)) {
            return Rsx_Api::validation_error($errors);
        }

        // Apply only fields present in the request; omitted fields are untouched.
        if (array_key_exists('first_name', $params)) {
            $contact->first_name = $params['first_name'];
        }
        if (array_key_exists('last_name', $params)) {
            $contact->last_name = $params['last_name'];
        }
        if (array_key_exists('client_id', $params)) {
            $contact->client_id = $params['client_id'];
        }
        if (array_key_exists('email', $params)) {
            $contact->email = $params['email'];
        }
        if (array_key_exists('title', $params)) {
            $contact->title = $params['title'];
        }
        if (array_key_exists('email_secondary', $params)) {
            $contact->email_secondary = $params['email_secondary'];
        }
        if (array_key_exists('phone_work', $params)) {
            $contact->phone_work = $params['phone_work'];
        }
        if (array_key_exists('phone_cell', $params)) {
            $contact->phone_cell = $params['phone_cell'];
        }
        if (array_key_exists('phone_other', $params)) {
            $contact->phone_other = $params['phone_other'];
        }
        if (array_key_exists('address', $params)) {
            $contact->address = $params['address'];
        }
        if (array_key_exists('city', $params)) {
            $contact->city = $params['city'];
        }
        if (array_key_exists('state', $params)) {
            $contact->state = $params['state'];
        }
        if (array_key_exists('zip', $params)) {
            $contact->zip = $params['zip'];
        }
        if (array_key_exists('notes', $params)) {
            $contact->notes = $params['notes'];
        }
        if (array_key_exists('is_active', $params)) {
            $contact->is_active = $params['is_active'] ? 1 : 0;
        }
        if (array_key_exists('priority', $params)) {
            $contact->priority = $params['priority'];
        }

        $contact->save();

        return $contact;
    }

    /**
     * Delete a contact.
     *
     * Soft delete (mirrors the staff feature); the record is retained but excluded
     * from every API read. Returns 204 No Content.
     *
     * @api-response
     * (empty body, HTTP 204)
     */
    #[Api_Endpoint('/api/v1/contacts/:id/delete', methods: ['POST'])]
    #[Api_Param('id', type: 'int', required: true, description: 'Contact ID')]
    public static function delete(Request $request, array $params = [])
    {
        $contact = Contact_Model::find($params['id']);

        if (!$contact) {
            return Rsx_Api::not_found('Contact not found');
        }

        $contact->delete();

        return Rsx_Api::no_content();
    }
}
