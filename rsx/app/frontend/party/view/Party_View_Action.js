/**
 * Party detail view - shows universal fields plus the type-specific detail, resolved from
 * the embedded payload via the CTI accessor (await party.party_person()). No extra fetch.
 *
 * Tabbed like the Clients_View archetype: Overview / Activity, with a KPI sidebar. The
 * Overview tab carries the universal fields plus the type-specific detail (or an
 * Empty_State for a Group); the type discriminator surfaces as an outline classification
 * chip. Composed from the semantic view-page vocabulary (Page_Scaffold / Card_Widget /
 * Entity_Header / Tab_Bar / Tab_Panels / Section / View_Fields / Empty_State / Feed_Row /
 * Detail_Sidebar / Action_Menu).
 */
@route('/party/view/:id')
@layout('Frontend_Spa_Layout')
@spa('Frontend_Spa_Controller::index')
@auth('is_logged_in')
class Party_View_Action extends Spa_Action {
    // Composes with Page_Scaffold: the layout yields max-width and page padding
    // to the scaffold (see Frontend_Spa_Layout.on_action).
    scaffolded = true;

    on_create() {
        this.data.party = { name: '' };
        this.data.detail = null;
        this.data.activity = [];
        this.data.error_data = null;
        this.data.loading = true;

        // Canonical realtime pattern: subscribe in on_create() so the FIRST on_load() is
        // GATED on subscription establishment -> exactly ONE race-free fetch, strictly
        // after the subscription is live (the initial resync is swallowed; the gated load
        // IS the revalidation). refresh() (not reload()) refetches via on_load() but
        // re-renders ONLY when the data actually changed. Idempotent per component, auto-
        // stops on destroy. The CTI detail models touch back to the party, so a
        // detail-only edit refreshes this view too.
        this.subscribe(Party_Model, this.args.id, () => this.refresh());
    }

    async on_load() {
        try {
            const [party, activity_result] = await Promise.all([
                Party_Model.fetch(this.args.id),
                Frontend_Party_Controller.party_activity({id: this.args.id}),
            ]);
            this.data.party = party;

            // Resolve the type-specific detail from the embedded payload (no network call).
            if (party.type_id === Party_Model.TYPE_PERSON) {
                this.data.detail = await party.party_person();
            } else if (party.type_id === Party_Model.TYPE_COMPANY) {
                this.data.detail = await party.party_company();
            } else {
                this.data.detail = null;
            }

            // Decorate each activity entry with its Feed_Row icon/variant (shared map).
            this.data.activity = activity_result.activity.map(a => ({...a, ...Activity_Feed.decorate(a.type_id)}));
        } catch (e) {
            this.data.error_data = e;
        } finally {
            this.data.loading = false;
        }
    }

    on_ready() {
        // KPI cells that jump to a tab (Kpi_Cell $clickable $tab).
        this.$.on('click', '.Kpi_Cell--clickable', (e) => {
            const tab = $(e.currentTarget).attr('data-kpi-tab');
            const tab_bar = this.sid('tabs');
            if (tab && tab_bar) tab_bar.activate(tab);
        });

        // Destructive action lives inside the overflow Action_Menu, never as a peer button.
        if (this.$sid('delete-party').exists()) {
            this.$sid('delete-party').click(async () => {
                const confirmed = await Modal.confirm('Delete Party', 'Are you sure you want to delete this party?\n\nThis can be undone by restoring it afterward.', 'Delete', 'Cancel');
                if (!confirmed) return;
                await Frontend_Party_Controller.delete({id: this.args.id});
                Spa.dispatch(Rsx.Route('Party_Index_Action'));
            });
        }
    }

    async page_title() {
        await this.await_loaded();
        return this.data.party.name || 'Party';
    }

    async breadcrumb_label() {
        await this.await_loaded();
        return this.data.party.name || 'Party';
    }

    async breadcrumb_label_active() {
        return 'View Party';
    }

    async breadcrumb_parent() {
        return Rsx.Route('Party_Index_Action');
    }

    page_actions() {
        const id = this.args.id;
        return `
            <div class="d-flex gap-2">
                <a href="${Rsx.Route('Party_Index_Action')}" class="btn btn-secondary btn-sm">
                    <i class="bi bi-arrow-left"></i> Back
                </a>
                ${id ? `
                <a href="${Rsx.Route('Party_Edit_Action', id)}" class="btn btn-primary btn-sm">
                    <i class="bi bi-pencil"></i> Edit
                </a>` : ''}
            </div>
        `;
    }
}
