# rsx/lib/notification — staff notifications behind the header bell

## WHAT IS HERE

- `notification.php` — `Notification`, the whole API:
  `send(int $type, array $user_ids, ?object $entity = null, array $metadata = [])`,
  `get_unread_count()`, `get_for_dropdown(int $limit = 5)`, `mark_read($id)`,
  `mark_all_read()`, `expire_old()`.
- `notification_renderer.php` — `Notification_Renderer`, one `public static` method per
  type returning `{text, url, image_url}`. Four exist, matching the four shipped types:
  `client_created`, `contact_created`, `project_created`, `task_assigned`. The text is
  PLAIN — unlike the action-log renderer, which returns HTML.

## HOW IT IS USED

`send()` writes one **`Notification_Model`** row per recipient, carrying site, user, type,
an optional polymorphic entity and an expiry (`rsx.notifications.default_expiry_days`,
21 by default). Nothing here touches the portal's notifications — those are a separate
framework model, published on `Portal_Notification_Topic` and read by the portal dashboard.

**`get_for_dropdown()` is self-policing**: it loads the unexpired rows, DELETES any whose
entity no longer exists, counts the unread among the survivors, and only then slices to the
limit and renders. So a notification pointing at a deleted record disappears rather than
rendering a dead link — which is why a count taken before the fetch can legitimately be
higher than the list that comes back.

**Rendered by `Notification_Dropdown`** (`rsx/theme/components/notification/`), mounted
exactly once, in `Frontend_Spa_Layout.jqhtml`. It seeds its badge from `get_count`, loads
the list lazily on first open, and refreshes the count on every SPA navigation. The
endpoints are `Frontend_Notifications_Controller`, whose `get_count` also runs the expiry
sweep behind a throttle rather than on a schedule.

## HOW TO CUSTOMIZE

- **Add a type**: a `TYPE_*` constant and `$enums` row on `Notification_Model`
  (`rsx:constants:regenerate` afterwards) plus a renderer method here. A renderer must work
  when the entity is gone — `task_assigned` shows the pattern, falling back to a title
  carried in `metadata`.
- **`send()` takes user ids, not a query.** Deciding WHO is notified is the caller's job and
  belongs where the domain rule lives, not in this class.
- **Never send a notification the recipient cannot act on**: the dropdown deletes rows whose
  entity vanished, so a notification about a record the user may not read is a dead line.
- Change the retention window in config, not by editing `send()`.
- The subsystem is deletable: this directory, `Notification_Model`, the notifications
  controller, and the `<Notification_Dropdown>` mount in the frontend layout.

## RELATED

`../CLAUDE.md` · `../action_log/CLAUDE.md` · `../topics/` (`Portal_Notification_Topic`) ·
`rsx/theme/components/notification/CLAUDE.md` · app skill `action-log-and-notifications` ·
`rsx:man notification` · skill `rspade:realtime`
