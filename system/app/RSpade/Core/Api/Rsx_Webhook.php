<?php

namespace App\RSpade\Core\Api;

/**
 * Rsx_Webhook - Webhook event firing system (stub)
 *
 * Infrastructure for webhook delivery. Currently logs events only.
 * Actual HTTP delivery to subscriber endpoints will be implemented
 * in a future phase with a webhook_subscriptions table and queue processing.
 *
 * Event naming convention: resource.action
 * Examples: contact.created, contact.updated, project.deleted
 *
 * Usage:
 *   Rsx_Webhook::fire('contact.created', ['id' => $contact->id, 'name' => $contact->full_name()]);
 *   Rsx_Webhook::fire('project.updated', $project->toArray());
 */
class Rsx_Webhook
{
    /**
     * Fire a webhook event
     *
     * Currently logs the event for development visibility.
     * Future: looks up subscribers and queues HTTP deliveries.
     *
     * @param string $event Event name (resource.action format)
     * @param array $data Event payload
     */
    public static function fire(string $event, array $data = []): void
    {
        console_debug('WEBHOOK', "Event fired: {$event}", $data);
    }
}
