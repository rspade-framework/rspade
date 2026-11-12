<?php


namespace Rsx\Lib\ActionLog;

use App\RSpade\Core\Session\Session;
use Rsx\Models\Action_Log_Model;
use Rsx\Models\Action_Log_Related_Model;

/**
 * Action_Log - Helper class for recording user actions
 *
 * This class provides a simple API for creating action log entries.
 * Use this in controller POST handlers to record what actions users performed.
 *
 * @example
 * // Basic usage - record a simple action
 * Action_Log::record(Action_Log_Model::TYPE_CLIENT_CREATED, $client);
 *
 * // With related entities
 * Action_Log::record(
 *     Action_Log_Model::TYPE_CONTACT_CREATED,
 *     $contact,
 *     [
 *         [$client, Action_Log_Related_Model::ROLE_MENTIONED],
 *     ]
 * );
 *
 * // With metadata
 * Action_Log::record(
 *     Action_Log_Model::TYPE_PROJECT_UPDATED,
 *     $project,
 *     [],
 *     ['changed_fields' => ['status', 'due_date']]
 * );
 *
 * @see /rsx/resource/man/action_log.txt for full documentation
 */
class Action_Log
{
    /**
     * Record an action
     *
     * WHO and FROM WHERE default to the current session: the signed-in identity is the actor
     * and Session::get_audit_session_id() the acting context (the address, the user agent, an
     * impersonator - read back through Action_Log_Model::audit_ip_address(), never copied
     * onto the row). Code recording an act it was HANDED rather than one its own request is
     * doing - a task writing what an event reported - passes both explicitly; the session it
     * runs in names nobody.
     *
     * @param int $type Action_Log_Model::TYPE_* constant
     * @param object $subject The primary entity (model instance) that was acted upon
     * @param array $related Optional related entities as [[model, role_id], ...]
     * @param array $metadata Optional additional data for the action
     * @param object|null $actor Who did it (Login_User_Model, Portal_User_Model); null = the
     *        signed-in identity
     * @param int|null $audit_session_id Where it was done from; null = the current one
     * @return Action_Log_Model The created action log entry
     */
    public static function record(
        int $type,
        object $subject,
        array $related = [],
        array $metadata = [],
        ?object $actor = null,
        ?int $audit_session_id = null
    ): Action_Log_Model {
        $actor = $actor ?? Session::get_login_user();

        // Create the action log entry
        $log = new Action_Log_Model();
        $log->site_id = Session::get_site_id();
        $log->type_id = $type;
        $log->audit_session_id = $audit_session_id ?? Session::get_audit_session_id();

        // Set actor (polymorphic) - null for system actions
        if ($actor) {
            $log->actor_type = class_basename($actor);
            $log->actor_id = $actor->id;
        }

        // Set subject (polymorphic) - the entity being acted upon
        $log->subject_type = class_basename($subject);
        $log->subject_id = $subject->id;

        // Set metadata if provided
        if (!empty($metadata)) {
            $log->metadata = json_encode($metadata);
        }

        $log->save();

        // Create related entity records
        foreach ($related as $related_entry) {
            if (!is_array($related_entry) || count($related_entry) < 2) {
                continue;
            }

            [$related_model, $role_id] = $related_entry;

            $related_record = new Action_Log_Related_Model();
            $related_record->action_log_id = $log->id;
            $related_record->role_id = $role_id;
            $related_record->related_type = class_basename($related_model);
            $related_record->related_id = $related_model->id;
            $related_record->save();
        }

        return $log;
    }

    /**
     * Get action logs for a specific entity
     *
     * @param object $entity The entity to get logs for
     * @param int|null $limit Maximum number of logs to return (null for all)
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public static function get_for_entity(object $entity, ?int $limit = null)
    {
        $entity_type = class_basename($entity);
        $entity_id = $entity->id;

        $query = Action_Log_Model::where(function ($q) use ($entity_type, $entity_id) {
            // Entity is the subject
            $q->where('subject_type', $entity_type)
              ->where('subject_id', $entity_id);
        })->orWhereHas('related_entries', function ($q) use ($entity_type, $entity_id) {
            // Entity is a related entry
            $q->where('related_type', $entity_type)
              ->where('related_id', $entity_id);
        })
        ->orderBy('created_at', 'desc');

        if ($limit !== null) {
            $query->limit($limit);
        }

        return $query->get();
    }
}
