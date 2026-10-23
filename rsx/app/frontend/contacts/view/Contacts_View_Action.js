/**
 * Contact detail view action
 *
 * Displays a single contact, composed from the semantic view-page vocabulary
 * (Page_Scaffold / Card_Widget / Entity_Header / Tab_Bar / Tab_Panels / Section /
 * View_Fields / Record_Table / Feed_Row / Detail_Sidebar). Tabbed like the
 * Clients_View archetype: Overview / Projects / Activity, with a KPI sidebar.
 */
@route('/contacts/view/:id')
@layout('Frontend_Spa_Layout')
@spa('Frontend_Spa_Controller::index')
@auth('is_logged_in', 'can_view_data')
@spinner_until_ready('uncached')
class Contacts_View_Action extends Spa_Action {
    // Composes with Page_Scaffold: the layout yields max-width and page padding
    // to the scaffold (see Frontend_Spa_Layout.on_action).
    scaffolded = true;

    on_create() {
        // Initialize contact data to prevent undefined errors during first render
        this.data.contact = {
            first_name: '',
            last_name: '',
            full_name: '',
        };
        this.data.projects = [];
        this.data.activity = [];
        this.data.error_data = null;
        this.data.loading = true;

        // Canonical realtime pattern: subscribe in on_create() so the FIRST on_load() is
        // GATED on subscription establishment -> exactly ONE race-free fetch, strictly
        // after the subscription is live (the initial resync is swallowed; the gated load
        // IS the revalidation). refresh() (not reload()) refetches via on_load() but
        // re-renders ONLY when the data actually changed. Idempotent per component, auto-
        // stops on destroy. The contact's projects (#[Realtime_Touch] on Project::client())
        // touch the CLIENT, not this contact, so the Projects tab live-updates only for
        // contact-record changes.
        this.subscribe(Contact_Model, this.args.id, () => this.refresh());
    }

    async on_load() {
        try {
            // One parallel batch: every call keys off this.args.id, so none of them
            // depends on another's RESULT. The contact is FATAL - the page IS this
            // record - and carries no .catch(); the two side lists are non-fatal and
            // degrade to empty so a failing tab cannot blank the record.
            const [contact, projects_result, activity_result] = await Promise.all([
                Contact_Model.fetch(this.args.id),
                Frontend_Contacts_Controller.contact_projects({id: this.args.id}).catch(() => ({projects: []})),
                Frontend_Contacts_Controller.contact_activity({id: this.args.id}).catch(() => ({activity: []})),
            ]);
            this.data.contact = contact;
            this.data.projects = projects_result.projects;
            // Decorate each activity entry with its Feed_Row icon/variant (shared map).
            this.data.activity = activity_result.activity.map(a => ({...a, ...Activity_Feed.decorate(a.type_id)}));
        } catch (e) {
            this.data.error_data = e;
        } finally {
            this.data.loading = false;
        }
    }

    // Breadcrumb methods
    async page_title() {
        await this.await_loaded();
        const c = this.data.contact;
        return `${c.first_name} ${c.last_name}`.trim() || 'Contact';
    }

    async breadcrumb_label() {
        await this.await_loaded();
        const c = this.data.contact;
        return `${c.first_name} ${c.last_name}`.trim() || 'Contact';
    }

    async breadcrumb_label_active() {
        return 'View Contact';
    }

    async breadcrumb_parent() {
        return Rsx.Route('Contacts_Index_Action');
    }

    on_render() {
        // Delegated on the component root, NAMESPACED AND IDEMPOTENT, in on_render() so
        // the cached paint responds at once: this.$ survives every render() while
        // on_render() re-fires on each one, so a bare .on() would stack a second handler
        // per repaint. The Tab_Bar is looked up when the click lands. The .off('.ns').on('.ns') pair needs no
        // one-shot flag - flags live on the instance, handlers live on the element.
        // KPI cells that jump to a tab (Kpi_Cell $clickable $tab).
        this.$.off('click.contacts_view').on('click.contacts_view', '.Kpi_Cell--clickable', (e) => {
            const tab = $(e.currentTarget).attr('data-kpi-tab');
            const tab_bar = this.sid('tabs');
            if (tab && tab_bar) tab_bar.activate(tab);
        });
    }

    // Open the client portal as this contact (impersonation, read-only) in a new tab.
    // The tab is opened synchronously, inside the click, so no popup blocker refuses it;
    // it is pointed at the URL once the server has answered.
    async view_as_client() {
        const $btn = this.$sid('view_as_client');
        $btn.prop('disabled', true);
        const tab = window.open('', '_blank');
        try {
            const res = await Frontend_Contacts_Controller.begin_portal_impersonation({ id: this.args.id });
            tab.location.href = res.url;
        } catch (e) {
            tab.close();
            // The blank tab is closed either way; only the alert belongs to this page.
            if (this.is_stopped()) return;
            Modal.alert('Unable to View as Client', e.message || 'Could not start the portal session.');
        } finally {
            $btn.prop('disabled', false);
        }
    }

    // Action buttons for page header
    page_actions() {
        const id = this.args.id;
        return {
            component: 'Record_Page_Actions',
            args: {
                back_href: Rsx.Route('Contacts_Index_Action'),
                edit_href: id ? Rsx.Route('Contacts_Edit_Action', id) : null,
            },
        };
    }
}
