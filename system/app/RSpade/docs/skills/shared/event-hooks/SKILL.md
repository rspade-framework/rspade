---
name: event-hooks
description: The RSpade PHP event system - #[OnEvent] handlers discovered by the manifest, and the four trigger kinds (action, filter, gate, resolve) with their exact return contracts. Use when extending framework behavior at a documented hook, registering the mandatory file-upload gate, transforming data through a chain, intercepting a framework default with trigger_resolve, or firing your own application events.
---

# Event Hooks

Attribute-based hooks discovered by the manifest - **no registration, no service provider**. A handler is a public static method marked `#[OnEvent]`, living in a class under `/rsx/handlers/`.

```php
namespace Rsx\Handlers;

class Upload_Handlers
{
    #[OnEvent('file.upload.authorize', priority: 10)]
    public static function require_auth($payload)
    {
        return Session::is_logged_in() ? true : response_unauthorized('Login required');
    }
}
```

Lower `priority` runs first; the default is 10. Organize by concern - `auth_handlers.php`, `file_handlers.php`, `notification_handlers.php`.

---

## The four trigger kinds

| Trigger | Handler returns | Chain stops when | Use for |
|---|---|---|---|
| `Rsx::trigger_action($event, $data)` | nothing (ignored) | never - all handlers run | side effects: logging, notifications, kicking a task |
| `Rsx::trigger_filter($event, $data)` | the (modified) data | never - each handler feeds the next | transforming a value |
| `Rsx::trigger_gate($event, $data)` | `true` to permit | first NON-`true` return, which is the result | authorization / veto |
| `Rsx::trigger_resolve($event, $data)` | a result, or `null` to decline | first NON-null return | intercepting a framework default |

**Handlers run INLINE, inside the request.** Hand heavy work to `Task::dispatch()` - a handler that talks to a slow external service makes every request that fires the event slow.

### Action

```php
Rsx::trigger_action('project.deleted', ['project' => $project]);

#[OnEvent('project.deleted')]
public static function notify_watchers($data): void
{
    Task::dispatch('Notification_Service', 'project_deleted', ['id' => $data['project']->id]);
}
```

### Filter

```php
$params = Rsx::trigger_filter('file.upload.params', $params);

#[OnEvent('file.upload.params', priority: 20)]
public static function stamp_owner($params)
{
    $params['uploaded_by'] = Session::get_user_id();
    return $params;                    // a filter handler MUST return the data
}
```

### Gate - and the default-open convention

**The framework convention is that `true` permits.** The first handler returning anything other than `true` denies, and that return value is what the caller gets back (so return a response, not just `false`, when the caller will forward it).

```php
$auth = Rsx::trigger_gate('project.delete.authorize', ['project' => $project]);
if ($auth !== true) { return $auth; }
```

**A gate with NO handlers returns `true` - open.** That is deliberate: an unhooked extension point must not break the framework. **The consequence is that a gate is never a substitute for an `#[Auth]` declaration**, and a surface that must be CLOSED when nobody hooked it has to check for itself:

```php
if (!Event_Registry::has_handlers('file.upload.authorize')) {
    throw new \RuntimeException('File uploads are disabled: no ... gate handler is registered');
}
```

That is exactly what `POST /_upload` does - an unhandled upload gate would be an anonymous upload endpoint, so it throws instead. **Every app ships a `file.upload.authorize` handler** (minimum: require login); see `rspade:file-attachments`.

### Resolve - intercept a framework default

`trigger_resolve()` walks handlers until one returns non-null; `null` means "declined, not mine". If every handler declines, the framework runs its own terminal default.

```php
#[OnEvent('document.extract_text')]
public static function extract_special($data)
{
    if ($data['mime'] !== 'application/x-special') { return null; }   // decline
    return Special_Lib::to_text($data['path']);                       // intercept
}
```

The three document chains (`document.extract_text`, `document.preview_rendition`, `document.thumbnail_render`) are the canonical resolve hooks - see `rspade:document-preview`.

---

## Framework-fired events worth knowing

- **Manifest lifecycle**: `rsx.rebuilt`, then the mode variant `rsx.rebuilt.dev` | `rsx.rebuilt.prod` (both only when THIS process rebuilt), then **`rsx.ready`** on every boot - all fired at the end of `Manifest::init()`. In development the rebuild happens on the first request after a source change; in production it is the deploy-time build. Handlers run inline at boot, so keep them tiny.
- **`rsx.post_dispatch`** - fired by every dispatch seam immediately after a handler returns, with `{request, params, result}`. It runs inline, may throw, and must NOT mutate `result`. This is what backs Turnstile's completeness guard (a form that submitted `__turnstile` without calling `Rsx_Turnstile::validate()` throws here) - see `rspade:turnstile`.
- **`session.terminated`** - `{actor_login_user_id, target_login_user_id, session_id, scope}`; see `rspade:session-auth`.
- **File attachment disposal**: the `file.attachment.destroy.hold` gate + the `file.attachment.destroyed` action.

The complete catalog of framework events, with payload shapes, is `php artisan rsx:man event_hooks`.

---

## Firing your own events

An endpoint that fires events still declares its own gate - **`#[Auth]` is mandatory on every dispatchable surface, and an event gate is not one**:

```php
#[Ajax_Endpoint]
#[Auth('can_manage_projects')]
public static function delete(Request $request, array $params = [])
{
    $project = Project_Model::find($params['id']);

    $auth = Rsx::trigger_gate('project.delete.authorize', ['project' => $project]);
    if ($auth !== true) { return $auth; }

    $project->delete();
    Rsx::trigger_action('project.deleted', ['project' => $project]);
    return ['success' => true];
}
```

Name events `subject.verb` (`project.deleted`) or `subject.verb.aspect` (`file.upload.authorize`), and keep the payload an array so it can grow without breaking handlers.

---

## Troubleshooting

- **A handler never fires.** It must be `public static`, inside a class the manifest indexes (`/rsx/handlers/`), and the event name must match exactly. Handlers are discovered, not registered - a typo is silent.
- **A filter drops the data.** A filter handler that returns nothing returns `null` into the chain. Always return the data.
- **A gate "passes" with no handlers installed.** By design - default open. Check `Event_Registry::has_handlers()` if it must fail closed.
- **A resolve handler swallowed everything.** Returning a falsy-but-non-null value (`false`, `''`, `0`) counts as an interception; return `null` to decline.
- **Requests got slow after adding a handler.** Handlers are inline; move the work into `Task::dispatch()`.

Details: `php artisan rsx:man event_hooks`. Related: `rspade:background-tasks`, `rspade:file-attachments`, `rspade:turnstile`.
