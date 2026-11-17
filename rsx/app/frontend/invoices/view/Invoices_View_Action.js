/**
 * Invoice view - the invoice's lines, totals and lifecycle.
 *
 * A shell over three regions in this directory: Invoices_View_Tab_Lines (the lines and their
 * remove action while DRAFT), Invoices_View_Totals (the Stat_Row figures) and
 * Invoices_View_Sidebar (KPIs, facts and the lifecycle buttons). A region that changes the
 * invoice fires 'invoice_changed' and the shell reloads - the one fetch stays here.
 *
 * The lifecycle offered follows the state (Invoice_Model's transitions):
 *   DRAFT  - Issue, Edit, Remove line, Void
 *   ISSUED - Mark Paid, Edit, Void
 *   PAID, VOID - nothing; the invoice is closed
 * The server decides every one of them again.
 */
@route('/invoices/view/:id')
@layout('Frontend_Spa_Layout')
@spa('Frontend_Spa_Controller::index')
@auth('is_logged_in', 'can_view_data', 'can_approve_billing')
@spinner_until_ready('uncached')
class Invoices_View_Action extends Spa_Action {
    // Composes with Page_Scaffold: the layout yields max-width and page padding
    // to the scaffold (see Frontend_Spa_Layout.on_action).
    scaffolded = true;

    on_create() {
        this.data.invoice = { invoice_number_formatted: '' };
        this.data.lines = [];
        this.data.activity = [];
        this.data.error_data = null;
        this.data.loading = true;

        // Canonical realtime pattern: subscribe in on_create() so the FIRST on_load() is gated
        // on the subscription. Every entry on the invoice touches it (#[Realtime_Touch] on
        // Billable_Entry_Model::invoice()), so a line removed or an entry changed elsewhere
        // refreshes this view too.
        this.subscribe(Invoice_Model, this.args.id, () => this.refresh());
    }

    async on_load() {
        try {
            // One parallel batch keyed off this.args.id. The invoice is FATAL - the page IS this
            // record; the lines are fatal too (an invoice shown without its lines would show a
            // total that disagrees with them); the activity feed degrades to empty.
            const [invoice, lines_result, activity_result] = await Promise.all([
                Invoice_Model.fetch(this.args.id),
                Frontend_Invoices_Controller.invoice_lines({ id: this.args.id }),
                Activity_Feed.can_view() ? Frontend_Invoices_Controller.invoice_activity({ id: this.args.id }).catch(() => ({ activity: [] })) : { activity: [] },
            ]);
            this.data.invoice = invoice;
            this.data.lines = lines_result.lines;
            this.data.activity = activity_result.activity.map(a => ({ ...a, ...Activity_Feed.decorate(a.type_id) }));
        } catch (e) {
            this.data.error_data = e;
        } finally {
            this.data.loading = false;
        }
    }

    on_render() {
        // KPI cells that jump to a tab (Kpi_Cell $clickable $tab). On the SHELL because it
        // crosses regions: the cells are in the sidebar, the Tab_Bar in the main column.
        this.$.off('click.iva').on('click.iva', '.Kpi_Cell--clickable', (e) => {
            const tab = $(e.currentTarget).attr('data-kpi-tab');
            const tab_bar = this.sid('tabs');
            if (tab && tab_bar) tab_bar.activate(tab);
        });
    }

    on_ready() {
        // The regions are CHILD components, ready only here. A parent render destroys them,
        // so every on_ready() registers against brand-new instances.
        for (const sid of ['lines', 'sidebar']) {
            const region = this.sid(sid);
            if (region) region.on('invoice_changed', () => this.reload());
        }
    }

    async page_title() {
        await this.await_loaded();
        return this.data.invoice.invoice_number_formatted || 'Invoice';
    }

    async breadcrumb_label() {
        await this.await_loaded();
        return this.data.invoice.invoice_number_formatted || 'Invoice';
    }

    async breadcrumb_label_active() {
        return 'View Invoice';
    }

    async breadcrumb_parent() {
        return Rsx.Route('Invoices_Index_Action');
    }

    page_actions() {
        return {
            component: 'Record_Page_Actions',
            args: { back_href: Rsx.Route('Invoices_Index_Action') },
        };
    }
}
