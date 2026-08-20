<?php

namespace Rsx\Lib\Notification;

use App\RSpade\Core\Rsx;
use Rsx\Models\Notification_Model;

/**
 * Notification_Renderer - Renders notifications as structured data
 *
 * Each method corresponds to a notification type and returns an array with:
 * - text: Human-readable notification text (plain text, no HTML)
 * - url: Optional URL to navigate to when clicked
 * - image_url: Optional image/avatar URL for display
 *
 * Methods are called via call_user_func() from Notification_Model::render().
 *
 * Downstream applications can create their own renderer classes for
 * custom notification types by storing the FQCN::method in the enum's
 * 'renderer' property.
 */
class Notification_Renderer
{
    /**
     * Default return structure for notifications
     *
     * @param string $text The notification text
     * @param string|null $url Optional navigation URL
     * @param string|null $image_url Optional image URL
     * @return array
     */
    protected static function result(string $text, ?string $url = null, ?string $image_url = null): array
    {
        return [
            'text' => $text,
            'url' => $url,
            'image_url' => $image_url,
        ];
    }

    /**
     * Get display name for the creator
     */
    protected static function creator_name(Notification_Model $n): string
    {
        $creator = $n->creator();
        return $creator ? $creator->email : 'Someone';
    }

    // =========================================================================
    // Client Notifications
    // =========================================================================

    public static function client_created(Notification_Model $n): array
    {
        $creator_name = static::creator_name($n);
        $client = $n->entity;

        if (!$client) {
            return static::result("{$creator_name} created a client (deleted)");
        }

        $url = Rsx::Route('Clients_View_Action', $client->id);
        $text = "{$creator_name} created client \"{$client->name}\"";

        return static::result($text, $url);
    }

    // =========================================================================
    // Contact Notifications
    // =========================================================================

    public static function contact_created(Notification_Model $n): array
    {
        $creator_name = static::creator_name($n);
        $contact = $n->entity;

        if (!$contact) {
            return static::result("{$creator_name} created a contact (deleted)");
        }

        $url = Rsx::Route('Contacts_View_Action', $contact->id);
        $name = trim($contact->first_name . ' ' . $contact->last_name);
        $text = "{$creator_name} created contact \"{$name}\"";

        return static::result($text, $url);
    }

    // =========================================================================
    // Project Notifications
    // =========================================================================

    public static function project_created(Notification_Model $n): array
    {
        $creator_name = static::creator_name($n);
        $project = $n->entity;

        if (!$project) {
            return static::result("{$creator_name} created a project (deleted)");
        }

        $url = Rsx::Route('Projects_View_Action', $project->id);
        $text = "{$creator_name} created project \"{$project->name}\"";

        return static::result($text, $url);
    }

    // =========================================================================
    // Task Notifications
    // =========================================================================

    public static function task_assigned(Notification_Model $n): array
    {
        $creator_name = static::creator_name($n);
        $task = $n->entity;
        $metadata = $n->get_metadata();

        // Get task title from entity or metadata
        $task_title = null;
        $url = null;

        if ($task) {
            $task_title = $task->title;
            // Tasks don't have a dedicated view page - link to project if available
            if ($task->project_id) {
                $url = Rsx::Route('Projects_View_Action', $task->project_id);
            }
        } elseif (!empty($metadata['task_title'])) {
            // Use metadata when entity not provided
            $task_title = $metadata['task_title'];
        }

        if (!$task_title) {
            return static::result("{$creator_name} assigned you a task");
        }

        $text = "{$creator_name} assigned you to task \"{$task_title}\"";

        return static::result($text, $url);
    }
}
