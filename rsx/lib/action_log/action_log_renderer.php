<?php


namespace Rsx\Lib\ActionLog;

use App\RSpade\Core\Api\Api_Key_Event;
use App\RSpade\Core\Auth\Password_Change;
use App\RSpade\Core\Models\Login_User_Model;
use App\RSpade\Core\Rsx;
use App\RSpade\Lib\Portal\Portal_User_Model;
use Rsx\Models\Action_Log_Model;
use Rsx\Models\Client_Model;
use Rsx\Models\Project_Model;

/**
 * Action_Log_Renderer - Renders action log entries as human-readable HTML
 *
 * Each method corresponds to an action type and returns an HTML string
 * with hyperlinks. The methods are called via call_user_func() from
 * Action_Log_Model::render().
 *
 * Downstream applications can create their own renderer classes for
 * custom action types by storing the FQCN::method in the enum's
 * 'renderer' property.
 *
 * @see /rsx/resource/man/action_log.txt for full documentation
 */
class Action_Log_Renderer
{
    /**
     * Get actor display name
     */
    protected static function actor_name(Action_Log_Model $log): string
    {
        $actor = $log->actor;
        return $actor ? htmlspecialchars($actor->email) : 'System';
    }

    // =========================================================================
    // Client Actions
    // =========================================================================

    public static function client_created(Action_Log_Model $log): string
    {
        $actor_name = static::actor_name($log);
        $client = $log->subject;

        if (!$client) {
            return "{$actor_name} created a client (deleted)";
        }

        $link = Rsx::Route('Clients_View_Action', $client->id);
        $name = htmlspecialchars($client->name);

        return "{$actor_name} created client <a href=\"{$link}\">{$name}</a>";
    }

    public static function client_updated(Action_Log_Model $log): string
    {
        $actor_name = static::actor_name($log);
        $client = $log->subject;

        if (!$client) {
            return "{$actor_name} updated a client (deleted)";
        }

        $link = Rsx::Route('Clients_View_Action', $client->id);
        $name = htmlspecialchars($client->name);

        return "{$actor_name} updated client <a href=\"{$link}\">{$name}</a>";
    }

    public static function client_deleted(Action_Log_Model $log): string
    {
        $actor_name = static::actor_name($log);
        $client = $log->subject;

        // For deleted items, we may not have a valid link
        if (!$client) {
            return "{$actor_name} deleted a client";
        }

        $name = htmlspecialchars($client->name);

        return "{$actor_name} deleted client {$name}";
    }

    // =========================================================================
    // Contact Actions
    // =========================================================================

    public static function contact_created(Action_Log_Model $log): string
    {
        $actor_name = static::actor_name($log);
        $contact = $log->subject;

        if (!$contact) {
            return "{$actor_name} created a contact (deleted)";
        }

        $link = Rsx::Route('Contacts_View_Action', $contact->id);
        $name = htmlspecialchars($contact->first_name . ' ' . $contact->last_name);

        return "{$actor_name} created contact <a href=\"{$link}\">{$name}</a>";
    }

    public static function contact_updated(Action_Log_Model $log): string
    {
        $actor_name = static::actor_name($log);
        $contact = $log->subject;

        if (!$contact) {
            return "{$actor_name} updated a contact (deleted)";
        }

        $link = Rsx::Route('Contacts_View_Action', $contact->id);
        $name = htmlspecialchars($contact->first_name . ' ' . $contact->last_name);

        return "{$actor_name} updated contact <a href=\"{$link}\">{$name}</a>";
    }

    public static function contact_deleted(Action_Log_Model $log): string
    {
        $actor_name = static::actor_name($log);
        $contact = $log->subject;

        if (!$contact) {
            return "{$actor_name} deleted a contact";
        }

        $name = htmlspecialchars($contact->first_name . ' ' . $contact->last_name);

        return "{$actor_name} deleted contact {$name}";
    }

    // =========================================================================
    // Project Actions
    // =========================================================================

    public static function project_created(Action_Log_Model $log): string
    {
        $actor_name = static::actor_name($log);
        $project = $log->subject;

        if (!$project) {
            return "{$actor_name} created a project (deleted)";
        }

        $link = Rsx::Route('Projects_View_Action', $project->id);
        $name = htmlspecialchars($project->name);

        return "{$actor_name} created project <a href=\"{$link}\">{$name}</a>";
    }

    public static function project_updated(Action_Log_Model $log): string
    {
        $actor_name = static::actor_name($log);
        $project = $log->subject;

        if (!$project) {
            return "{$actor_name} updated a project (deleted)";
        }

        $link = Rsx::Route('Projects_View_Action', $project->id);
        $name = htmlspecialchars($project->name);

        return "{$actor_name} updated project <a href=\"{$link}\">{$name}</a>";
    }

    public static function project_deleted(Action_Log_Model $log): string
    {
        $actor_name = static::actor_name($log);
        $project = $log->subject;

        if (!$project) {
            return "{$actor_name} deleted a project";
        }

        $name = htmlspecialchars($project->name);

        return "{$actor_name} deleted project {$name}";
    }

    // =========================================================================
    // Task Actions
    // =========================================================================

    public static function task_created(Action_Log_Model $log): string
    {
        $actor_name = static::actor_name($log);
        $task = $log->subject;

        if (!$task) {
            return "{$actor_name} created a task (deleted)";
        }

        // Tasks don't have a dedicated view page, just show the title
        $title = htmlspecialchars($task->title);

        return "{$actor_name} created task \"{$title}\"";
    }

    public static function task_updated(Action_Log_Model $log): string
    {
        $actor_name = static::actor_name($log);
        $task = $log->subject;

        if (!$task) {
            return "{$actor_name} updated a task (deleted)";
        }

        $title = htmlspecialchars($task->title);

        return "{$actor_name} updated task \"{$title}\"";
    }

    public static function task_deleted(Action_Log_Model $log): string
    {
        $actor_name = static::actor_name($log);
        $task = $log->subject;

        if (!$task) {
            return "{$actor_name} deleted a task";
        }

        $title = htmlspecialchars($task->title);

        return "{$actor_name} deleted task \"{$title}\"";
    }

    // =========================================================================
    // Billable Entries (60-69)
    //
    // An entry is named by its kind and its description, linked to the entry's view page,
    // followed by a link to its project.
    // =========================================================================

    public static function billable_entry_created(Action_Log_Model $log): string
    {
        return static::__entry_line($log, 'logged', 'logged an entry (deleted)');
    }

    public static function billable_entry_updated(Action_Log_Model $log): string
    {
        return static::__entry_line($log, 'updated', 'updated an entry (deleted)');
    }

    public static function billable_entry_deleted(Action_Log_Model $log): string
    {
        return static::__entry_line($log, 'deleted', 'deleted an entry');
    }

    public static function billable_entry_submitted(Action_Log_Model $log): string
    {
        return static::__entry_line($log, 'submitted', 'submitted an entry (deleted)');
    }

    public static function billable_entry_approved(Action_Log_Model $log): string
    {
        return static::__entry_line($log, 'approved', 'approved an entry (deleted)');
    }

    public static function billable_entry_rejected(Action_Log_Model $log): string
    {
        $line = static::__entry_line($log, 'rejected', 'rejected an entry (deleted)');
        $reason = $log->get_metadata()['reason'] ?? null;

        return $reason !== null && $reason !== ''
            ? $line . ': ' . htmlspecialchars((string) $reason)
            : $line;
    }

    /**
     * "{actor} {verb} time entry "<a>Design review</a>" on project <a>Website</a>". A deleted
     * entry (the subject reads as null) renders $missing after the actor.
     */
    protected static function __entry_line(Action_Log_Model $log, string $verb, string $missing): string
    {
        $actor_name = static::actor_name($log);
        $entry = $log->subject;

        if (!$entry) {
            return "{$actor_name} {$missing}";
        }

        $kind = htmlspecialchars(strtolower($entry->type_id__label));
        $description = htmlspecialchars($entry->description);
        $entry_link = Rsx::Route('Billing_View_Action', $entry->id);
        $line = "{$actor_name} {$verb} {$kind} entry \"<a href=\"{$entry_link}\">{$description}</a>\"";

        // Looked up, never read as $entry->project: a loaded relation would ride the
        // subject into the action log's payload and collide with the JS model's project().
        $project = Project_Model::find($entry->project_id);
        if ($project) {
            $link = Rsx::Route('Projects_View_Action', $project->id);
            $line .= ' on project <a href="' . $link . '">' . htmlspecialchars($project->name) . '</a>';
        }

        return $line;
    }

    // =========================================================================
    // Invoices (70-79)
    //
    // An invoice is named by its number, linked to the invoice's view page, followed by a link
    // to its client.
    // =========================================================================

    public static function invoice_created(Action_Log_Model $log): string
    {
        return static::__invoice_line($log, 'created', 'created an invoice (deleted)');
    }

    public static function invoice_issued(Action_Log_Model $log): string
    {
        return static::__invoice_line($log, 'issued', 'issued an invoice (deleted)');
    }

    public static function invoice_paid(Action_Log_Model $log): string
    {
        return static::__invoice_line($log, 'marked paid', 'marked an invoice paid (deleted)');
    }

    public static function invoice_voided(Action_Log_Model $log): string
    {
        return static::__invoice_line($log, 'voided', 'voided an invoice (deleted)');
    }

    /** "{actor} {verb} invoice <a>INV-0001</a> for client <a>Acme</a>". */
    protected static function __invoice_line(Action_Log_Model $log, string $verb, string $missing): string
    {
        $actor_name = static::actor_name($log);
        $invoice = $log->subject;

        if (!$invoice) {
            return "{$actor_name} {$missing}";
        }

        $invoice_link = Rsx::Route('Invoices_View_Action', $invoice->id);
        $line = "{$actor_name} {$verb} invoice <a href=\"{$invoice_link}\">" . htmlspecialchars($invoice->invoice_number_formatted()) . '</a>';

        // Looked up, never read as $invoice->client: a loaded relation would ride the
        // subject into the action log's payload and collide with the JS model's client().
        $client = Client_Model::find($invoice->client_id);
        if ($client) {
            $link = Rsx::Route('Clients_View_Action', $client->id);
            $line .= ' for client <a href="' . $link . '">' . htmlspecialchars($client->name) . '</a>';
        }

        return $line;
    }

    // =========================================================================
    // Account Security (written by Password_Event_Handlers)
    // =========================================================================

    /**
     * A password change. metadata.cause is the framework's Password_Change::CAUSE_* value;
     * the subject is the affected person's membership of this site (User_Model) or a portal
     * user (Portal_User_Model).
     */
    public static function password_changed(Action_Log_Model $log): string
    {
        $subject = static::account_name($log);
        $actor = $log->actor;
        $by_subject = $actor !== null && static::is_subject_identity($log, $actor);

        $line = match ($log->get_metadata()['cause'] ?? null) {
            Password_Change::CAUSE_SELF => $by_subject
                ? "{$subject} changed their password"
                : static::actor_name($log) . " changed the password of {$subject}",
            Password_Change::CAUSE_RESET_LINK => "{$subject} reset their password from an emailed link",
            Password_Change::CAUSE_ADMIN => static::actor_name($log) . " set a temporary password for {$subject}",
            Password_Change::CAUSE_FORCED_CHANGE => "{$subject} chose a new password at sign-in",
            Password_Change::CAUSE_CLI => "The password of {$subject} was set from the command line",
            default => "The password of {$subject} was changed",
        };

        return $line . static::from_address($log);
    }

    public static function password_reset_requested(Action_Log_Model $log): string
    {
        return static::account_name($log) . ' requested a password reset link' . static::from_address($log);
    }

    /**
     * An API key minted on a user's account (written by Api_Key_Event_Handlers). The subject is
     * the holder's membership; metadata names the key, since the key may be deleted later. A
     * null actor is the command line.
     */
    public static function api_key_created(Action_Log_Model $log): string
    {
        $subject = static::account_name($log);
        $key = static::api_key_name($log);
        $actor = $log->actor;

        $line = match (true) {
            $actor === null => "API key {$key} was created for {$subject} from the command line",
            static::is_subject_identity($log, $actor) => "{$subject} created API key {$key}",
            default => static::actor_name($log) . " created API key {$key} for {$subject}",
        };

        return $line . static::from_address($log);
    }

    /**
     * A live API key ended (written by Api_Key_Event_Handlers): metadata.how is revoked, or
     * deleted for a key whose row was removed.
     */
    public static function api_key_revoked(Action_Log_Model $log): string
    {
        $subject = static::account_name($log);
        $key = static::api_key_name($log);
        $actor = $log->actor;
        $verb = ($log->get_metadata()['how'] ?? null) === Api_Key_Event::HOW_DELETED ? 'deleted' : 'revoked';

        $line = match (true) {
            $actor === null => "API key {$key} of {$subject} was {$verb} from the command line",
            static::is_subject_identity($log, $actor) => "{$subject} {$verb} their API key {$key}",
            default => static::actor_name($log) . " {$verb} API key {$key} of {$subject}",
        };

        return $line . static::from_address($log);
    }

    /**
     * "Nightly export" (rsx_live_AbCd...) from an API key row's metadata.
     */
    protected static function api_key_name(Action_Log_Model $log): string
    {
        $metadata = $log->get_metadata();

        return '"' . htmlspecialchars((string) ($metadata['name'] ?? '')) . '" ('
            . htmlspecialchars((string) ($metadata['key_prefix'] ?? '')) . ')';
    }

    /**
     * The account an account-security row is about: a link to the user's page for a staff
     * membership, the address for a portal user.
     */
    protected static function account_name(Action_Log_Model $log): string
    {
        $subject = $log->subject;

        if (!$subject) {
            return 'a user (deleted)';
        }

        if ($subject instanceof Portal_User_Model) {
            return 'portal user ' . htmlspecialchars((string) $subject->email);
        }

        $name = trim($subject->first_name . ' ' . $subject->last_name) ?: (string) $subject->email;
        $link = Rsx::Route('Settings_User_Management_View_Action', $subject->id);

        return '<a href="' . $link . '">' . htmlspecialchars($name) . '</a>';
    }

    /**
     * Is the actor the identity the row is about (a person acting on their own account)?
     */
    protected static function is_subject_identity(Action_Log_Model $log, object $actor): bool
    {
        $subject = $log->subject;

        if (!$subject) {
            return false;
        }

        if ($subject instanceof Portal_User_Model) {
            return $actor instanceof Portal_User_Model && (int) $actor->id === (int) $subject->id;
        }

        return $actor instanceof Login_User_Model && (int) $actor->id === (int) $subject->login_user_id;
    }

    /**
     * " - from 203.0.113.7" when the row's audit session recorded an address (never when the
     * act came from a command or a task, which have none).
     */
    protected static function from_address(Action_Log_Model $log): string
    {
        $ip_address = $log->audit_ip_address();

        return $ip_address === null ? '' : ' from ' . htmlspecialchars($ip_address);
    }
}
