/**
 * Billable entry revision-history action
 *
 * The read-only change timeline for one entry: Page_Scaffold + the entry's header +
 * <Revision_History>, which owns the whole timeline (loading, paging and field formatting).
 * The timeline is the BASE entry's: the detail rows (hours, rate, vendor, cost) record no
 * revisions of their own, and their effect shows as the entry's amount changing.
 *
 * See: php artisan rsx:man revisions
 */
@route('/billing/history/:id')
@layout('Frontend_Spa_Layout')
@spa('Frontend_Spa_Controller::index')
@auth('is_logged_in', 'can_view_data')
class Billing_History_Action extends Spa_Action {
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
            this.data.record = await Billable_Entry_Model.fetch(this.args.id);
        } catch (e) {
            this.data.error_data = e;
        }

        this.data.loading = false;
    }

    // Data-dependent title: await_loaded() FIRST, because the layout paints the title at
    // dispatch time, before on_load() has finished.
    async page_title() {
        await this.await_loaded();
        return (this.data.record.description || 'Entry') + ' - History';
    }

    async breadcrumb_label() {
        await this.await_loaded();
        return this.data.record.description || 'Entry';
    }

    async breadcrumb_label_active() {
        return 'History';
    }

    async breadcrumb_parent() {
        return Rsx.Route('Billing_View_Action', this.args.id);
    }

    page_actions() {
        return {
            component: 'Record_Page_Actions',
            args: {
                back_href: Rsx.Route('Billing_View_Action', this.args.id),
                back_label: 'Back to Entry',
            },
        };
    }
}
