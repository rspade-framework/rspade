# rsx/app/frontend/billing — billable entries, the detail-tables reference

## WHAT IS HERE

The staff screens for `Billable_Entry_Model` (`rsx/models/billable_entry_model.php`): time,
expenses and fixed fees logged against projects, and their approval. It is the application's
worked example of DETAIL TABLES (class-table inheritance, `rsx:man detail_tables`, skill
`rspade:detail-tables`): one base record whose kind-specific fields live in a 1:1 detail table
chosen by a discriminator.

The model: the shared fields (`project_id`, `task_id`, `user_id`, `entry_date`, `description`,
`amount`, `status` and the review columns) on `billable_entries`; `type_id` picks the detail
through `$detail_tables` - `TYPE_TIME` -> `Billable_Entry_Time_Detail_Model`
(`billable_entry_time`: `hours`, `hourly_rate`), `TYPE_EXPENSE` ->
`Billable_Entry_Expense_Detail_Model` (`billable_entry_expense`: `vendor`, `cost`),
`TYPE_FIXED_FEE` -> no detail. The detail is `$entry->billable_entry_time` in PHP and
`await entry.billable_entry_time()` in JS, resolved from the payload `fetch()` embeds; the wrong
kind's accessor THROWS in both languages. The value rules, the pricing (`amount` = hours x rate
rounded half-up, the cost, the fee) and the lifecycle transitions are the MODEL's
(`value_errors()`, `save_edit()`, the `*_error()` / transition pairs); this directory adds the
gates, the record rule and the screens.

- `billing_controller.php` — `Frontend_Billing_Controller`, every endpoint:
  - grids: `datagrid_fetch` (`Billing_DataGrid`), `approvals_datagrid_fetch`
    (`Billing_Approvals_DataGrid`, `can_edit_data` + `can_approve_billing`);
  - the record: `save` (base + the kind's detail inside one `Form_Edit::run()`; answers
    `redirect` to the view page), `submit`, `approve` / `reject` (`can_approve_billing`; a
    reject needs `rejection_reason`, a field error when blank), `delete` (soft; the detail and
    receipt stay), `attach_receipt` / `receipt`;
  - read-only feeds: `form_options` (projects, the chosen project's tasks and rate, the people
    an approver may choose), `billing_activity` (`can_view_user_activity`),
    `project_billing_summary` / `task_billing_summary` (the figures the project and task view
    pages show).
- `list/` — `Billing_Index_Action` (`/billing`), `Billing_DataGrid`
  (`billing_datagrid.{php,js,jqhtml,scss}`: filters status, mine / everyone, project) and
  `Billing_Index_Page_Actions`, the header's "New entry" menu of the three kinds (also mounted
  on a project's Billing tab with `$project_id`).
- `approvals/` — `Billing_Approvals_Action` (`/billing/approvals`, gated like the approve
  endpoint) and `Billing_Approvals_DataGrid`: `Billing_DataGrid`'s query fixed to SUBMITTED
  (the PHP subclass overwrites the status param), oldest first, Approve / Reject per row.
- `edit/` — `Billing_Edit_Action`, the dual-`@route` add/edit action (`/billing/add?type=N`
  [`&project_id=N`], `/billing/edit/:id`) behind `@spinner_until_ready('always')`, and
  `Billing_Task_Select_Input`, the Task select whose choices follow the Project select.
- `view/` — `Billing_View_Action` (`/billing/view/:id`) and `Billing_Receipt` (an expense's
  receipt file, with Upload / Replace while the entry can still change). A BILLED entry names
  its invoice (the entry's `invoice()` relationship, read after the entry arrives), linked to
  the invoice's page for a viewer who may open it (`Permission.can_access('Invoices_View_Action')`).
- `history/` — `Billing_History_Action`, the base entry's revision timeline
  (`Billable_Entry_Model` is on `Frontend_Revisions_Controller`'s allowlist).
- At the root, shared by the screens: `Billing_Entry_Actions` (the approver's Approve / Reject,
  and `can_approve()`), `Billing_Reject_Form` (the Reject dialog's body, an `<Rsx_Form>` on
  `reject`) and `Billing_Kind_Chip` (the kind as an outline chip).

## HOW IT IS USED

**The kind is chosen once.** Add mode takes it from the New entry menu (`?type=N`) and fixes it
for the page; `save()` sets `type_id` only on create and validates and writes an edit against
the STORED kind. The form shows the kind's own fields - TIME hours (`Time_Entry_Input`) and
hourly rate, EXPENSE vendor and cost, FIXED_FEE the amount (each money field a `Money_Input`,
seeded with null or the `Rsx_Money`, never `''`). Field lengths come from
`Billable_Entry_Model.field_length()`, which spans base and detail columns.

**Detail writes follow `Form_Edit`'s changed fields**, and the base is saved BEFORE the detail
accessor is read (the accessor builds a new detail with the entry's id already set). Only the
base is the record `Form_Edit` guards; detail fields carry no revisions of their own, and the
History page shows their effect as the entry's amount changing.

**THE ONE SEQUENCED AWAIT**: the view and edit `on_load()`s fetch the entry, then await its
detail accessor in a second step - WHICH accessor is decided by `entry.type_id`, which exists
only once the entry has arrived. Each site says so in a `GENUINE DEPENDENCY` comment.

**The grid preloads details.** `Billing_DataGrid`'s query is over the base model, so
`DataGrid_Abstract::fetch()` calls `Billable_Entry_Model::preload_details()` between `get()`
and `toArray()`: one query per detail table for the page, never one per row
(`rsx/theme/components/datagrid/CLAUDE.md`, Detail-tables models). It joins the project's and
the person's names on the base row's `site_id`.

**Who sees which action.** Submit, Edit and Delete show on a DRAFT or REJECTED entry to its
author or an approver (mirroring `Billable_Entry_Model::is_changeable_by_current_user()`);
Approve and Reject show on a SUBMITTED entry to an approver -
`Billing_Entry_Actions.can_approve()`, which is `Permission.can_access('Billing_Approvals_Action')`,
the page gated exactly like the two endpoints. The nav's Approvals entry hides itself the same
way. The server decides every one of them again.

**The task follows the project.** Choosing another project reloads the Task choices from
`form_options` and clears the task AS THE USER'S CHANGE (`Billing_Task_Select_Input.set_options()`),
so an edit save never keeps the old project's task. A NEW time entry's hourly rate is filled
from the chosen project's rate; an existing entry keeps its own rate when its project changes.

**The receipt is a file on the BASE entry** (`Billable_Entry_Model::RECEIPT_CATEGORY`), never on
the detail row. It is attached once the entry exists: the view page (and the edit page, on an
existing expense) uploads with `Ajax.upload()` and claims with `attach_receipt`, which replaces
any earlier receipt. A new expense's form says to attach it after saving.

**Realtime**: the view subscribes to `Billable_Entry_Model`; the detail models
`#[Realtime_Touch]` the entry and the entry touches its project, so a project's Billing tab
follows a change to any of its entries.

**Elsewhere**: the project view's Billing tab (`../projects/view/projects_view_tab_billing.jqhtml`)
mounts `<Billing_DataGrid $fixed_filters={project_id}>` beside `project_billing_summary`'s
figures, and the task view shows `task_billing_summary`'s hours. APPROVED entries are invoiced
from `../invoices/` (New invoice), and an invoice's lines link back to their entries' view pages. The action log's entry lines
(types 60-69, `rsx/lib/action_log/action_log_renderer.php`) link to the view page.

## HOW TO CUSTOMIZE

- **A new kind**: a `TYPE_*` in the model's `type_id` enum, a detail model and table
  (`Rsx_Detail_Table::create(...)` in a migration under `rsx/resource/migrations/`) and its
  `$detail_tables` entry - or no entry, for a kind with no fields of its own - then its branch in
  the model's `value_errors()` / `save_edit()` / pricing, the edit form, the view's detail
  Section, `Billing_Edit_Action.kind_label()` and the New entry menu.
- **A list column from a detail table**: the row already carries `__details`, preloaded - read
  `row.__details.billable_entry_time.hours`; add no query of your own.
- **Who may approve** is the `can_approve_billing` check in `rsx/permission.php`; the screens
  follow it with no change here.

## RELATED

`rsx:man detail_tables` · skills `rspade:detail-tables`, `rspade:form-engine`, `rspade:money` ·
app skill `crud-patterns` (`../clients/` is the plain-model archetype) · `../CLAUDE.md` ·
`rsx/models/billable_entry_model.php` · `rsx/tests/Billable_Entry_Test.php`,
`rsx/tests/Billing_Screens_Test.php`.
