/**
 * Clients revision-history action
 *
 * The read-only change timeline for one client: Page_Scaffold + the entity header the
 * view page uses + <Revision_History>, which owns the whole timeline (loading, paging and
 * field formatting). This action exists to give the history a URL, a title and a way back.
 *
 * See: php artisan rsx:man revisions
 */
@route('/clients/history/:id')
@layout('Frontend_Spa_Layout')
@spa('Frontend_Spa_Controller::index')
@auth('is_logged_in')
class Clients_History_Action extends Spa_Action {
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
            this.data.record = await Client_Model.fetch(this.args.id);
        } catch (e) {
            this.data.error_data = e;
        }

        this.data.loading = false;
    }

    // Data-dependent title: await_loaded() FIRST, because the layout paints the title at
    // dispatch time, before on_load() has finished.
    async page_title() {
        await this.await_loaded();
        return (this.data.record.name || 'Client') + ' - History';
    }

    async breadcrumb_label() {
        await this.await_loaded();
        return (this.data.record.name || 'Client');
    }

    async breadcrumb_label_active() {
        return 'History';
    }

    async breadcrumb_parent() {
        return Rsx.Route('Clients_Index_Action');
    }

    page_actions() {
        return `
            <div class="d-flex gap-2">
                <a href="${Rsx.Route('Clients_View_Action', this.args.id)}" class="btn btn-secondary btn-sm">
                    <i class="bi bi-arrow-left"></i> Back to Clients
                </a>
            </div>
        `;
    }
}
