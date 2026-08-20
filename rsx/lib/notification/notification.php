<?php

namespace Rsx\Lib\Notification;

use App\RSpade\Core\Session\Session;
use Rsx\Models\Notification_Model;

/**
 * Notification - Helper class for sending and managing user notifications
 *
 * Provides a simple API for creating notifications and retrieving them.
 * Notifications can optionally reference a primary entity (polymorphic).
 *
 * @example
 * // With entity reference - notification links to the entity
 * Notification::send(
 *     Notification_Model::TYPE_PROJECT_CREATED,
 *     [$user1->id, $user2->id],
 *     $project
 * );
 *
 * // Without entity - metadata only (entity is optional)
 * Notification::send(
 *     Notification_Model::TYPE_TASK_ASSIGNED,
 *     [$assignee->id],
 *     null,
 *     ['task_title' => $task->title, 'project_name' => $project->name]
 * );
 *
 * // Get unread count for current user
 * $count = Notification::get_unread_count();
 *
 * // Get notifications for dropdown
 * $result = Notification::get_for_dropdown(5);
 * // $result = ['notifications' => [...], 'total' => 12, 'unread' => 3]
 */
class Notification
{
    /**
     * Send notifications to multiple users
     *
     * Creates one notification record per recipient user.
     *
     * @param int $type Notification_Model::TYPE_* constant
     * @param array $user_ids Array of login_user IDs to notify
     * @param object|null $entity Optional entity the notification references
     * @param array $metadata Optional additional data
     * @return array Array of created Notification_Model instances
     */
    public static function send(
        int $type,
        array $user_ids,
        ?object $entity = null,
        array $metadata = []
    ): array {
        $site_id = Session::get_site_id();
        $expiry_days = config('rsx.notifications.default_expiry_days', 21);
        $expires_at = now()->addDays($expiry_days);

        $notifications = [];

        foreach ($user_ids as $user_id) {
            $notification = new Notification_Model();
            $notification->site_id = $site_id;
            $notification->user_id = $user_id;
            $notification->type_id = $type;

            // Set entity (polymorphic) if provided
            if ($entity) {
                $notification->entity_type = class_basename($entity);
                $notification->entity_id = $entity->id;
            }

            // Set metadata if provided
            if (!empty($metadata)) {
                $notification->metadata = json_encode($metadata);
            }

            $notification->expires_at = $expires_at;
            // Authorship is stamped by save() - see the audit columns in CLAUDE.md.
            $notification->save();

            $notifications[] = $notification;
        }

        return $notifications;
    }

    /**
     * Get unread notification count for current user
     *
     * @return int
     */
    public static function get_unread_count(): int
    {
        $user_id = Session::get_user_id();
        if (!$user_id) {
            return 0;
        }

        return Notification_Model::where('site_id', Session::get_site_id())
            ->where('user_id', $user_id)
            ->whereNull('read_at')
            ->where('expires_at', '>', now())
            ->count();
    }

    /**
     * Get notifications for dropdown display
     *
     * Self-polices: validates entities and deletes invalid notifications.
     * Returns structured array with notifications, total count, and unread count.
     *
     * @param int $limit Maximum notifications to return for display
     * @return array ['notifications' => [...], 'total' => int, 'unread' => int]
     */
    public static function get_for_dropdown(int $limit = 5): array
    {
        $user_id = Session::get_user_id();
        if (!$user_id) {
            return ['notifications' => [], 'total' => 0, 'unread' => 0];
        }

        $site_id = Session::get_site_id();

        // Get all unexpired notifications for this user (for self-policing)
        $all_notifications = Notification_Model::where('site_id', $site_id)
            ->where('user_id', $user_id)
            ->where('expires_at', '>', now())
            ->orderBy('created_at', 'desc')
            ->get();

        // Self-police: validate and delete invalid notifications
        $valid_notifications = [];
        foreach ($all_notifications as $notification) {
            if ($notification->is_valid()) {
                $valid_notifications[] = $notification;
            } else {
                $notification->delete();
            }
        }

        // Count unread among valid
        $unread_count = 0;
        foreach ($valid_notifications as $notification) {
            if (!$notification->is_read()) {
                $unread_count++;
            }
        }

        // Take only the requested limit for display
        $display_notifications = array_slice($valid_notifications, 0, $limit);

        // Render each notification for display
        $rendered = [];
        foreach ($display_notifications as $notification) {
            $render_data = $notification->render();
            $rendered[] = [
                'id' => $notification->id,
                'type_id' => $notification->type_id,
                'text' => $render_data['text'],
                'url' => $render_data['url'] ?? null,
                'image_url' => $render_data['image_url'] ?? null,
                'is_read' => $notification->is_read(),
                'created_at' => $notification->created_at,
            ];
        }

        return [
            'notifications' => $rendered,
            'total' => count($valid_notifications),
            'unread' => $unread_count,
        ];
    }

    /**
     * Mark all notifications as read for current user
     *
     * @return int Number of notifications marked as read
     */
    public static function mark_all_read(): int
    {
        $user_id = Session::get_user_id();
        if (!$user_id) {
            return 0;
        }

        return Notification_Model::where('site_id', Session::get_site_id())
            ->where('user_id', $user_id)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);
    }

    /**
     * Mark a single notification as read
     *
     * @param int $notification_id
     * @return bool True if marked, false if not found or not owned by user
     */
    public static function mark_read(int $notification_id): bool
    {
        $user_id = Session::get_user_id();
        if (!$user_id) {
            return false;
        }

        $notification = Notification_Model::where('site_id', Session::get_site_id())
            ->where('user_id', $user_id)
            ->where('id', $notification_id)
            ->first();

        if (!$notification) {
            return false;
        }

        $notification->mark_read();
        return true;
    }

    /**
     * Expire (delete) old notifications
     *
     * Call this periodically (e.g., via Rsx_Throttle) to clean up expired notifications.
     *
     * @return int Number of notifications deleted
     */
    public static function expire_old(): int
    {
        return Notification_Model::where('site_id', Session::get_site_id())
            ->where('expires_at', '<', now())
            ->delete();
    }
}
