<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace Rsx\App\Frontend\Contacts;

use Illuminate\Http\Request;
use libphonenumber\NumberParseException;
use libphonenumber\PhoneNumberFormat;
use libphonenumber\PhoneNumberUtil;
use App\RSpade\Core\Ajax\Ajax;
use App\RSpade\Core\Controller\Rsx_Controller_Abstract;
use App\RSpade\Core\Portal\Portal_Session;
use App\RSpade\Core\Portal\Rsx_Portal;
use App\RSpade\Core\Response\Error_Response;
use App\RSpade\Core\Rsx;
use App\RSpade\Core\Session\Session;
use App\RSpade\Core\Time\Rsx_Date;
use App\RSpade\Lib\Flash\Flash_Alert;
use Rsx\App\Frontend\Contacts\List\Contacts_DataGrid;
use Rsx\Lib\ActionLog\Action_Log;
use Rsx\Models\Action_Log_Model;
use Rsx\Models\Client_Model;
use Rsx\Models\Contact_Model;
use Rsx\Models\Project_Contact_Model;
use Rsx\Models\Project_Model;

/**
 */
#[Auth('is_logged_in')]
class Frontend_Contacts_Controller extends Rsx_Controller_Abstract
{
    /**
     * Ajax endpoint: Fetch DataGrid data
     *
     * @param Request $request
     * @param array $params
     * @return array
     */
    #[Ajax_Endpoint]
    public static function datagrid_fetch(Request $request, array $params = [])
    {
        // Call static fetch method on DataGrid
        return Contacts_DataGrid::fetch($params);
    }

    /**
     * Ajax endpoint: Get clients list for dropdown
     *
     * @param Request $request
     * @param array $params
     * @return array
     */
    #[Ajax_Endpoint]
    public static function get_clients(Request $request, array $params = [])
    {
        $clients = Client_Model::query()
            ->orderBy('name', 'asc')
            ->get();

        return $clients->map(function ($client) {
            return [
                'value' => $client->id,
                'label' => $client->name,
            ];
        })->toArray();
    }

    /**
     * Ajax endpoint: Get single client by ID
     *
     * @param Request $request
     * @param array $params
     * @return mixed
     */
    #[Ajax_Endpoint]
    public static function get_client(Request $request, array $params = [])
    {
        $client_id = $params['client_id'] ?? null;

        if (!$client_id) {
            return response_error(Ajax::ERROR_VALIDATION, ['message' => 'Client ID is required']);
        }

        $client = Client_Model::find($client_id);

        if (!$client) {
            return response_error(Ajax::ERROR_NOT_FOUND, 'Client not found');
        }

        return [
            'id' => $client->id,
            'name' => $client->name,
        ];
    }

    /**
     * Ajax endpoint: Get projects for a contact.
     *
     * Projects this contact is associated with (via the project_contacts pivot - projects
     * gained 1-to-many contacts, replacing the single contact_id column). Same row shape
     * as Frontend_Clients_Controller::client_projects.
     */
    #[Ajax_Endpoint]
    public static function contact_projects(Request $request, array $params = [])
    {
        $contact_id = $params['id'] ?? null;
        if (!$contact_id) {
            return response_error(Ajax::ERROR_VALIDATION, 'Contact ID is required');
        }

        $project_ids = Project_Contact_Model::project_ids_for_contact((int) $contact_id);

        $projects = $project_ids
            ? Project_Model::whereIn('id', $project_ids)->orderBy('name')->get()
            : collect();

        $result = [];
        foreach ($projects as $project) {
            $result[] = [
                'id' => $project->id,
                'name' => $project->name,
                'status' => $project->status,
                'status__label' => $project->status__label,
                'status__badge' => $project->status__badge,
                'priority' => $project->priority,
                'priority__label' => $project->priority__label,
                'priority__badge' => $project->priority__badge,
                'due_date' => $project->due_date,
            ];
        }

        return ['projects' => $result];
    }

    /**
     * Ajax endpoint: Get the activity feed (action log) for a contact.
     *
     * One shared shape across all entity Activity tabs (Dashboard's recent-activity
     * items): id, rendered summary html, created_at, type_id. render() already emits
     * the linked "actor verb object" summary.
     */
    #[Ajax_Endpoint]
    public static function contact_activity(Request $request, array $params = [])
    {
        $contact_id = $params['id'] ?? null;
        if (!$contact_id) {
            return response_error(Ajax::ERROR_VALIDATION, 'Contact ID is required');
        }

        $contact = Contact_Model::withTrashed()->find($contact_id);
        if (!$contact) {
            return response_error(Ajax::ERROR_NOT_FOUND, 'Contact not found');
        }

        $activity = [];
        foreach (Action_Log::get_for_entity($contact, 50) as $log) {
            $activity[] = [
                'id' => $log->id,
                'html' => $log->render(),
                'created_at' => $log->created_at,
                'type_id' => $log->type_id,
            ];
        }

        return ['activity' => $activity];
    }

    /**
     * Ajax endpoint: Begin a client-portal impersonation ("View as Client").
     *
     * Creates a throwaway, read-only-intended portal session for the contact's
     * linked portal user (stamped with the current staff user as impersonator) and
     * returns a single-use claim URL for the caller to open in a new tab. The
     * read-only experience itself is enforced portal-side (see portal_main.php).
     *
     * @param Request $request
     * @param array $params
     * @return mixed
     */
    #[Ajax_Endpoint]
    #[Auth('can_impersonate')]
    public static function begin_portal_impersonation(Request $request, array $params = [])
    {
        $contact = !empty($params['id']) ? Contact_Model::find($params['id']) : null;
        if (!$contact) {
            return response_error(Ajax::ERROR_NOT_FOUND, 'Contact not found');
        }

        $portal_user = $contact->resolve_portal_user();
        if (!$portal_user) {
            return response_error(Ajax::ERROR_VALIDATION, 'This contact has no client portal account.');
        }

        // get_user() is guaranteed non-null here (the can_impersonate gate implies
        // a logged-in user); use its id rather than get_user_id(), which is null
        // when there is no resolved site context (e.g. CLI/rsx:ajax).
        $handoff = Portal_Session::create_impersonation_session(
            $portal_user->id,
            Session::get_user()->id,
            (int) $contact->site_id
        );

        return [
            'url' => Rsx_Portal::Route('Portal_Impersonate_Controller::claim', ['t' => $handoff]),
        ];
    }

    /**
     * Ajax endpoint: Save contact (add or edit)
     *
     * @param Request $request
     * @param array $params
     * @return mixed
     */
    #[Ajax_Endpoint]
    public static function save(Request $request, array $params = [])
    {
        // Validate required fields
        $errors = [];

        if (empty($params['first_name'])) {
            $errors['first_name'] = 'First name is required';
        }

        if (empty($params['last_name'])) {
            $errors['last_name'] = 'Last name is required';
        }

        if (empty($params['client_id'])) {
            $errors['client_id'] = 'Client is required';
        }

        if (empty($params['email'])) {
            $errors['email'] = 'Email address is required';
        } elseif (!filter_var($params['email'], FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'Please enter a valid email address';
        }

        // Phone numbers: validated and normalised to E.164 here, on the server, because a
        // browser widget formats but never decides. Each field is independent - one bad
        // number reports on its own field and does not stop the others being accepted.
        $phones = [];

        foreach (['phone_work', 'phone_cell', 'phone_other'] as $phone_field) {
            $phones[$phone_field] = self::_normalize_phone($params[$phone_field] ?? null, $errors, $phone_field);
        }

        if (!empty($errors)) {
            return response_form_error('Please correct the errors below.', $errors);
        }

        // Determine if this is create or update
        $contact_id = $params['id'] ?? null;

        if ($contact_id) {
            // Update existing contact
            $contact = Contact_Model::find($contact_id);
            if (!$contact) {
                return response_error(Ajax::ERROR_NOT_FOUND, 'Contact not found');
            }
        } else {
            // Create new contact
            $contact = new Contact_Model();
            $contact->site_id = 1; // Default site
        }

        // Set all fields explicitly
        $contact->first_name = $params['first_name'];
        $contact->last_name = $params['last_name'];
        $contact->title = $params['title'] ?? null;
        $contact->email = $params['email'] ?? null;
        $contact->email_secondary = $params['email_secondary'] ?? null;
        $contact->phone_work = $phones['phone_work'];
        $contact->phone_cell = $phones['phone_cell'];
        $contact->phone_other = $phones['phone_other'];
        $contact->address = $params['address'] ?? null;
        $contact->city = $params['city'] ?? null;
        $contact->state = $params['state'] ?? null;
        $contact->zip = $params['zip'] ?? null;
        $contact->client_id = (int)$params['client_id'];
        $contact->is_active = !empty($params['is_active']) ? 1 : 0;
        $contact->priority = !empty($params['priority']) ? (int)$params['priority'] : 2;
        $contact->notes = $params['notes'] ?? null;

        $contact->save();

        // Record action log
        Action_Log::record(
            $contact_id ? Action_Log_Model::TYPE_CONTACT_UPDATED : Action_Log_Model::TYPE_CONTACT_CREATED,
            $contact
        );

        // Example: Send notification to other users when contact created
        // if (!$contact_id) {
        //     // Get user IDs to notify (e.g., admins, team members)
        //     $notify_user_ids = [/* array of login_user IDs */];
        //
        //     // With entity reference (self-polices if contact deleted later)
        //     Notification::send(
        //         Notification_Model::TYPE_CONTACT_CREATED,
        //         $notify_user_ids,
        //         $contact
        //     );
        //
        //     // Or without entity (metadata only, won't self-police)
        //     // Notification::send(
        //     //     Notification_Model::TYPE_CONTACT_CREATED,
        //     //     $notify_user_ids,
        //     //     null,
        //     //     ['contact_name' => $contact->first_name . ' ' . $contact->last_name]
        //     // );
        // }

        // Flash success message for display after redirect
        Flash_Alert::success($contact_id ? 'Contact updated successfully' : 'Contact created successfully');

        return [
            'contact_id' => $contact->id,
            'redirect' => Rsx::Route('Contacts_View_Action', $contact->id),
        ];
    }

    /**
     * Validate one submitted phone number and return the value to store.
     *
     * Phone numbers are stored in E.164 ("+19206145140") - one canonical spelling, so two
     * records holding the same number compare equal, a search matches whatever the user
     * typed, and an SMS gateway gets the form it requires. The display formatting is
     * Rsx\Lib\Formatters::phone()'s job on the way out; nothing but E.164 goes in.
     *
     * A BLANK value is a value: the user clearing the field means "this contact has no work
     * number", so it stores null rather than being treated as "not submitted". That is the
     * form contract - a form submits every field it renders.
     *
     * The bar is libphonenumber's isPossibleNumber(), not isValidNumber(), matching
     * Formatters::phone(): a length/pattern check that accepts a number in an unassigned or
     * placeholder range. A CRM records the number on the business card; deciding that a
     * carrier has not issued that block yet is not a data-entry error.
     *
     * A number typed without a '+' is understood to belong to config('rsx.formatting.phone_region').
     *
     * @param string|null $value Raw submitted value
     * @param array $errors Error map, appended to by reference when the number is unusable
     * @param string $field Field name the error is reported against
     * @return string|null E.164 number, or null when the field was submitted blank
     */
    private static function _normalize_phone(?string $value, array &$errors, string $field): ?string
    {
        $value = trim((string)$value);

        if ($value === '') {
            return null;
        }

        $region = config('rsx.formatting.phone_region');

        try {
            // A '+' number carries its own country code and ignores the region; anything
            // else is read as a number dialled inside the region.
            $proto = PhoneNumberUtil::getInstance()->parse($value, $region);
        } catch (NumberParseException $e) {
            // Expected: this is user input, and unparseable input is exactly what this
            // function exists to report.
            $errors[$field] = 'Please enter a valid phone number';
            return null;
        }

        if (!PhoneNumberUtil::getInstance()->isPossibleNumber($proto)) {
            $errors[$field] = 'Please enter a valid phone number';
            return null;
        }

        return PhoneNumberUtil::getInstance()->format($proto, PhoneNumberFormat::E164);
    }

    /**
     * Ajax endpoint: bulk-delete the contacts a datagrid footer action selected.
     *
     * The selection payload names a SET, not a page: additive ids, subtractive exclusions, or
     * the whole filtered result. The filters ride along in filter_params so the server rebuilds
     * the exact query the user was looking at rather than trusting a client-side id list to be
     * complete. Every row goes through the model layer one at a time - a raw bulk DELETE would
     * skip the soft delete, the audit stamp, the realtime frame and the action log.
     *
     * Gate: the class-level 'is_logged_in'. Contacts have no single-record delete endpoint to
     * copy a gate from, so this matches save() - the controller's other mutating endpoint.
     *
     * @param Request $request
     * @param array $params
     * @return mixed
     */
    #[Ajax_Endpoint]
    public static function bulk_delete(Request $request, array $params = [])
    {
        $query = Contacts_DataGrid::build_query_public($params['filter_params'] ?? []);

        $query = Contacts_DataGrid::apply_selection($query, 'contacts.id', $params);

        if ($query instanceof Error_Response) {
            return $query;
        }

        $deleted = 0;

        foreach (Contacts_DataGrid::iterate_selection($query, 'contacts.id') as $contact) {
            Action_Log::record(Action_Log_Model::TYPE_CONTACT_DELETED, $contact);
            $contact->delete();
            $deleted++;
        }

        return [
            'deleted' => $deleted,
        ];
    }

    /**
     * Ajax endpoint: export the selected contacts as CSV.
     *
     * Same selection resolution as bulk_delete(). Returns the CSV as a string; the browser
     * turns it into a download, because an Ajax response cannot be one.
     *
     * @param Request $request
     * @param array $params
     * @return mixed
     */
    #[Auth('can_export_data')]
    #[Ajax_Endpoint]
    public static function export_csv(Request $request, array $params = [])
    {
        $query = Contacts_DataGrid::build_query_public($params['filter_params'] ?? []);

        $query = Contacts_DataGrid::apply_selection($query, 'contacts.id', $params);

        if ($query instanceof Error_Response) {
            return $query;
        }

        $rows = [];

        foreach (Contacts_DataGrid::iterate_selection($query, 'contacts.id') as $contact) {
            $rows[] = [
                $contact->id,
                $contact->first_name,
                $contact->last_name,
                $contact->title,
                $contact->company_name,
                $contact->email,
                $contact->phone_work,
                $contact->phone_cell,
                $contact->city,
                $contact->state,
                $contact->priority__label,
                $contact->is_active ? 'Yes' : 'No',
                $contact->created_at,
            ];
        }

        $csv = Contacts_DataGrid::build_csv(
            [
                'ID', 'First Name', 'Last Name', 'Title', 'Company', 'Email', 'Work Phone',
                'Cell Phone', 'City', 'State', 'Priority', 'Active', 'Created',
            ],
            $rows
        );

        return [
            'csv' => $csv,
            'filename' => 'contacts_export_' . Rsx_Date::today() . '.csv',
            'count' => count($rows),
        ];
    }
}
