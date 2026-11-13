<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace Rsx\Tests;

use Illuminate\Http\Request;
use App\RSpade\Core\Response\Error_Response;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;
use Rsx\App\Frontend\Clients\Frontend_Clients_Controller;
use Rsx\App\Frontend\Contacts\Frontend_Contacts_Controller;
use Rsx\App\Frontend\Projects\Frontend_Projects_Controller;
use Rsx\App\Frontend\Tasks\Frontend_Tasks_Controller;
use Rsx\Models\Client_Model;
use Rsx\Models\Contact_Model;
use Rsx\Models\Portal_Project_Model;
use Rsx\Models\Project_Contact_Model;
use Rsx\Models\Project_Model;
use Rsx\Models\Task_Model;

/**
 * Staff saves write what the form sent, and only that.
 *
 *   - a task's parent arrives as ONE field, `taskable` = {type, id}, and an edit that
 *     changed it writes the taskable_type / taskable_id pair
 *   - a reference a record already holds is accepted as it stands, so a task or project
 *     whose linked row was since deleted still saves; a NEW reference to a deleted row is
 *     refused
 *   - a new project defaults to Active (the list's default view), and its status and
 *     priority are written as sent
 *   - a new contact is active unless the submission says otherwise, and is_active is
 *     written as sent
 *   - a project's portal visibility is SET to the value sent, idempotently, and only for a
 *     project of that client
 */
class Save_Correctness_Test extends Rsx_Test_Abstract
{
    private const SITE_ID = 1;

    public static function setup(): void
    {
        static::__acting_as_site(self::SITE_ID);
        static::__acting_as_user(1);
    }

    public static function teardown(): void
    {
        static::__reset_session();
    }

    // ---------------------------------------------------------------------
    // fixtures
    // ---------------------------------------------------------------------

    private static function __client(): Client_Model
    {
        $client = new Client_Model();
        $client->name = 'Save Correctness ' . uniqid();
        $client->save();

        return $client;
    }

    private static function __project(?Client_Model $client = null): Project_Model
    {
        $project = new Project_Model();
        $project->name = 'Save Correctness Project ' . uniqid();
        $project->client_id = ($client ?? static::__client())->id;
        $project->status = Project_Model::STATUS_ACTIVE;
        $project->priority = Project_Model::PRIORITY_MEDIUM;
        $project->save();

        return $project;
    }

    private static function __contact(Client_Model $client): Contact_Model
    {
        $contact = new Contact_Model();
        $contact->client_id = $client->id;
        $contact->first_name = 'Linked';
        $contact->last_name = 'Contact ' . uniqid();
        $contact->email = 'save_correctness_' . uniqid() . '@example.com';
        $contact->save();

        return $contact;
    }

    private static function __task(array $columns = []): Task_Model
    {
        $task = new Task_Model();
        $task->title = 'Save Correctness Task';
        $task->status = Task_Model::STATUS_PENDING;
        $task->priority = Task_Model::PRIORITY_MEDIUM;
        foreach ($columns as $column => $value) {
            $task->{$column} = $value;
        }
        $task->save();

        return Task_Model::find($task->id);
    }

    /** An edit submission of $record that changed only $changed. */
    private static function __edit($record, array $values, array $changed): array
    {
        return array_merge($values, [
            'id' => $record->id,
            '_changed' => $changed,
            '_loaded_updated_at' => $record->updated_at,
        ]);
    }

    // ---------------------------------------------------------------------
    // tasks
    // ---------------------------------------------------------------------

    public static function test_changing_a_tasks_parent_on_edit_is_written()
    {
        $project = static::__project();
        $task = static::__task();

        $result = Frontend_Tasks_Controller::save(new Request(), static::__edit($task, [
            'title' => $task->title,
            'taskable' => ['type' => 'Project_Model', 'id' => $project->id],
        ], ['taskable']));

        static::__assert_array_has_key('id', $result, 'the save succeeds');

        $saved = Task_Model::find($task->id);
        static::__assert_equals('Project_Model', $saved->taskable_type, 'the parent type is written');
        static::__assert_equals((int) $project->id, (int) $saved->taskable_id, 'the parent id is written');
        static::__assert_equals((int) $project->id, (int) $saved->project_id, 'the project is derived from the new parent');
    }

    public static function test_clearing_a_tasks_parent_on_edit_is_written()
    {
        $project = static::__project();
        $task = static::__task(['taskable_type' => 'Project_Model', 'taskable_id' => $project->id]);

        Frontend_Tasks_Controller::save(new Request(), static::__edit($task, [
            'title' => $task->title,
            'taskable' => ['type' => null, 'id' => null],
        ], ['taskable']));

        $saved = Task_Model::find($task->id);
        static::__assert_null($saved->taskable_type, 'the parent is cleared');
        static::__assert_null($saved->taskable_id);
    }

    public static function test_a_task_holding_deleted_references_still_saves()
    {
        $client = static::__client();
        $project = static::__project();
        $task = static::__task([
            'taskable_type' => 'Client_Model',
            'taskable_id' => $client->id,
            'direct_project_id' => $project->id,
        ]);
        $client->delete();
        $project->delete();

        $result = Frontend_Tasks_Controller::save(new Request(), static::__edit($task, [
            'title' => 'Renamed',
            'taskable' => ['type' => 'Client_Model', 'id' => $client->id],
            'direct_project_id' => $project->id,
        ], ['title']));

        static::__assert_array_has_key('id', $result, 'unchanged references to deleted rows do not block the save');
        static::__assert_equals('Renamed', Task_Model::find($task->id)->title);
    }

    public static function test_a_new_reference_to_a_deleted_row_is_refused()
    {
        $deleted = static::__project();
        $deleted->delete();
        $task = static::__task();

        $result = Frontend_Tasks_Controller::save(new Request(), static::__edit($task, [
            'title' => $task->title,
            'taskable' => ['type' => 'Project_Model', 'id' => $deleted->id],
        ], ['taskable']));

        static::__assert_instance_of(Error_Response::class, $result, 'a deleted parent cannot be chosen');
        static::__assert_array_has_key('taskable', $result->get_metadata(), 'the error names the taskable field');
        static::__assert_null(Task_Model::find($task->id)->taskable_type, 'nothing was written');
    }

    // ---------------------------------------------------------------------
    // projects
    // ---------------------------------------------------------------------

    public static function test_a_new_project_defaults_to_active()
    {
        $result = Frontend_Projects_Controller::save(new Request(), [
            'name' => 'Defaults',
            'client_id' => static::__client()->id,
        ]);

        $project = Project_Model::find($result['project_id']);
        static::__assert_equals(Project_Model::STATUS_ACTIVE, (int) $project->status, 'a new project is Active');
        static::__assert_equals(Project_Model::PRIORITY_MEDIUM, (int) $project->priority);
    }

    public static function test_a_projects_status_and_priority_are_written_on_edit()
    {
        $project = static::__project();

        Frontend_Projects_Controller::save(new Request(), static::__edit($project, [
            'name' => $project->name,
            'client_id' => $project->client_id,
            'status' => Project_Model::STATUS_ON_HOLD,
            'priority' => Project_Model::PRIORITY_URGENT,
        ], ['status', 'priority']));

        $saved = Project_Model::find($project->id);
        static::__assert_equals(Project_Model::STATUS_ON_HOLD, (int) $saved->status);
        static::__assert_equals(Project_Model::PRIORITY_URGENT, (int) $saved->priority);
    }

    public static function test_a_project_linked_to_a_deleted_contact_still_saves()
    {
        $client = static::__client();
        $project = static::__project($client);
        $contact = static::__contact($client);

        $link = new Project_Contact_Model();
        $link->site_id = $project->site_id;
        $link->project_id = $project->id;
        $link->contact_id = $contact->id;
        $link->save();
        $contact->delete();

        // The form pre-selects the linked contact, as project_form_options returns it.
        $options = Frontend_Projects_Controller::project_form_options(new Request(), [
            'client_id' => $client->id,
            'project_id' => $project->id,
        ]);
        static::__assert_equals([(int) $contact->id], $options['selected_contacts']);
        $offered = array_values(array_filter($options['contacts'], fn ($o) => (int) $o['id'] === (int) $contact->id));
        static::__assert_count(1, $offered, 'the deleted linked contact is still offered');
        static::__assert_contains('(deleted)', $offered[0]['label'], 'and marked');

        $result = Frontend_Projects_Controller::save(new Request(), static::__edit($project, [
            'name' => 'Renamed project',
            'client_id' => $client->id,
            'contacts' => [$contact->id],
        ], ['name']));

        static::__assert_array_has_key('project_id', $result, 'the already-linked deleted contact does not block the save');
        static::__assert_equals('Renamed project', Project_Model::find($project->id)->name);

        // Adding a deleted contact that was NOT linked is refused.
        $other = static::__contact($client);
        $other->delete();
        $refused = Frontend_Projects_Controller::save(new Request(), static::__edit(Project_Model::find($project->id), [
            'name' => 'Renamed project',
            'client_id' => $client->id,
            'contacts' => [$contact->id, $other->id],
        ], ['contacts']));
        static::__assert_instance_of(Error_Response::class, $refused, 'a newly selected deleted contact is refused');
    }

    // ---------------------------------------------------------------------
    // contacts
    // ---------------------------------------------------------------------

    private static function __contact_params(Client_Model $client, array $extra = []): array
    {
        return array_merge([
            'first_name' => 'Active',
            'last_name' => 'Default',
            'email' => 'save_correctness_' . uniqid() . '@example.com',
            'client_id' => $client->id,
        ], $extra);
    }

    public static function test_a_new_contact_is_active()
    {
        $client = static::__client();

        $without_key = Frontend_Contacts_Controller::save(new Request(), static::__contact_params($client));
        static::__assert_equals(1, (int) Contact_Model::find($without_key['contact_id'])->is_active, 'a create that sends no is_active is active');

        $checked = Frontend_Contacts_Controller::save(new Request(), static::__contact_params($client, ['is_active' => '1']));
        static::__assert_equals(1, (int) Contact_Model::find($checked['contact_id'])->is_active);

        $unchecked = Frontend_Contacts_Controller::save(new Request(), static::__contact_params($client, ['is_active' => '0']));
        static::__assert_equals(0, (int) Contact_Model::find($unchecked['contact_id'])->is_active, 'an unchecked box is inactive');
    }

    public static function test_a_contact_is_deactivated_and_reactivated_on_edit()
    {
        $client = static::__client();
        $created = Frontend_Contacts_Controller::save(new Request(), static::__contact_params($client));
        $contact = Contact_Model::find($created['contact_id']);

        Frontend_Contacts_Controller::save(new Request(), static::__edit($contact, static::__contact_params($client, [
            'email' => $contact->email,
            'is_active' => '0',
        ]), ['is_active']));
        $contact = Contact_Model::find($contact->id);
        static::__assert_equals(0, (int) $contact->is_active, 'deactivated');

        Frontend_Contacts_Controller::save(new Request(), static::__edit($contact, static::__contact_params($client, [
            'email' => $contact->email,
            'is_active' => '1',
        ]), ['is_active']));
        static::__assert_equals(1, (int) Contact_Model::find($contact->id)->is_active, 'reactivated');
    }

    // ---------------------------------------------------------------------
    // portal project visibility
    // ---------------------------------------------------------------------

    public static function test_portal_project_visibility_is_set_not_toggled()
    {
        $client = static::__client();
        $project = static::__project($client);
        $params = ['client_id' => $client->id, 'project_id' => $project->id];

        Frontend_Clients_Controller::portal_set_project_visible(new Request(), $params + ['visible' => true]);
        Frontend_Clients_Controller::portal_set_project_visible(new Request(), $params + ['visible' => true]);
        static::__assert_true(Portal_Project_Model::is_visible($client->id, $project->id), 'setting visible twice leaves it visible');

        Frontend_Clients_Controller::portal_set_project_visible(new Request(), $params + ['visible' => false]);
        Frontend_Clients_Controller::portal_set_project_visible(new Request(), $params + ['visible' => false]);
        static::__assert_false(Portal_Project_Model::is_visible($client->id, $project->id), 'setting hidden twice leaves it hidden');
    }

    public static function test_another_clients_project_cannot_be_made_visible()
    {
        $client = static::__client();
        $foreign_project = static::__project();

        $result = Frontend_Clients_Controller::portal_set_project_visible(new Request(), [
            'client_id' => $client->id,
            'project_id' => $foreign_project->id,
            'visible' => true,
        ]);

        static::__assert_instance_of(Error_Response::class, $result, 'a project of another client is refused');
        static::__assert_false(Portal_Project_Model::is_visible($client->id, $foreign_project->id), 'nothing was written');
    }
}
