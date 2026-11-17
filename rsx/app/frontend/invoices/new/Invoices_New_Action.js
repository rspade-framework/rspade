/**
 * New invoice - choose a client, tick its approved entries that are not on an invoice yet, and
 * create a DRAFT invoice from them (Frontend_Invoices_Controller::create, which answers the
 * new invoice's view page as its redirect).
 *
 * The client is part of the URL (/invoices/new?client_id=N - the client view's New invoice
 * link pre-fills it), so choosing another client redirects to that URL and the page loads the
 * new client's entries in its own on_load(). Every entry starts ticked; the checklist shows
 * the running total of the ticked ones. The server validates the whole selection.
 */
@route('/invoices/new')
@layout('Frontend_Spa_Layout')
@spa('Frontend_Spa_Controller::index')
@title('New Invoice')
@auth('is_logged_in', 'can_view_data', 'can_edit_data', 'can_approve_billing')
@spinner_until_ready('always')
class Invoices_New_Action extends Spa_Action {
    // Composes with Page_Scaffold: the layout yields max-width and page
    // padding to the scaffold (see Frontend_Spa_Layout.on_action).
    scaffolded = true;

    on_create() {
        this.data.client_id = this.args.client_id ? int(this.args.client_id) : null;
        this.data.client_options = [];
        this.data.entries = [];
        this.data.form_data = { client_id: this.data.client_id || '', entry_ids: [] };
        this.data.error_data = null;
    }

    async on_load() {
        try {
            // One parallel batch: both calls key off this.args, and the entries are asked for
            // only when a client is chosen. A client that does not exist answers not-found.
            const [options, uninvoiced] = await Promise.all([
                Frontend_Invoices_Controller.client_options({}),
                this.data.client_id ? Frontend_Invoices_Controller.uninvoiced_entries({ client_id: this.data.client_id }) : null,
            ]);

            this.data.client_options = options.clients.map((c) => ({
                value: c.id,
                label: c.ready_count ? `${c.label} - ${c.ready_count} ready` : c.label,
            }));

            if (uninvoiced) {
                this.data.entries = uninvoiced.entries;
                this.data.form_data = {
                    client_id: this.data.client_id,
                    entry_ids: uninvoiced.entries.map((e) => e.id),
                };
            }
        } catch (e) {
            this.data.error_data = e;
        }
    }

    on_ready() {
        // The client select is a CHILD component, ready only here. Choosing another client is a
        // new page state: the URL carries it, and that page loads the client's entries.
        const client = this.sid('client_select');
        if (client) {
            client.on('input', (component, value) => {
                const args = value ? { client_id: value } : {};
                Rsx.redirect(Rsx.Route('Invoices_New_Action', args));
            });
        }
    }

    async breadcrumb_label_active() {
        return 'New Invoice';
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
