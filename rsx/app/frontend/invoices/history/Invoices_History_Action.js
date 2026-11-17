/**
 * Invoice revision-history action
 *
 * The read-only change timeline for one invoice: Page_Scaffold + the invoice's header +
 * <Revision_History>, which owns the whole timeline (loading, paging and field formatting).
 * Lines are billing entries with histories of their own; this is the invoice row's - its
 * status, dates and notes.
 *
 * See: php artisan rsx:man revisions
 */
@route('/invoices/history/:id')
@layout('Frontend_Spa_Layout')
@spa('Frontend_Spa_Controller::index')
@auth('is_logged_in', 'can_view_data', 'can_approve_billing')
class Invoices_History_Action extends Spa_Action {
    // Composes with Page_Scaffold: the layout yields max-width and page padding
    // to the scaffold (see Frontend_Spa_Layout.on_action).
    scaffolded = true;

    on_create() {
        this.data.record = {};
        this.data.error_data = null;
        this.data.loading = true;
    }

    async on_load() {
        try {
            this.data.record = await Invoice_Model.fetch(this.args.id);
        } catch (e) {
            this.data.error_data = e;
        }

        this.data.loading = false;
    }

    // Data-dependent title: await_loaded() FIRST, because the layout paints the title at
    // dispatch time, before on_load() has finished.
    async page_title() {
        await this.await_loaded();
        return (this.data.record.invoice_number_formatted || 'Invoice') + ' - History';
    }

    async breadcrumb_label() {
        await this.await_loaded();
        return this.data.record.invoice_number_formatted || 'Invoice';
    }

    async breadcrumb_label_active() {
        return 'History';
    }

    async breadcrumb_parent() {
        return Rsx.Route('Invoices_View_Action', this.args.id);
    }

    page_actions() {
        return {
            component: 'Record_Page_Actions',
            args: {
                back_href: Rsx.Route('Invoices_View_Action', this.args.id),
                back_label: 'Back to Invoice',
            },
        };
    }
}
