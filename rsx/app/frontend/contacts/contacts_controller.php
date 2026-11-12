<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace Rsx\App\Frontend\Contacts;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use libphonenumber\NumberParseException;
use libphonenumber\PhoneNumberFormat;
use libphonenumber\PhoneNumberUtil;
use App\RSpade\Core\Ajax\Ajax;
use App\RSpade\Core\Controller\Rsx_Controller_Abstract;
use App\RSpade\Core\Forms\Form_Edit;
use App\RSpade\Core\Forms\Form_Questions;
use App\RSpade\Core\Response\Error_Response;
use App\RSpade\Core\Rsx;
use App\RSpade\Core\Session\Session;
use App\RSpade\Core\Task\Task;
use App\RSpade\Core\Time\Rsx_Date;
use App\RSpade\Lib\Portal\Portal_Session;
use App\RSpade\Lib\Ui\Flash\Flash_Alert;
use Rsx\App\Frontend\Contacts\List\Contacts_DataGrid;
use Rsx\Lib\ActionLog\Action_Log;
use Rsx\Models\Action_Log_Model;
use Rsx\Models\Client_Model;
use Rsx\Models\Contact_Model;
use Rsx\Models\Project_Contact_Model;
use Rsx\Models\Project_Model;

/**
 */
#[Auth('is_logged_in', 'can_view_data')]
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
     *
     * The action log is for administrators: the same can_view_user_activity check the
     * Action Log screen carries, so a per-record feed never shows what that screen refuses.
     */
    #[Auth('can_view_user_activity')]
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
     * Page route: "View as Client" - begin a read-only client-portal impersonation of the
     * contact's portal user, then send the browser there.
     *
     * The contact page's View as Client control is an ordinary <a target="_blank"> to this
     * URL, so the new tab is opened by the user's own click (no popup blocker, no blank tab
     * opened ahead of an Ajax answer) and this route moves it on with a 302: to the portal
     * itself when it is served under a prefix on this host, or to the first leg of the
     * linked-session handshake when it has its own host
     * (Portal_Session::begin_impersonation_from_staff()). A refusal renders in that tab:
     * no such contact (404), no portal account or no client portal on the contact's site
     * (400, naming which), a caller the framework's own can_impersonate re-check refuses
     * (403 - it throws AjaxUnauthorizedException, which a page route renders as the
     * unauthorized page). Read-only is enforced by the framework: while impersonating, a
     * portal Ajax endpoint runs only if it is marked #[Portal_Impersonation_Readable].
     *
     * A GET that DOES something, so it answers only a navigation started on this site
     * ($request->is_same_origin_navigation()): another site linking a signed-in staff member
     * here must not start an impersonation behind their back.
     *
     * Declares no site lock: it writes only the browser's own session rows (framework
     * tables, not site-scoped data), and the site-scoped reads are of the contact and its
     * portal user.
     *
     * @param Request $request
     * @param array $params
     * @return mixed
     */
    #[Route('/contacts/view/:id/view-as-client', methods: ['GET'])]
    #[Auth('can_impersonate')]
    public static function view_as_client(Request $request, array $params = [])
    {
        if (!$request->is_same_origin_navigation()) {
            return response_unauthorized('Open View as Client from the contact page.');
        }

        $contact = !empty($params['id']) ? Contact_Model::find($params['id']) : null;
        if (!$contact) {
            return response_error(Ajax::ERROR_NOT_FOUND, 'Contact not found');
        }

        $portal_user = $contact->resolve_portal_user();
        if (!$portal_user) {
            return response_error(Ajax::ERROR_VALIDATION, 'This contact has no client portal account.');
        }

        // get_user() is guaranteed non-null here (the can_impersonate gate implies a
        // signed-in user).
        $url = Portal_Session::begin_impersonation_from_staff(
            $portal_user->id,
            Session::get_user()->id,
            (int) $contact->site_id
        );

        if ($url === false) {
            return response_error(Ajax::ERROR_VALIDATION, 'This site has no client portal.');
        }

        // Exactly the URL the framework built: redirect() would normalize it (trim the
        // portal root's trailing slash).
        return new RedirectResponse($url);
    }

    /**
     * Ajax endpoint: Save contact (add or edit)
     *
     * The app's worked example of a SERVER-DRIVEN QUESTION: on create, an email another
     * contact already holds is a decision for the user, not a validation error, so the
     * endpoint asks it with response_form_question() and writes nothing until it is
     * answered. Order is validate -> ask -> write; see rsx:man form_conventions
     * (QUESTIONS) and the handler in rsx/lib/modal/modal.js.
     *
     * @param Request $request
     * @param array $params
     * @return mixed
     */
    #[Auth('can_edit_data')]
    #[Writer]
    #[Ajax_Endpoint]
    public static function save(Request $request, array $params = [])
    {
        // Validate required fields
        $errors = [];

        if (!isset($params['first_name']) || !is_string($params['first_name']) || trim($params['first_name']) === '') {
            $errors['first_name'] = 'First name is required';
        }

        if (!isset($params['last_name']) || !is_string($params['last_name']) || trim($params['last_name']) === '') {
            $errors['last_name'] = 'Last name is required';
        }

        if (empty($params['client_id'])) {
            $errors['client_id'] = 'Client is required';
        } elseif (!Client_Model::find((int) $params['client_id'])) {
            // The site-scoped find is the tenant check: an id from another site misses.
            $errors['client_id'] = 'The selected client does not exist';
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

        // Lengths (phones in their stored E.164 form), the secondary email and the priority.
        $errors += Contact_Model::value_errors(array_merge($params, $phones));

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
            // VALIDATE -> ASK -> WRITE. Validation has passed and nothing has been written
            // yet; this is where a question belongs. Whether the address is already in use
            // is server knowledge, so the question originates here rather than being
            // re-implemented in JavaScript, where it would drift from this query.
            //
            // The endpoint re-runs from scratch on the resubmission, so no state is held
            // between rounds: if the duplicate is still there, it is simply asked again.
            // contacts.email is utf8mb4_unicode_ci, so the comparison is already
            // case-insensitive and (site_id, email) serves it directly.
            $existing = Contact_Model::query()
                ->where('email', trim($params['email']))
                ->orderBy('id')
                ->first();

            if ($existing) {
                $answer = Form_Questions::answer($params, 'duplicate_email');

                if ($answer === null) {
                    $client_name = $existing->client ? $existing->client->name : 'no client';

                    return response_form_question('duplicate_email', [
                        'kind' => 'confirm',
                        'title' => 'A contact with this email already exists',
                        'body' => "{$existing->first_name} {$existing->last_name} ({$client_name}) already uses {$existing->email}.\n\nCreate a second contact with the same email?",
                        'confirm_label' => 'Create anyway',
                        'cancel_label' => 'Open the existing contact',
                    ]);
                }

                // "No" is an ANSWER, not a cancel: the user chose the existing record.
                // Nothing is written; the form's success path follows the redirect.
                if ($answer === false) {
                    return [
                        'contact_id' => $existing->id,
                        'redirect' => Rsx::Route('Contacts_View_Action', $existing->id),
                    ];
                }
            }

            // Create new contact (site_id is stamped by the site-scoped model on save)
            $contact = new Contact_Model();
        }

        return Form_Edit::run($contact, $params, function (Form_Edit $edit) use ($contact, $contact_id, $params, $phones) {
            // Set all fields explicitly
            $edit->set('first_name', $params['first_name']);
            $edit->set('last_name', $params['last_name']);
            $edit->set('title', $params['title'] ?? null);
            $edit->set('email', $params['email'] ?? null);
            $edit->set('email_secondary', $params['email_secondary'] ?? null);
            $edit->set('phone_work', $phones['phone_work']);
            $edit->set('phone_cell', $phones['phone_cell']);
            $edit->set('phone_other', $phones['phone_other']);
            $edit->set('address', $params['address'] ?? null);
            $edit->set('city', $params['city'] ?? null);
            $edit->set('state', $params['state'] ?? null);
            $edit->set('zip', $params['zip'] ?? null);
            $edit->set('client_id', (int)$params['client_id']);
            // A new contact is active unless the submission says otherwise; on an edit an
            // absent key is never in _changed, so it is left as stored.
            $edit->set('is_active', array_key_exists('is_active', $params) ? (!empty($params['is_active']) ? 1 : 0) : 1);
            $edit->set('priority', !empty($params['priority']) ? (int)$params['priority'] : Contact_Model::PRIORITY_MEDIUM);
            $edit->set('notes', $params['notes'] ?? null);

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
            //     // With entity reference (hidden, then purged, if the contact is deleted later)
            //     Notification::send(
            //         Notification_Model::TYPE_CONTACT_CREATED,
            //         $notify_user_ids,
            //         $contact
            //     );
            //
            //     // Or without entity (metadata only, outlives the contact)
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
        }, $contact->exists ? Rsx::Route('Contacts_View_Action', $contact->id) : null);
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
     * @param mixed $value Raw submitted value - a request may carry any shape, and one that
     *                     is not a string or a number is a field error, never a TypeError
     * @param array $errors Error map, appended to by reference when the number is unusable
     * @param string $field Field name the error is reported against
     * @return string|null E.164 number, or null when the field was submitted blank
     */
    private static function _normalize_phone(mixed $value, array &$errors, string $field): ?string
    {
        if ($value !== null && !is_string($value) && !is_int($value)) {
            $errors[$field] = 'Please enter a valid phone number';
            return null;
        }

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
     * complete.
     *
     * A MASS EDIT: this validates the selection, dispatches Mass_Edit_Service::delete_contacts and
     * returns the run's id; the grid shows the run's progress and its summary. The task takes
     * one write section per contact, re-checking it is still selected, and deletes it through the
     * model layer with its action-log entry - a raw bulk DELETE would skip the soft delete, the
     * audit stamp, the realtime frame and the action log.
     *
     * Gate: can_edit_data, as on save() - the controller's other mutating endpoint.
     *
     * @param Request $request
     * @param array $params
     * @return mixed
     */
    #[Auth('can_edit_data')]
    #[Writer]
    #[Ajax_Endpoint]
    public static function bulk_delete(Request $request, array $params = [])
    {
        $query = Contacts_DataGrid::build_query_public($params['filter_params'] ?? []);

        $query = Contacts_DataGrid::apply_selection($query, 'contacts.id', $params);

        if ($query instanceof Error_Response) {
            return $query;
        }

        // A mass edit never holds the write lock across its selection: the task deletes one
        // record per write section, as the person who clicked (rsx/services/mass_edit_service.php).
        $task_id = Task::dispatch('Mass_Edit_Service', 'delete_contacts', [
            'mode' => $params['mode'],
            'ids' => $params['ids'] ?? [],
            'filter_params' => $params['filter_params'] ?? [],
        ]);

        return [
            'task_id' => $task_id,
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
