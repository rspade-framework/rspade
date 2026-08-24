---
name: action-log-and-notifications
description: Recording activity and notifying users in this application - Action_Log::record() with related entities and metadata, Action_Log_Renderer, the Feed_Row activity tabs via Activity_Feed.decorate(), Notification::send() / get_unread_count() / get_for_dropdown() with Notification_Renderer, and the separate realtime Portal_Notification_Model::emit() for portal users. Use when asked to "log an action", "record who did what", "show activity history", "notify a user", "unread count", when adding a TYPE_* to Action_Log_Model or Notification_Model, or when a notification vanishes on fetch.
---

# Action log and notifications

Two app-owned primitives, deliberately separate. **Contracts:**
`php artisan rsx:man action_log` and `php artisan rsx:man notification` - read them for
the full API and enum tables. This skill is the how-to layer over them.

| | Action log | Notification |
|---|---|---|
| Answers | "what did people DO here" | "what do I need to see" |
| Rows | one per action | one per RECIPIENT per event |
| Renderer returns | an HTML string with links | `{text, url, image_url}` |
| Read by | activity tabs, `action_logs` module | the header bell (`Notification_Dropdown`) |
| Files | `rsx/models/action_log_model.php`, `action_log_related_model.php`, `rsx/lib/action_log/` | `rsx/models/notification_model.php`, `rsx/lib/notification/` |

Both models are `$unbounded = true` (they grow with customer activity).

## Recording an action

**In the controller's POST handler, after the write succeeds** - never in a model
event. The frame is "a user performed an action", not "a row changed" (audit
authorship is already stamped automatically on every save).

```php
use Rsx\Lib\ActionLog\Action_Log;          // note: ActionLog, one word
use Rsx\Models\Action_Log_Model;
use Rsx\Models\Action_Log_Related_Model;

$client->save();
Action_Log::record(
    $is_new ? Action_Log_Model::TYPE_CLIENT_CREATED : Action_Log_Model::TYPE_CLIENT_UPDATED,
    $client,                                                  // the SUBJECT
    [[$parent_client, Action_Log_Related_Model::ROLE_MENTIONED]],  // optional related
    ['changed_fields' => ['status_id', 'due_date']]           // optional metadata
);
```

- The actor comes from the session automatically (`Session::get_login_user()`); a
  system action logs with no actor and renders as "System".
- **`site_id` is stamped from the session** - never pass it.
- Subject and related are polymorphic pairs written with `class_basename()` (simple
  names, never FQCNs).
- **Related entities exist so you can query by involvement**: `ROLE_ACTOR`,
  `ROLE_TARGET`, `ROLE_MENTIONED`. Add one when "show me everything involving X"
  should surface an action X is not the subject of.
- **Metadata is for the detail view**, not the list line - the rendered summary must
  read correctly without it.
- Read it back with `Action_Log::get_for_entity($entity, $limit)` (subject OR related).

Live examples: `rsx/app/frontend/clients/clients_controller.php`,
`tasks_controller.php`, `party_controller.php`.

## Adding an action type

1. Add the integer to `Action_Log_Model`'s `$enums['type_id']` with `constant`,
   `label`, and a `renderer` of `FQCN::method`. **Keep the range convention**:
   client 1-9, contact 10-19, project 20-29, task 30-39, party 40-49 - the activity
   feed's icon map keys off those ranges.
2. Write the renderer as a static method on `Rsx\Lib\ActionLog\Action_Log_Renderer`
   taking the `Action_Log_Model` and returning an HTML string: `htmlspecialchars()`
   every user value, `Rsx::Route()` every URL, and **handle a deleted subject
   gracefully** ("... a client (deleted)") - the subject may be gone.
3. `php artisan rsx:constants:regenerate` after changing an enum.
4. If the new range is outside the existing ones, extend
   `Activity_Feed.decorate()` in `rsx/lib/action_log/activity_feed.js` AND
   `Frontend_Dashboard_Controller::_activity_icon()` - they are deliberate twins
   (server-side for the dashboard, client-side for entity Activity tabs) so every
   feed decorates identically.

## Displaying activity

`Action_Log_Model::render()` emits the linked summary HTML. An Activity tab fetches
`{id, html, created_at, type_id}` and decorates it:

```javascript
this.data.activity = result.activity.map(a => ({ ...a, ...Activity_Feed.decorate(a.type_id) }));
```

```html
<Feed_Row $icon=a.icon $variant=a.variant> <%!= a.html %> </Feed_Row>
```

`Feed_Row` is self-dividing: stack rows inside `<Section $flush=true>`. The summary is
rendered UNESCAPED (`<%!=`) because the renderer authored the links - which is exactly
why the renderer must escape its own inputs.

## Notifications (staff)

```php
use Rsx\Lib\Notification\Notification;
use Rsx\Models\Notification_Model;

Notification::send(
    Notification_Model::TYPE_PROJECT_CREATED,
    $login_user_ids,     // one row is written PER recipient
    $project,            // optional entity reference
    ['note' => '...']    // optional metadata
);
```

- **You choose the recipients.** There is no subscription model - the calling code
  decides who cares (typically site users minus the actor).
- `Notification::get_unread_count()`, `get_for_dropdown($limit)`, `mark_read($id)`,
  `mark_all_read()`, `expire_old()`. Expiry defaults to 21 days
  (`rsx.notifications.default_expiry_days`) and is run opportunistically behind
  `Rsx_Throttle` on `get_count()`.
- **Self-policing:** a notification whose referenced entity no longer exists is
  DELETED when fetched. That is the answer to "my notification disappeared" - not a
  bug. Use metadata instead of an entity when the notification must outlive its
  subject.
- Adding a type mirrors the action log: `$enums['type_id']` + a
  `Notification_Renderer` static returning `['text' => ..., 'url' => ..., 'image_url' => ...]`,
  handling a missing entity.
- UI: `<Notification_Dropdown>` is already in `Frontend_Spa_Layout`. It is **NOT
  realtime** - it refreshes its badge on `spa:dispatch` and on demand via
  `Frontend_Notifications_Controller` (`get_count`, `get_dropdown`, `mark_read`,
  `mark_all_read`).
- **The template ships no live `Notification::send()` caller** (only the worked
  example commented in `contacts_controller.php`). Yours will be the first - follow the
  Action_Log call sites for placement.

## Portal notifications are a DIFFERENT system

Portal users are notified through the framework's `Portal_Notification_Model::emit()`,
not `Notification::send()`:

```php
Portal_Notification_Model::emit($recipient_ids, Portal_Request_Thread_Model::NOTIFICATION_TYPE_REPLY, [...]);
```

That one IS realtime: it publishes per recipient on `Rsx\Lib\Topics\Portal_Notification_Topic`,
whose `can_subscribe()` authorizes ONLY the owning portal user (fail-closed, filtered
by `portal_user_id`). The frame is notification-only - the browser refetches its feed
through `rsx/portal/notifications/portal_notifications_controller.php`. Call sites:
`clients_controller.php` (document shared, thread reply/status/decision) and
`Announcement_Model`.

**Do not cross the wires**: staff bell = `Notification`, portal feed =
`Portal_Notification_Model`.
