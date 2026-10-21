# rsx/theme/components/notification — the header notification bell

## WHAT IS HERE

- `Notification_Dropdown.jqhtml` / `Notification_Dropdown.js` / `notification_dropdown.scss`
  — the bell button with its unread-count badge (hidden at zero, capped at "99+") and the
  Bootstrap dropdown listing recent notifications, with mark-one-read-on-click,
  mark-all-read, and a link through to the full list.

That is the whole group: one component, three files.

## HOW IT IS USED

Mounted once, in the staff shell header: `rsx/app/frontend/Frontend_Spa_Layout.jqhtml`.
It loads its initial unread count in `on_load()` from
`Frontend_Notifications_Controller.get_count()`, keeps the count and the loaded list in
`this.state`, and refreshes the count on every `spa:dispatch` so navigating anywhere in
the app updates the badge. The dropdown body is fetched lazily the first time the bell is
opened, not on page load.

The records behind it are `Notification_Model` plus its renderer — sending a
notification, the unread count and the dropdown payload are the app skill
`action-log-and-notifications` and `rsx/lib/notification/`. The portal has its own
notification path (`Portal_Notification_Model`) and does not mount this component.

## HOW TO CUSTOMIZE

- **Restyle** in `notification_dropdown.scss` (single `.Notification_Dropdown` wrap,
  `Notification_Dropdown__` BEM children).
- **Change what a row shows**: the payload shape comes from the controller and the
  notification renderer, not from this component — edit those, and the component follows.
- The one `on_load()` fetch is the initial count (`this.data.initial_count`); every later
  count and the list are `this.state`. The template reads the badge through
  `_badge_count()` (the refreshed count, else the loaded one), so the first render already
  shows it - never re-render from `on_ready()` to show it (a `render()` there runs
  `on_ready()` again, and the component never reaches ready). Keep new fetches out of
  click handlers.
- Removing the bell is one line in the layout template; the component and the endpoints
  are independent of each other.

## RELATED

App skill `action-log-and-notifications` · `rsx/lib/notification/` ·
`rsx/app/frontend/Frontend_Spa_Layout.jqhtml` · skill `rspade:jqhtml` (state buckets)
