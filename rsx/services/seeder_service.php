<?php






/**
 * Seeder Service - Database seeding tasks
 *
 * Provides tasks for populating the database with test data:
 * - seed_clients: Create 20 test clients
 * - seed_contacts: Create 5-15 contacts per client
 * - seed_all: Run both seeders in sequence
 */

namespace Rsx\Services;

use App\RSpade\Core\Models\User_Model;
use App\RSpade\Core\Revisions\Revision;
use App\RSpade\Core\Service\Rsx_Service_Abstract;
use App\RSpade\Core\Task\Task;
use App\RSpade\Core\Task\Task_Instance;
use App\RSpade\Core\Time\Rsx_Date;
use Rsx\Lib\TextTypes\RichText\Rich_Text;
use Rsx\Models\Client_Model;
use Rsx\Models\Contact_Model;
use Rsx\Models\Project_Contact_Model;
use Rsx\Models\Project_Model;
use Rsx\Models\Project_User_Model;
use Rsx\Models\Task_Model;

class Seeder_Service extends Rsx_Service_Abstract
{
    #[Task('Seed 20 test clients into the database')]
    public static function seed_clients(Task_Instance $task, array $params = [])
    {
        // Prevent running in production
        if (app()->environment('production')) {
            throw new \Exception("Cannot run seeders in production environment. Seeders are for development only.");
        }

        // Check if clients table already has data
        if (Client_Model::count() > 0) {
            throw new \Exception("Cannot seed clients - table already contains data. Clear the table first.");
        }

        $clients_created = 0;
        $company_types = ['Solutions', 'Industries', 'Technologies', 'Systems', 'Group', 'Corp', 'Inc'];
        $company_names = ['Acme', 'Global', 'Premier', 'Alpha', 'Summit', 'Vertex', 'Apex', 'Elite', 'Prime', 'Unity'];
        $streets = ['Main St', 'Oak Ave', 'Maple Dr', 'Washington Blvd', 'Park Pl', 'Commerce Way', 'Tech Pkwy', 'Business Center Dr'];
        $cities = [
            ['name' => 'San Francisco', 'state' => 'CA', 'zip_prefix' => '941'],
            ['name' => 'Austin', 'state' => 'TX', 'zip_prefix' => '787'],
            ['name' => 'Seattle', 'state' => 'WA', 'zip_prefix' => '981'],
            ['name' => 'Boston', 'state' => 'MA', 'zip_prefix' => '021'],
            ['name' => 'Chicago', 'state' => 'IL', 'zip_prefix' => '606'],
            ['name' => 'Denver', 'state' => 'CO', 'zip_prefix' => '802'],
            ['name' => 'Atlanta', 'state' => 'GA', 'zip_prefix' => '303'],
            ['name' => 'Portland', 'state' => 'OR', 'zip_prefix' => '972'],
        ];

        for ($i = 1; $i <= 20; $i++) {
            if ($task->is_stop_requested()) {
                $task->summary("Stopped after seeding {$clients_created} of 20 client(s).");

                return null;
            }
            $task->heartbeat();

            // One revision-history entry per seeded client, not one for the whole task run.
            Revision::begin_unit_of_work("Seeded client {$i}");

            $company_name = $company_names[array_rand($company_names)];
            $company_type = $company_types[array_rand($company_types)];
            $location = $cities[array_rand($cities)];

            $client = new Client_Model();
            $client->site_id = 1;
            $client->name = "{$company_name} {$company_type}";
            $client->address = rand(100, 9999) . ' ' . $streets[array_rand($streets)];
            $client->city = $location['name'];
            $client->state = $location['state'];
            $client->zip = $location['zip_prefix'] . str_pad(rand(0, 99), 2, '0', STR_PAD_LEFT);
            $client->phone = sprintf('(%03d) %03d-%04d', rand(200, 999), rand(200, 999), rand(1000, 9999));
            $client->phone_secondary = rand(0, 1) ? sprintf('(%03d) %03d-%04d', rand(200, 999), rand(200, 999), rand(1000, 9999)) : null;
            $client->website = strtolower(str_replace(' ', '', $company_name . $company_type)) . '.com';
            $client->priority = rand(Client_Model::PRIORITY_LOW, Client_Model::PRIORITY_URGENT);
            $client->notes = rand(0, 1) ? 'Test client created by seeder' : null;
            // Authorship is stamped automatically by save() (created_by_id/created_by_type);
            // a CLI seeder has no signed-in actor, so seeded rows are deliberately unattributed.
            $client->owner_user_id = 1;
            $client->save();

            $clients_created++;
            $task->progress_count($i, 20);
        }

        $task->state(['clients_created' => $clients_created]);
        $task->summary("Seeded {$clients_created} client(s).");

        return null;
    }

    #[Task('Seed 5-15 test contacts for each client')]
    public static function seed_contacts(Task_Instance $task, array $params = [])
    {
        // Prevent running in production
        if (app()->environment('production')) {
            throw new \Exception("Cannot run seeders in production environment. Seeders are for development only.");
        }

        // Check if contacts table already has data
        if (Contact_Model::count() > 0) {
            throw new \Exception("Cannot seed contacts - table already contains data. Clear the table first.");
        }

        // Get all clients
        $clients = Client_Model::all();
        if ($clients->isEmpty()) {
            throw new \Exception("No clients found. Run seed_clients first.");
        }

        $contacts_created = 0;
        $first_names = ['John', 'Jane', 'Michael', 'Sarah', 'David', 'Emily', 'Robert', 'Jennifer', 'William', 'Lisa', 'James', 'Mary', 'Richard', 'Patricia', 'Thomas'];
        $last_names = ['Smith', 'Johnson', 'Williams', 'Brown', 'Jones', 'Garcia', 'Miller', 'Davis', 'Rodriguez', 'Martinez', 'Hernandez', 'Lopez', 'Wilson', 'Anderson', 'Taylor'];
        $titles = ['CEO', 'CTO', 'CFO', 'VP of Operations', 'Director of IT', 'Project Manager', 'Account Manager', 'Sales Director', 'Operations Manager', 'IT Manager'];

        $clients_done = 0;
        foreach ($clients as $client) {
            if ($task->is_stop_requested()) {
                $task->summary("Stopped after seeding {$contacts_created} contact(s) across {$clients_done} of {$clients->count()} client(s).");

                return null;
            }
            $task->heartbeat();

            // Create 5-15 contacts per client, each client's batch its own unit of work in
            // revision history (the closure form hands the task's own unit back afterwards).
            $contact_count = rand(5, 15);

            Revision::unit_of_work("Seeded {$contact_count} contacts for {$client->name}", function () use (
                $client, $contact_count, $first_names, $last_names, $titles, &$contacts_created
            ) {
                for ($i = 0; $i < $contact_count; $i++) {
                    $first_name = $first_names[array_rand($first_names)];
                    $last_name = $last_names[array_rand($last_names)];
                    $email_local = strtolower($first_name . '.' . $last_name);
                    $domain = strtolower(str_replace(' ', '', $client->name)) . '.com';

                    $contact = new Contact_Model();
                    $contact->site_id = $client->site_id;
                    $contact->client_id = $client->id;
                    $contact->first_name = $first_name;
                    $contact->last_name = $last_name;
                    $contact->title = $titles[array_rand($titles)];
                    $contact->email = $email_local . '@' . $domain;
                    $contact->email_secondary = rand(0, 1) ? $email_local . rand(1, 99) . '@' . $domain : null;
                    $contact->phone_work = sprintf('(%03d) %03d-%04d', rand(200, 999), rand(200, 999), rand(1000, 9999));
                    $contact->phone_cell = rand(0, 1) ? sprintf('(%03d) %03d-%04d', rand(200, 999), rand(200, 999), rand(1000, 9999)) : null;
                    $contact->phone_other = rand(0, 1) ? sprintf('(%03d) %03d-%04d', rand(200, 999), rand(200, 999), rand(1000, 9999)) : null;
                    $contact->is_active = rand(0, 10) > 1; // 90% active
                    $contact->priority = rand(Contact_Model::PRIORITY_LOW, Contact_Model::PRIORITY_URGENT);
                    $contact->notes = rand(0, 1) ? 'Test contact created by seeder' : null;
                    $contact->owner_user_id = 1;
                    $contact->save();

                    $contacts_created++;
                }
            });

            $clients_done++;
            $task->progress_count($clients_done, $clients->count());
        }

        $task->state(['clients_processed' => $clients->count(), 'contacts_created' => $contacts_created]);
        $task->summary("Seeded {$contacts_created} contact(s) across {$clients->count()} client(s).");

        return null;
    }

    #[Task('Seed 2-3 projects for each client that has none')]
    public static function seed_projects(Task_Instance $task, array $params = [])
    {
        // Prevent running in production
        if (app()->environment('production')) {
            throw new \Exception("Cannot run seeders in production environment. Seeders are for development only.");
        }

        $clients = Client_Model::all();
        if ($clients->isEmpty()) {
            throw new \Exception("No clients found. Run seed_clients first.");
        }

        // Additive + idempotent: only clients that currently have no projects get
        // seeded, so re-running never duplicates and never clobbers real data.
        $project_words = ['Website Redesign', 'Mobile App', 'Brand Identity', 'CRM Integration', 'Marketing Campaign', 'Data Migration', 'Portal Launch', 'API Platform', 'Onboarding Flow', 'Analytics Dashboard'];
        $statuses = [
            Project_Model::STATUS_PLANNING,
            Project_Model::STATUS_ACTIVE,
            Project_Model::STATUS_ACTIVE,   // weight ACTIVE so the dashboard has active work
            Project_Model::STATUS_ON_HOLD,
            Project_Model::STATUS_COMPLETED,
        ];

        $task->status('Seeding projects');
        $projects_created = 0;
        $subprojects_created = 0;
        foreach ($clients as $client) {
            if ($task->is_stop_requested()) {
                $task->summary("Stopped after seeding {$projects_created} project(s) and {$subprojects_created} subproject(s).");

                return null;
            }
            $task->heartbeat();

            if (Project_Model::where('client_id', $client->id)->exists()) {
                continue; // already has projects - leave it alone
            }

            $count = rand(2, 3);
            for ($i = 0; $i < $count; $i++) {
                $project = new Project_Model();
                $project->site_id = $client->site_id;
                $project->name = $project_words[array_rand($project_words)];
                $project->description = Rich_Text::from_plain_text('Seeded demo project for ' . $client->name);
                $project->client_id = $client->id;
                $project->status = $statuses[array_rand($statuses)];
                $project->priority = rand(Project_Model::PRIORITY_LOW, Project_Model::PRIORITY_URGENT);
                $project->start_date = Rsx_Date::add_days(Rsx_Date::today(), -rand(10, 120));
                $project->due_date = Rsx_Date::add_days(Rsx_Date::today(), rand(-15, 90));
                $project->budget = rand(5, 120) * 1000;
                $project->owner_user_id = 1;
                $project->save();

                $projects_created++;
            }
        }

        // --- Subprojects (1 level). Idempotent: seed only when NO subproject exists yet, so
        //     the stop is checked before the batch rather than inside it - a half-seeded batch
        //     would never be completed by a re-run. ---
        if ($task->is_stop_requested()) {
            $task->summary("Stopped after seeding {$projects_created} project(s) and {$subprojects_created} subproject(s).");

            return null;
        }
        $task->status('Seeding subprojects');
        if (Project_Model::whereNotNull('parent_project_id')->count() === 0) {
            // Give a handful of top-level projects one child project each.
            $parents = Project_Model::whereNull('parent_project_id')->limit(5)->get();
            foreach ($parents as $parent) {
                $task->heartbeat();

                $child = new Project_Model();
                $child->site_id = $parent->site_id;
                $child->name = $parent->name . ' - Phase 2';
                $child->description = Rich_Text::from_plain_text('Seeded subproject of ' . $parent->name);
                $child->client_id = $parent->client_id;
                $child->parent_project_id = $parent->id;
                $child->status = Project_Model::STATUS_ACTIVE;
                $child->priority = rand(Project_Model::PRIORITY_LOW, Project_Model::PRIORITY_URGENT);
                $child->start_date = Rsx_Date::add_days(Rsx_Date::today(), -rand(5, 30));
                $child->due_date = Rsx_Date::add_days(Rsx_Date::today(), rand(15, 60));
                $child->budget = rand(5, 50) * 1000;
                $child->owner_user_id = 1;
                $child->save();

                $subprojects_created++;
            }
        }

        // --- Pivots: contacts (from the project's client) + assigned users. Additive +
        //     idempotent per-project (a project that already has pivot rows is skipped). ---
        $all_user_ids = User_Model::query()->pluck('id')->all();
        $contact_pivots = 0;
        $user_pivots = 0;

        $task->status('Assigning project contacts and users');
        foreach (Project_Model::all() as $project) {
            if ($task->is_stop_requested()) {
                $task->summary("Stopped after seeding {$projects_created} project(s) and {$subprojects_created} subproject(s).");

                return null;
            }
            $task->heartbeat();

            // Contacts: 1-2 from this project's client.
            if (!Project_Contact_Model::where('project_id', $project->id)->exists()) {
                $client_contact_ids = Contact_Model::where('client_id', $project->client_id)
                    ->limit(10)->pluck('id')->all();
                if (!empty($client_contact_ids)) {
                    shuffle($client_contact_ids);
                    $take = array_slice($client_contact_ids, 0, rand(1, 2));
                    foreach ($take as $cid) {
                        $pivot = new Project_Contact_Model();
                        $pivot->site_id = $project->site_id;
                        $pivot->project_id = $project->id;
                        $pivot->contact_id = $cid;
                        $pivot->save();
                        $contact_pivots++;
                    }
                }
            }

            // Assigned users: 1-2 of the available users.
            if (!empty($all_user_ids) && !Project_User_Model::where('project_id', $project->id)->exists()) {
                $pool = $all_user_ids;
                shuffle($pool);
                $take = array_slice($pool, 0, min(rand(1, 2), count($pool)));
                foreach ($take as $uid) {
                    $pivot = new Project_User_Model();
                    $pivot->site_id = $project->site_id;
                    $pivot->project_id = $project->id;
                    $pivot->user_id = $uid;
                    $pivot->save();
                    $user_pivots++;
                }
            }
        }

        $task->state([
            'projects_created' => $projects_created,
            'subprojects_created' => $subprojects_created,
            'contact_pivots_created' => $contact_pivots,
            'user_pivots_created' => $user_pivots,
        ]);
        $task->summary("Seeded {$projects_created} project(s) and {$subprojects_created} subproject(s).");

        return null;
    }

    #[Task('Seed 3-5 tasks for each project that has none')]
    public static function seed_tasks(Task_Instance $task, array $params = [])
    {
        // Prevent running in production
        if (app()->environment('production')) {
            throw new \Exception("Cannot run seeders in production environment. Seeders are for development only.");
        }

        $projects = Project_Model::all();
        if ($projects->isEmpty()) {
            throw new \Exception("No projects found. Run seed_projects first.");
        }

        // Additive + idempotent: only projects with no tasks get seeded.
        $task_titles = ['Review design mockups', 'Call the client', 'Draft the proposal', 'Fix reported bug', 'Prepare status report', 'Schedule kickoff', 'Update documentation', 'Deploy to staging', 'QA pass', 'Collect requirements'];
        // Weighted so most tasks are still open (dashboard shows live work).
        $statuses = [
            Task_Model::STATUS_PENDING,
            Task_Model::STATUS_PENDING,
            Task_Model::STATUS_IN_PROGRESS,
            Task_Model::STATUS_IN_PROGRESS,
            Task_Model::STATUS_COMPLETED,
        ];

        $task->status('Seeding tasks');
        $tasks_created = 0;
        $chains_created = 0;
        foreach ($projects as $project) {
            if ($task->is_stop_requested()) {
                $task->summary("Stopped after seeding {$tasks_created} task(s) and {$chains_created} dependency-chain task(s).");

                return null;
            }
            $task->heartbeat();

            if (Task_Model::where('taskable_type', 'Project_Model')->where('taskable_id', $project->id)->exists()) {
                continue;
            }

            $count = rand(3, 5);
            for ($i = 0; $i < $count; $i++) {
                $status = $statuses[array_rand($statuses)];

                $t = new Task_Model();
                $t->site_id = $project->site_id;
                $t->title = $task_titles[array_rand($task_titles)];
                $t->description = Rich_Text::from_plain_text('Seeded demo task for project ' . $project->name);
                $t->taskable_type = 'Project_Model';
                $t->taskable_id = $project->id;
                $t->status = $status;
                $t->priority = rand(Task_Model::PRIORITY_LOW, Task_Model::PRIORITY_URGENT);
                // Spread due dates: some overdue, some today, some upcoming so the
                // Open/Overdue KPIs and the Today's Tasks list all have content.
                $t->due_date = Rsx_Date::add_days(Rsx_Date::today(), rand(-7, 14));
                if ($status === Task_Model::STATUS_COMPLETED) {
                    $t->completed_date = Rsx_Date::today();
                }
                $t->assigned_to_user_id = 1;
                $t->hour_estimate = rand(1, 40) * 0.5; // 0.5 - 20 hours
                // Directly parented to a project -> project_id is DERIVED.
                $t->project_id = $project->id;
                $t->save();

                $tasks_created++;
            }
        }

        // --- Backfill DERIVED project_id for any task whose value is stale/null (idempotent:
        //     resolve_chain_project_id is a pure function of the current chain). ---
        $task->status('Backfilling derived task projects');
        $backfilled = 0;
        foreach (Task_Model::all() as $t) {
            if ($task->is_stop_requested()) {
                $task->summary("Stopped after seeding {$tasks_created} task(s) and {$chains_created} dependency-chain task(s).");

                return null;
            }
            $task->heartbeat();

            $chain = $t->resolve_chain_project_id();
            if ($chain !== null && (int) $t->project_id !== (int) $chain) {
                $t->project_id = $chain;
                $t->save();
                $backfilled++;
            }
            // Backfill hour_estimate on older rows that predate the column.
            if ($t->hour_estimate === null) {
                $t->hour_estimate = rand(1, 40) * 0.5;
                $t->save();
            }
        }

        // --- Task -> task chains + varied non-project parents. Idempotent: seed only when no
        //     task yet has a non-project parent (Task/Client/User taskable), so the stop is
        //     checked before the batch rather than inside it - a half-seeded batch would never
        //     be completed by a re-run. ---
        if ($task->is_stop_requested()) {
            $task->summary("Stopped after seeding {$tasks_created} task(s) and {$chains_created} dependency-chain task(s).");

            return null;
        }
        $task->status('Seeding task chains');
        $has_non_project_parent = Task_Model::where('taskable_type', '!=', 'Project_Model')
            ->orWhereNull('taskable_type')
            ->exists();

        if (!$has_non_project_parent) {
            // Subtasks under existing project-tasks (taskable = Task) -> project_id DERIVED
            // through the chain.
            $parent_tasks = Task_Model::where('taskable_type', 'Project_Model')->limit(8)->get();
            foreach ($parent_tasks as $parent_task) {
                $task->heartbeat();

                $sub = new Task_Model();
                $sub->site_id = $parent_task->site_id;
                $sub->title = 'Subtask of: ' . $parent_task->title;
                $sub->description = Rich_Text::from_plain_text('Seeded task-of-task (chain derives its project).');
                $sub->taskable_type = 'Task_Model';
                $sub->taskable_id = $parent_task->id;
                $sub->status = Task_Model::STATUS_PENDING;
                $sub->priority = Task_Model::PRIORITY_MEDIUM;
                $sub->due_date = Rsx_Date::add_days(Rsx_Date::today(), rand(1, 20));
                $sub->assigned_to_user_id = 1;
                $sub->hour_estimate = rand(1, 20) * 0.5;
                $sub->project_id = $sub->resolve_chain_project_id(); // derived through the parent
                $sub->save();
                $chains_created++;
            }

            // A couple parented to a Client (project_id editable -> left null here).
            $client = Client_Model::first();
            if ($client) {
                for ($i = 0; $i < 2; $i++) {
                    $ct = new Task_Model();
                    $ct->site_id = $client->site_id;
                    $ct->title = 'Client-level task ' . ($i + 1);
                    $ct->description = Rich_Text::from_plain_text('Seeded task parented to a client (no chain project).');
                    $ct->taskable_type = 'Client_Model';
                    $ct->taskable_id = $client->id;
                    $ct->status = Task_Model::STATUS_PENDING;
                    $ct->priority = Task_Model::PRIORITY_LOW;
                    $ct->due_date = Rsx_Date::add_days(Rsx_Date::today(), rand(1, 20));
                    $ct->assigned_to_user_id = 1;
                    $ct->hour_estimate = rand(1, 20) * 0.5;
                    $ct->project_id = null; // editable, seeded empty
                    $ct->save();
                    $chains_created++;
                }
            }

            // One parented to a User.
            $user = User_Model::first();
            if ($user) {
                $ut = new Task_Model();
                $ut->site_id = 1;
                $ut->title = 'Personal follow-up';
                $ut->description = Rich_Text::from_plain_text('Seeded task parented to a user (no chain project).');
                $ut->taskable_type = 'User_Model';
                $ut->taskable_id = $user->id;
                $ut->status = Task_Model::STATUS_PENDING;
                $ut->priority = Task_Model::PRIORITY_LOW;
                $ut->due_date = Rsx_Date::add_days(Rsx_Date::today(), rand(1, 20));
                $ut->assigned_to_user_id = $user->id;
                $ut->hour_estimate = rand(1, 20) * 0.5;
                $ut->project_id = null;
                $ut->save();
                $chains_created++;
            }

            // One top-level task with NO parent (project_id user-editable, seeded to a project).
            $orphan_project = Project_Model::first();
            $none = new Task_Model();
            $none->site_id = 1;
            $none->title = 'Unattached planning task';
            $none->description = Rich_Text::from_plain_text('Seeded task with no parent (project_id is user-set directly).');
            $none->taskable_type = null;
            $none->taskable_id = null;
            $none->status = Task_Model::STATUS_PENDING;
            $none->priority = Task_Model::PRIORITY_MEDIUM;
            $none->due_date = Rsx_Date::add_days(Rsx_Date::today(), rand(1, 20));
            $none->assigned_to_user_id = 1;
            $none->hour_estimate = rand(1, 20) * 0.5;
            $none->project_id = $orphan_project ? $orphan_project->id : null; // user-chosen
            $none->save();
            $chains_created++;
        }

        $task->state([
            'tasks_created' => $tasks_created,
            'project_id_backfilled' => $backfilled,
            'chain_tasks_created' => $chains_created,
        ]);
        $task->summary("Seeded {$tasks_created} task(s) and {$chains_created} dependency-chain task(s).");

        return null;
    }

    /**
     * #[Command] is all it takes to give a task its own artisan command:
     *
     *     php artisan rsx_app:seed
     *
     * is `php artisan rsx:task:run Seeder_Service seed_all` under a friendlier name, with the
     * same parameters, output and exit codes: what the task writes to stdout is the command's
     * stdout, its stderr (each status() change included) is the command's stderr, and its
     * return value is the exit code. See rsx:man task_commands.
     *
     * Each step runs as its own inline run (Task::internal()), recorded with its own state and
     * summary; this run reports its progress as steps done of four.
     */
    #[Task('Seed clients, contacts, projects and tasks (full demo dataset)')]
    #[Command('rsx_app:seed', 'Seed the demo dataset - clients, contacts, projects and tasks')]
    public static function seed_all(Task_Instance $task, array $params = [])
    {
        // Prevent running in production
        if (app()->environment('production')) {
            throw new \Exception("Cannot run seeders in production environment. Seeders are for development only.");
        }

        $steps = [
            'seed_clients' => 'Seeding clients',
            'seed_contacts' => 'Seeding contacts',
            'seed_projects' => 'Seeding projects',
            'seed_tasks' => 'Seeding tasks',
        ];

        $summaries = [];
        $done = 0;
        foreach ($steps as $method => $label) {
            if ($task->is_stop_requested()) {
                $task->summary(trim("Stopped after {$done} of " . count($steps) . ' step(s). ' . implode(' ', $summaries)));

                return null;
            }
            $task->heartbeat();

            $task->status($label);
            $task->progress_count($done, count($steps));

            $run = Task::internal('Seeder_Service', $method, $params);
            $summaries[] = $run->summary();
            $task->stdout($run->summary());
            $done++;
        }

        $task->progress_count($done, count($steps));
        $task->status('Done');
        $task->summary(implode(' ', $summaries));

        return null;
    }
}
