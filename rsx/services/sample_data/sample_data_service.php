<?php

namespace Rsx\Services\SampleData;

use libphonenumber\PhoneNumberFormat;
use libphonenumber\PhoneNumberUtil;
use App\RSpade\Core\Files\File_Attachment_Model;
use App\RSpade\Core\Models\Site_Model;
use App\RSpade\Core\Models\User_Model;
use App\RSpade\Core\Revisions\Revision;
use App\RSpade\Core\Service\Rsx_Service_Abstract;
use App\RSpade\Core\Session\Session;
use App\RSpade\Core\Task\Task_Instance;
use App\RSpade\Core\Time\Rsx_Date;
use App\RSpade\Core\Time\Rsx_Time;
use Rsx\Lib\ActionLog\Action_Log;
use Rsx\Lib\Attachments\Record_Attachment_List;
use Rsx\Lib\Notification\Notification;
use Rsx\Lib\TextTypes\RichText\Rich_Text;
use Rsx\Models\Action_Log_Model;
use Rsx\Models\Announcement_Model;
use Rsx\Models\Client_Department_Model;
use Rsx\Models\Client_Model;
use Rsx\Models\Contact_Model;
use Rsx\Models\Demo_Product_Model;
use Rsx\Models\Notification_Model;
use Rsx\Models\Party_Model;
use Rsx\Models\Portal_Request_Thread_Model;
use Rsx\Models\Project_Contact_Model;
use Rsx\Models\Project_Model;
use Rsx\Models\Project_User_Model;
use Rsx\Models\Task_Model;

/**
 * The sample dataset: fills an EMPTY site with fictional clients, contacts, projects, tasks,
 * documents of every preview type, request threads, announcements, parties and products, so a
 * new install (or a demo) has something to look at in every screen.
 *
 *     php artisan rsx_app:seed [--site=<id>]
 *
 * The data is resource/sample_data.json (the cast is resource/seed_gags.txt; the documents
 * are generated into resource/documents by resource/generate_documents.php). Every email is a
 * TEST address and every phone a 555 number (Rsx_Test_Recipients), so nothing here can reach
 * a real person. It creates no account and enables no portal.
 *
 * It REFUSES a site that already holds application data, in every mode: it is a starting
 * point, never a merge. The first-run setup screen offers it when
 * config('rsx.first_run.sample_data_task') names it. How to refresh the data as the
 * application grows: CLAUDE.md beside this file.
 */
class Sample_Data_Service extends Rsx_Service_Abstract
{
    /** The records whose presence makes a site "not empty". */
    private const OCCUPANCY_MODELS = [
        Client_Model::class,
        Contact_Model::class,
        Project_Model::class,
        Task_Model::class,
        Party_Model::class,
        Announcement_Model::class,
    ];

    /** Notifications are sent to the acting user for tasks with these titles' priority. */
    private const NOTIFY_PRIORITY = Task_Model::PRIORITY_URGENT;

    #[Task('Seed the sample dataset into an empty site')]
    #[Command('rsx_app:seed', 'Seed the sample dataset (fictional clients, contacts, projects, tasks and documents) into an empty site')]
    public static function seed(Task_Instance $task, array $params = [])
    {
        $site_id = static::__resolve_site($params);
        if ($site_id === null) {
            $task->stderr('More than one site exists - say which to seed: --site=<id>');

            return 1;
        }

        // Act as the site's first member for the run, so every record, revision and activity
        // entry is attributed to a person rather than to nobody.
        Session::set_site_id($site_id);
        $owner = User_Model::orderBy('id')->first();
        if ($owner === null) {
            $task->stderr("Site {$site_id} has no user to attribute the sample data to.");

            return 1;
        }
        Session::set_login_user_id((int) $owner->login_user_id, false);

        $occupied = static::__occupancy();
        if ($occupied !== []) {
            $task->stderr("Site {$site_id} already has data (" . implode(', ', $occupied) . ') - sample data is only seeded into an empty site.');

            return 1;
        }

        $data = json_decode((string) file_get_contents(__DIR__ . '/resource/sample_data.json'), true, 512, JSON_THROW_ON_ERROR);
        $counts = ['clients' => 0, 'contacts' => 0, 'projects' => 0, 'tasks' => 0, 'documents' => 0, 'threads' => 0];
        $steps = count($data['clients']) + 1;
        $done = 0;

        $task->status('Seeding clients');
        foreach ($data['clients'] as $client_data) {
            if ($task->is_stop_requested()) {
                $task->summary('Stopped after seeding ' . static::__describe($counts) . '.');

                return null;
            }
            $task->heartbeat();

            // One revision entry per client: its contacts, projects and tasks with it.
            Revision::unit_of_work("Seeded sample client {$client_data['name']}", function () use ($client_data, $site_id, $owner, &$counts) {
                static::__seed_client($client_data, $site_id, $owner, $counts);
            });

            $task->stdout("Seeded {$client_data['name']}");
            $task->progress_count(++$done, $steps);
        }

        $task->status('Seeding the rest');
        Revision::unit_of_work('Seeded sample reference documents, announcements, parties and products', function () use ($data, $site_id, $owner, &$counts) {
            static::__seed_reference_documents($data['reference_documents'], $site_id, $counts);
            static::__seed_announcements($data['announcements'], $site_id);
            static::__seed_parties($data['parties'], $site_id);
            static::__seed_general_tasks($data['general_tasks'], $site_id, $owner, $counts);
            static::__seed_products($data['products']);
        });
        $task->progress_count(++$done, $steps);

        $task->state($counts);
        $task->status('Done');
        $task->stdout('Seeded ' . static::__describe($counts) . " into site {$site_id}.");
        $task->summary('Seeded ' . static::__describe($counts) . '.');

        return null;
    }

    // -------------------------------------------------------------------------
    // Clients and everything under them
    // -------------------------------------------------------------------------

    private static function __seed_client(array $data, int $site_id, User_Model $owner, array &$counts): void
    {
        $client = new Client_Model();
        $client->site_id = $site_id;
        $client->name = $data['name'];
        $client->industry = $data['industry'];
        $client->status_id = constant(Client_Model::class . '::STATUS_' . $data['status']);
        $client->priority = constant(Client_Model::class . '::PRIORITY_' . $data['priority']);
        $client->address_street = $data['address'];
        $client->city = $data['city'];
        $client->state = $data['state'];
        $client->address_country = 'US';
        $client->zip = $data['zip'];
        $client->phone = $data['phone'];
        $client->website = $data['website'];
        $client->email = $data['email'];
        $client->notes = $data['notes'];
        $client->tags = json_encode($data['tags']);
        // The client-level portal switch only: it shows the Portal tab (documents, request
        // threads, announcements) to staff. No contact is given portal access.
        $client->portal_enabled = $data['portal_enabled'];
        $client->owner_user_id = $owner->id;
        $client->save();
        Action_Log::record(Action_Log_Model::TYPE_CLIENT_CREATED, $client);
        $counts['clients']++;

        $departments = [];
        foreach ($data['departments'] as $name) {
            $department = new Client_Department_Model();
            $department->site_id = $site_id;
            $department->client_id = $client->id;
            $department->name = $name;
            $department->save();
            $departments[$name] = $department->id;
        }

        $domain = substr($data['email'], strpos($data['email'], '@') + 1);
        $contacts = [];
        foreach ($data['contacts'] as $contact_data) {
            $contact = new Contact_Model();
            $contact->site_id = $site_id;
            $contact->client_id = $client->id;
            $contact->first_name = $contact_data['first'];
            $contact->last_name = $contact_data['last'];
            $contact->title = $contact_data['title'];
            $contact->client_department_id = isset($contact_data['department']) ? $departments[$contact_data['department']] : null;
            $contact->email = static::__mailbox($contact_data['first'], $contact_data['last']) . '@' . $domain;
            $contact->phone_work = static::__e164($contact_data['phone_work'] ?? null);
            $contact->phone_cell = static::__e164($contact_data['phone_cell'] ?? null);
            $contact->is_active = 1;
            $contact->priority = constant(Contact_Model::class . '::PRIORITY_' . $contact_data['priority']);
            $contact->notes = $contact_data['notes'];
            $contact->owner_user_id = $owner->id;
            $contact->save();
            Action_Log::record(Action_Log_Model::TYPE_CONTACT_CREATED, $contact);
            $contacts[$contact_data['key']] = $contact;
            $counts['contacts']++;
        }

        // The first contact is the billing contact.
        if ($contacts !== []) {
            $client->billing_contact_id = reset($contacts)->id;
            $client->save();
        }

        $documents = [];
        foreach ($data['documents'] as $document) {
            $attachment = static::__attachment(__DIR__ . '/resource/documents/' . $document['file'], $site_id);
            $attachment->add_to($client, Client_Model::DOCUMENTS_CATEGORY);
            $documents[$document['file']] = $document;
            $counts['documents']++;
        }

        foreach ($data['projects'] as $project_data) {
            static::__seed_project($project_data, $client, null, $contacts, $owner, $counts);
        }

        foreach ($data['client_tasks'] ?? [] as $task_data) {
            static::__seed_task($task_data, $site_id, 'Client_Model', $client->id, null, $owner, $counts);
        }

        foreach ($data['request_threads'] ?? [] as $thread_data) {
            $thread = new Portal_Request_Thread_Model();
            $thread->site_id = $site_id;
            $thread->client_id = $client->id;
            $thread->title = $thread_data['title'];
            $thread->status_id = Portal_Request_Thread_Model::STATUS_NEW_REQUEST;
            $thread->save();
            $thread->post_message(Session::get_login_user(), $thread_data['message']);

            $status = constant(Portal_Request_Thread_Model::class . '::STATUS_' . $thread_data['status']);
            if ($status !== Portal_Request_Thread_Model::STATUS_NEW_REQUEST) {
                $thread->set_status($status, Session::get_login_user());
            }
            $counts['threads']++;
        }
    }

    private static function __seed_project(array $data, Client_Model $client, ?Project_Model $parent, array $contacts, User_Model $owner, array &$counts): void
    {
        $project = new Project_Model();
        $project->site_id = $client->site_id;
        $project->name = $data['name'];
        $project->description = Rich_Text::from_plain_text($data['description']);
        $project->client_id = $client->id;
        $project->parent_project_id = $parent?->id;
        $project->status = constant(Project_Model::class . '::STATUS_' . $data['status']);
        $project->priority = constant(Project_Model::class . '::PRIORITY_' . $data['priority']);
        $project->start_date = Rsx_Date::add_days(Rsx_Date::today(), $data['start']);
        $project->due_date = Rsx_Date::add_days(Rsx_Date::today(), $data['due']);
        if ($project->status === Project_Model::STATUS_COMPLETED) {
            $project->completed_date = $project->due_date;
        }
        $project->budget = (string) $data['budget'];
        $project->owner_user_id = $owner->id;
        $project->save();
        Action_Log::record(Action_Log_Model::TYPE_PROJECT_CREATED, $project);
        $counts['projects']++;

        foreach ($data['contacts'] as $key) {
            $pivot = new Project_Contact_Model();
            $pivot->site_id = $client->site_id;
            $pivot->project_id = $project->id;
            $pivot->contact_id = $contacts[$key]->id;
            $pivot->save();
        }

        $assigned = new Project_User_Model();
        $assigned->site_id = $client->site_id;
        $assigned->project_id = $project->id;
        $assigned->user_id = $owner->id;
        $assigned->save();

        foreach ($data['tasks'] as $task_data) {
            static::__seed_task($task_data, $client->site_id, 'Project_Model', $project->id, $project->id, $owner, $counts);
        }

        foreach ($data['subprojects'] ?? [] as $child) {
            static::__seed_project($child, $client, $project, $contacts, $owner, $counts);
        }
    }

    /**
     * A task under $parent_type/$parent_id (null for an unattached task), then its subtasks
     * under it - a task-of-task derives its project through the chain.
     */
    private static function __seed_task(array $data, int $site_id, ?string $parent_type, ?int $parent_id, ?int $project_id, User_Model $owner, array &$counts): Task_Model
    {
        $task = new Task_Model();
        $task->site_id = $site_id;
        $task->title = $data['title'];
        $task->description = isset($data['description']) ? Rich_Text::from_plain_text($data['description']) : null;
        $task->taskable_type = $parent_type;
        $task->taskable_id = $parent_id;
        $task->status = constant(Task_Model::class . '::STATUS_' . $data['status']);
        $task->priority = constant(Task_Model::class . '::PRIORITY_' . $data['priority']);
        $task->due_date = Rsx_Date::add_days(Rsx_Date::today(), $data['due']);
        if ($task->status === Task_Model::STATUS_COMPLETED) {
            $task->completed_date = $task->due_date;
        }
        $task->assigned_to_user_id = $owner->id;
        $task->hour_estimate = (string) $data['hours'];
        $task->project_id = $project_id;
        $task->save();
        Action_Log::record(Action_Log_Model::TYPE_TASK_CREATED, $task);
        $counts['tasks']++;

        if (isset($data['attach'])) {
            $attachment = static::__attachment(__DIR__ . '/resource/documents/' . $data['attach'], $site_id);
            $attachment->add_to($task, Record_Attachment_List::CATEGORY);
            $counts['documents']++;
        }

        if ($task->priority === self::NOTIFY_PRIORITY && $task->status !== Task_Model::STATUS_COMPLETED) {
            Notification::send(Notification_Model::TYPE_TASK_ASSIGNED, [(int) $owner->login_user_id], $task);
        }

        foreach ($data['subtasks'] ?? [] as $child) {
            static::__seed_task($child, $site_id, 'Task_Model', $task->id, $task->resolve_chain_project_id(), $owner, $counts);
        }

        return $task;
    }

    // -------------------------------------------------------------------------
    // Site-wide records
    // -------------------------------------------------------------------------

    private static function __seed_reference_documents(array $documents, int $site_id, array &$counts): void
    {
        foreach ($documents as $document) {
            $client = Client_Model::where('email', 'like', '%@test.' . $document['client'] . '.com')->firstOrFail();
            $attachment = static::__attachment(__DIR__ . '/resource/documents/' . $document['file'], $site_id);
            $attachment->add_to($client, Client_Model::DOCUMENTS_CATEGORY);
            $counts['documents']++;
        }
    }

    private static function __seed_announcements(array $announcements, int $site_id): void
    {
        foreach ($announcements as $data) {
            $announcement = new Announcement_Model();
            $announcement->site_id = $site_id;
            $announcement->client_id = $data['client'] === null
                ? null
                : Client_Model::where('email', 'like', '%@test.' . $data['client'] . '.com')->firstOrFail()->id;
            $announcement->title = $data['title'];
            $announcement->body = $data['body'];
            $announcement->published_at = Rsx_Time::add(Rsx_Time::now_iso(), $data['published'] * 86400);
            $announcement->save();
        }
    }

    private static function __seed_parties(array $parties, int $site_id): void
    {
        foreach ($parties as $data) {
            $party = new Party_Model();
            $party->site_id = $site_id;
            $party->type_id = constant(Party_Model::class . '::TYPE_' . $data['type']);
            $party->name = $data['name'];
            $party->email = $data['email'];
            $party->phone = $data['phone'];
            $party->save();

            if ($party->type_id === Party_Model::TYPE_PERSON) {
                $detail = $party->party_person;
                $detail->first_name = $data['first_name'];
                $detail->last_name = $data['last_name'];
                $detail->title = $data['title'];
                $detail->save();
            } elseif ($party->type_id === Party_Model::TYPE_COMPANY) {
                $detail = $party->party_company;
                $detail->legal_name = $data['legal_name'];
                $detail->industry = $data['industry'];
                $detail->employee_count = $data['employee_count'];
                $detail->save();
            }

            Action_Log::record(Action_Log_Model::TYPE_PARTY_CREATED, $party);
        }
    }

    /** The unattached tasks; `"parent": "owner"` parents one to the acting user. */
    private static function __seed_general_tasks(array $tasks, int $site_id, User_Model $owner, array &$counts): void
    {
        foreach ($tasks as $data) {
            $is_personal = ($data['parent'] ?? null) === 'owner';
            static::__seed_task($data, $site_id, $is_personal ? 'User_Model' : null, $is_personal ? $owner->id : null, null, $owner, $counts);
        }
    }

    /** Products are one catalogue for the whole install (no site), seeded only while it is empty. */
    private static function __seed_products(array $products): void
    {
        if (Demo_Product_Model::query()->exists()) {
            return;
        }

        foreach ($products as $data) {
            $product = new Demo_Product_Model();
            $product->name = $data['name'];
            $product->description = Rich_Text::from_plain_text($data['description']);
            $product->price = $data['price'];
            $product->status_id = constant(Demo_Product_Model::class . '::STATUS_' . $data['status']);
            $product->category_id = constant(Demo_Product_Model::class . '::CATEGORY_' . $data['category']);
            $product->save();
        }
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /** The site to seed: --site, else the only site there is; null when that is ambiguous. */
    private static function __resolve_site(array $params): ?int
    {
        if (isset($params['site'])) {
            $site = Site_Model::find((int) $params['site']);
            if ($site === null) {
                throw new \InvalidArgumentException("No site {$params['site']}.");
            }

            return (int) $site->id;
        }

        $ids = Site_Model::query()->where('id', '>', 0)->orderBy('id')->limit(2)->pluck('id')->all();

        return count($ids) === 1 ? (int) $ids[0] : null;
    }

    /** The model labels this site already holds rows of. */
    private static function __occupancy(): array
    {
        $occupied = [];
        foreach (self::OCCUPANCY_MODELS as $model) {
            if ($model::query()->exists()) {
                $occupied[] = class_basename($model);
            }
        }

        return $occupied;
    }

    private static function __attachment(string $path, int $site_id): File_Attachment_Model
    {
        return File_Attachment_Model::create_from_disk($path, ['site_id' => $site_id, 'filename' => basename($path)]);
    }

    /** "Dwayne Elizondo ...", "O'Brien" -> "dwayne.obrien" */
    private static function __mailbox(string $first, string $last): string
    {
        $clean = fn (string $value) => preg_replace('/[^a-z]/', '', strtolower($value));

        return $clean(strtok($first, ' .')) . '.' . $clean($last);
    }

    /** A (nnn) 555-01nn number in E.164, the form the contact screens store. */
    private static function __e164(?string $number): ?string
    {
        if ($number === null) {
            return null;
        }
        $util = PhoneNumberUtil::getInstance();

        return $util->format($util->parse($number, 'US'), PhoneNumberFormat::E164);
    }

    private static function __describe(array $counts): string
    {
        return "{$counts['clients']} clients, {$counts['contacts']} contacts, {$counts['projects']} projects, "
            . "{$counts['tasks']} tasks, {$counts['documents']} documents and {$counts['threads']} request threads";
    }
}
