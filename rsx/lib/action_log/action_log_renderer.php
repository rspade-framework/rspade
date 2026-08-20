<?php


namespace Rsx\Lib\ActionLog;

use App\RSpade\Core\Rsx;
use Rsx\Models\Action_Log_Model;

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
    // Party Actions
    // =========================================================================

    public static function party_created(Action_Log_Model $log): string
    {
        $actor_name = static::actor_name($log);
        $party = $log->subject;

        if (!$party) {
            return "{$actor_name} created a party (deleted)";
        }

        $link = Rsx::Route('Party_View_Action', $party->id);
        $name = htmlspecialchars($party->name);

        return "{$actor_name} created party <a href=\"{$link}\">{$name}</a>";
    }

    public static function party_updated(Action_Log_Model $log): string
    {
        $actor_name = static::actor_name($log);
        $party = $log->subject;

        if (!$party) {
            return "{$actor_name} updated a party (deleted)";
        }

        $link = Rsx::Route('Party_View_Action', $party->id);
        $name = htmlspecialchars($party->name);

        return "{$actor_name} updated party <a href=\"{$link}\">{$name}</a>";
    }

    public static function party_deleted(Action_Log_Model $log): string
    {
        $actor_name = static::actor_name($log);
        $party = $log->subject;

        if (!$party) {
            return "{$actor_name} deleted a party";
        }

        $name = htmlspecialchars($party->name);

        return "{$actor_name} deleted party {$name}";
    }
}
