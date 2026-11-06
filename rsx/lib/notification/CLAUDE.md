# rsx/lib/notification — staff notifications behind the header bell

## WHAT IS HERE

- `notification.php` — `Notification`, the whole API:
  `send(int $type, array $user_ids, ?object $entity = null, array $metadata = [])`,
  `get_unread_count()`, `get_for_dropdown(int $limit = 5)`, `mark_read($id)`,
  `mark_all_read()`.
- `notification_renderer.php` — `Notification_Renderer`, one `public static` method per
  type returning `{text, url, image_url}`. Four exist, matching the four shipped types:
  `client_created`, `contact_created`, `project_created`, `task_assigned`. The text is
  PLAIN — unlike the action-log renderer, which returns HTML.

## HOW IT IS USED

**The recipient is a LOGIN identity**: `notifications.user_id` holds a `login_users.id`, and
`site_id` scopes the row to one site. `send()` is given login-user ids (`$user->login_user_id`,
never `$user->id`), every read in `Notification` matches `Session::get_login_user_id()`, and
`Notification_Model::fetch()` answers any other caller as a missing row.

`send()` writes one **`Notification_Model`** row per recipient, carrying site, user, type,
an optional polymorphic entity and an expiry (`rsx.notifications.default_expiry_days`,
21 by default) - a DISPLAY expiry: an expired row stops being shown, and is not deleted.
Nothing here touches the portal's notifications — those are a separate
framework model, published on `Portal_Notification_Topic` and read by the portal dashboard.

**Reads skip invalid notifications and delete nothing**: `get_for_dropdown()` loads the
unexpired rows, leaves out any whose entity no longer exists, counts the unread among the
rest, and only then slices to the limit and renders; `get_unread_count()` applies the same
validity test, so the badge and the list agree. A notification pointing at a deleted record
disappears rather than rendering a dead link. Both run in reader requests (the site's read
lock), so nothing here deletes. Rows are deleted only by the nightly maintenance task
`Notification_Service::purge` (`rsx/services/`): a SEEN notification older than
`rsx.notifications.purge_seen_after_days` days (90) from `created_at`; an unseen one never,
and `0` disables the purge.

**Rendered by `Notification_Dropdown`** (`rsx/theme/components/notification/`), mounted
exactly once, in `Frontend_Spa_Layout.jqhtml`. It seeds its badge from `get_count`, loads
the list lazily on first open, and refreshes the count on every SPA navigation. The
endpoints are `Frontend_Notifications_Controller`: `get_count` and `get_dropdown` are readers
that write nothing, `mark_read` and `mark_all_read` are `#[Writer]`.

## HOW TO CUSTOMIZE

- **Add a type**: a `TYPE_*` constant and `$enums` row on `Notification_Model`
  (`rsx:constants:regenerate` afterwards) plus a renderer method here. A renderer must work
  when the entity is gone — `task_assigned` shows the pattern, falling back to a title
  carried in `metadata`.
- **`send()` takes user ids, not a query.** Deciding WHO is notified is the caller's job and
  belongs where the domain rule lives, not in this class.
- **Never send a notification the recipient cannot act on**: the dropdown skips rows whose
  entity vanished, so a notification about a record the user may not read is a dead line.
- Change the retention window in config, not by editing `send()`.
- The subsystem is deletable: this directory, `Notification_Model`, the notifications
  controller, `rsx/services/notification_service.php`, and the `<Notification_Dropdown>` mount in the frontend layout.

## RELATED

`../CLAUDE.md` · `../action_log/CLAUDE.md` · `../topics/` (`Portal_Notification_Topic`) ·
`rsx/theme/components/notification/CLAUDE.md` · app skill `action-log-and-notifications` ·
`rsx:man notification` · skill `rspade:realtime`
