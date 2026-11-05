# rsx/app/frontend/party — the Class-Table Inheritance reference

## WHAT IS HERE

The CRUD screens for `Party_Model` (`rsx/models/party_model.php`), the application's worked
example of DETAIL TABLES: one base record whose type-specific fields live in a 1:1 detail
table chosen by a discriminator. The framework's own documentation points here
(`rsx:man detail_tables`, skill `rspade:detail-tables`), so this module is kept as the
canonical shape rather than as a product feature.

The model: universal fields (`name`, `email`, `phone`, `notes`) on `parties`; `type_id` picks
the detail through `$detail_tables` - `TYPE_PERSON` -> `Party_Person_Detail_Model`
(`party_person`), `TYPE_COMPANY` -> `Party_Company_Detail_Model` (`party_company`),
`TYPE_GROUP` -> no detail. The detail is reached as `$party->party_person` (auto-vivifying on
write) in PHP and `await party.party_person()` in JS, resolved from the payload `fetch()`
embeds; the wrong type's accessor THROWS in both languages.

- `party_controller.php` — `Frontend_Party_Controller`: `datagrid_fetch`, `save` (base +
  active detail inside ONE `Form_Edit::run()`; `type_id` is set on create and immutable after;
  each detail column is written only when `$edit->changed()` says so, since the detail is not
  the record `Form_Edit` guards; `name` is composed from the type's own fields), `delete`
  (soft; the detail row stays, a hard delete cascades), `party_activity` (the action-log feed,
  behind `can_view_user_activity`).
- `list/` — `Party_Index_Action` (`/party`), `Party_DataGrid` (`party_datagrid.{php,js,jqhtml}`)
  and `Party_Index_Page_Actions`, the header's "New Party" menu of the three kinds, handed to
  the layout from `page_actions()`.
- `edit/` — `Party_Edit_Action`, the dual-`@route` add/edit action (`/party/add?type=N`,
  `/party/edit/:id`) behind `@spinner_until_ready('always')`. Add mode takes the type from the
  New menu and fixes it for the page.
- `view/` — `Party_View_Action` (`/party/view/:id`): subscribes to `Party_Model` in
  `on_create()` (the detail models touch back to the party, so a detail-only edit refreshes the
  view), loads the party and the activity feed in one `Promise.all`, then the detail.

## THE ONE SEQUENCED AWAIT

Both `on_load()`s fetch the party, then await its detail accessor in a SECOND step. That is
the sanctioned exception to "independent calls run in parallel": WHICH accessor to call is
decided by `party.type_id`, which exists only once the party has arrived. Each site says so in
a `GENUINE DEPENDENCY` comment - keep the comment when copying the pattern.

## HOW TO CUSTOMIZE

- **A new party kind**: a `TYPE_*` in the model's `$enums`, a detail model + table
  (`Rsx_Detail_Table::create(...)` in a migration under `rsx/resource/migrations/`), its
  `$detail_tables` entry, then the branch in `save()`'s detail write, `__validate()`,
  `__name_sources()` / `__compose_name()`, the edit form, the view and the New menu.
- Field lengths come from `Party_Model.field_length()`, which spans base and detail columns.
- A list column from a detail table: preload the details for the page rather than one query
  per row (skill `rspade:detail-tables`, `preload_details`).

## RELATED

`rsx:man detail_tables` · skills `rspade:detail-tables`, `rspade:form-engine` · app skill
`crud-patterns` (the list/view/edit layout this follows; `rsx/app/frontend/clients/` is the
plain-model archetype) · `rsx/app/frontend/CLAUDE.md` · `rsx/models/party_model.php`.
