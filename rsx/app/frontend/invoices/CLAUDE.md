# rsx/app/frontend/invoices — client invoices

## WHAT IS HERE

The staff screens for `Invoice_Model` (`rsx/models/invoice_model.php`): an invoice bills a
client for APPROVED billing entries (`../billing/`), which become BILLED on it. The model owns
every rule - per-site numbering (`invoice_number`, printed `INV-0001` by the appended
`invoice_number_formatted`), the total (the appended `total`, a `sum_money()` over the lines,
never stored), `is_overdue` (appended: ISSUED and past its due date; `where_overdue()` is the
query form), creation (`create_errors()` / `create_for_client()`), the edit
(`edit_error()` / `value_errors()` / `save_edit()`) and the lifecycle pairs (`issue_errors()` /
`issue()`, `mark_paid_errors()` / `mark_paid()`, `void_errors()` / `void()`,
`remove_line_errors()` / `remove_line()`):

    DRAFT --issue--> ISSUED --mark_paid--> PAID
      |                |
      +-----void-------+--> VOID   (every entry released back to APPROVED)

This directory adds the gates, the action log and the screens.

- `invoices_controller.php` — `Frontend_Invoices_Controller`, every endpoint:
  - reads: `datagrid_fetch` (`Invoices_DataGrid`), `client_options` (every client, labelled
    with its number, and `ready_count` - its approved entries waiting), `uninvoiced_entries`
    (a client's approved entries not yet invoiced), `invoice_lines` (an invoice's entries with
    their project names), `client_invoice_summary` (a client's `outstanding` / `overdue` /
    `paid` totals, `invoice_count`, `ready_count`), `invoice_activity` (`can_view_user_activity`);
  - writes, each one `#[Writer]`: `create` (answers `redirect` to the view page), `issue`,
    `mark_paid`, `void`, `remove_line`, `save` (dates and notes through `Form_Edit::run()`,
    answering `redirect`; the conflict dialog's "View current" is the view page).
- `list/` — `Invoices_Index_Action` (`/invoices`, the header's New invoice button) and
  `Invoices_DataGrid` (`invoices_datagrid.{php,js,jqhtml,scss}`): number, client, status, issue
  and due dates, total; filters search (an invoice number - `INV-0007` or `7`, matched exactly - otherwise part of the client's name),
  status (the four statuses plus `overdue`) and client. With `$fixed_filters={client_id}` it
  leaves out its Client column and the client filter (the client view's Invoices tab).
- `new/` — `Invoices_New_Action` (`/invoices/new[?client_id=N]`): choose a client, tick its
  entries, Create Draft Invoice. The client is in the URL: choosing another redirects to that
  URL, whose `on_load()` asks for the client's entries. `Invoices_Entry_Checklist_Input` is the
  page-private form input (`$name="entry_ids"`, a list of ids): every entry starts ticked, a
  click on a row toggles it, and a `Stat_Row` under the list shows the running total of the
  ticked amounts. The server validates the selection.
- `view/` — `Invoices_View_Action` (`/invoices/view/:id`), a shell over three regions:
  `Invoices_View_Tab_Lines` (the lines in a `Record_Table`, each linking to its billing entry,
  with Remove on each while DRAFT; then the totals and the notes), `Invoices_View_Totals` (the
  `Stat_Row` money lines: Total with `$strong_label`, then by state - ISSUED: Amount due, red
  with `$alert` once overdue; PAID: the payment and Amount due $0.00; VOID: Amount due $0.00)
  and `Invoices_View_Sidebar` (KPIs, facts and the lifecycle buttons: Issue and Edit on a DRAFT,
  Mark Paid and Edit on an ISSUED invoice, Void in the overflow menu of both, History always).
  Issue and Mark Paid are `Modal.form()` dialogs around `Invoices_Issue_Form` (dates seeded today
  and today + `Invoice_Model.PAYMENT_TERMS_DAYS`) and `Invoices_Mark_Paid_Form` (seeded today),
  each an `<Rsx_Form>` on its endpoint; Void and Remove are confirmations. A region that changes
  the invoice fires `invoice_changed` and the shell reloads.
- `edit/` — `Invoices_Edit_Action` (`/invoices/edit/:id`): issue date, due date and notes
  (`Raw_Text`, its editor named by `Invoice_Model.editor_component_for('notes')`), open while the
  invoice is DRAFT or ISSUED; a paid or void invoice shows why it cannot be edited.
- `history/` — `Invoices_History_Action`, the invoice row's revision timeline
  (`Invoice_Model` is on `Frontend_Revisions_Controller`'s allowlist). Its first transaction also
  shows the entries the creation billed.
- At the root, `Invoice_Overdue_Date` — an overdue due date (danger color, a mark and the word
  "overdue"), shared by the grid and the view.

## HOW IT IS USED

**Invoices are an approver's business.** Every endpoint adds `can_approve_billing` (Manager and
above, `rsx/permission.php`) to the class's `can_view_data`; writes add `can_edit_data`. Every
action is gated the same way (`@auth(..., 'can_approve_billing')`), so the nav entry, the client
view's Invoices tab and Outstanding KPI (`Clients_View_Action.can_view_invoices()`, which is
`Permission.can_access('Invoices_Index_Action')`) and every link to an invoice hide themselves
from everybody else. `Invoice_Model::fetch()` stays on `can_view_data`, which is what lets a
billed entry's page name its invoice number for every viewer (linked only for an approver).

**Elsewhere**: the client view's Invoices tab (`../clients/view/clients_view_tab_invoices.jqhtml`)
mounts `<Invoices_DataGrid $fixed_filters={client_id}>` beside `client_invoice_summary`'s figures
and a New invoice link carrying `client_id`; a billed entry's view (`../billing/view/`) names
and links its invoice; the action log's invoice lines (types 70-79,
`rsx/lib/action_log/action_log_renderer.php`) link the view page.

**Realtime**: the view subscribes to `Invoice_Model`; every entry touches its invoice
(`#[Realtime_Touch]` on `Billable_Entry_Model::invoice()`) and the invoice touches its client,
so a line removed or an entry changed elsewhere refreshes the view and the client page.

## HOW TO CUSTOMIZE

- **Payment terms** are `Invoice_Model::PAYMENT_TERMS_DAYS`; the Issue dialog reads the same
  constant for its default due date.
- **Who may invoice** is the `can_approve_billing` check; the screens follow it with no change here.
- **A new lifecycle step** is an `*_errors()` / transition pair on the model, an endpoint here
  that asks one and performs the other, an action-log type, and its button in
  `Invoices_View_Sidebar` - offered only in the states the model allows.
- **Tax, discounts or a PDF** are not built; a tax line would be a further `Stat_Row` in
  `Invoices_View_Totals` over a figure the model computes.

## RELATED

`../billing/CLAUDE.md` (the entries an invoice bills) · `../clients/CLAUDE.md` · `../CLAUDE.md` ·
skills `rspade:money`, `rspade:form-engine` · app skills `crud-patterns`, `modals` ·
`rsx/models/invoice_model.php` · `rsx/tests/Invoice_Test.php`, `rsx/tests/Invoice_Screens_Test.php`.
