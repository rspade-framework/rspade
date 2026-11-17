/**
 * Billable entry view - the base entry plus its kind's detail, resolved from the payload the
 * entry's fetch() embeds through the detail-tables accessor (await entry.billable_entry_time()).
 *
 * A BILLED entry names the invoice it is on (Invoice_Model through the entry's invoice()
 * relationship), linked for a viewer who may open the invoice screens.
 *
 * The actions shown follow the entry's state and the viewer:
 *   Submit, Edit, Delete - a DRAFT or REJECTED entry, to its author or an approver
 *                          (Billable_Entry_Model::is_changeable_by_current_user() mirrored)
 *   Approve, Reject      - a SUBMITTED entry, to an approver (Billing_Entry_Actions.can_approve())
 * The server decides every one of them again; these only keep the page from offering what it
 * would refuse.
 */
@route('/billing/view/:id')
@layout('Frontend_Spa_Layout')
@spa('Frontend_Spa_Controller::index')
@auth('is_logged_in', 'can_view_data')
@spinner_until_ready('uncached')
class Billing_View_Action extends Spa_Action {
    // Composes with Page_Scaffold: the layout yields max-width and page padding
    // to the scaffold (see Frontend_Spa_Layout.on_action).
    scaffolded = true;

    on_create() {
        this.data.entry = { description: '' };
        this.data.detail = null;
        this.data.invoice = null;
        this.data.activity = [];
        this.data.error_data = null;
        this.data.loading = true;

        // One Ajax_Action per control: a retry after a lost answer replays instead of running
        // the transition twice.
        this.submit_action = Ajax.action();
        this.approve_action = Ajax.action();
        this.delete_action = Ajax.action();

        // Canonical realtime pattern: subscribe in on_create() so the FIRST on_load() is gated
        // on the subscription. The detail models touch the entry (#[Realtime_Touch]), so a
        // detail-only write refreshes this view too.
        this.subscribe(Billable_Entry_Model, this.args.id, () => this.refresh());
    }

    async on_load() {
        try {
            // One parallel batch keyed off this.args.id. The entry is FATAL - the page IS this
            // record; the activity feed is non-fatal and degrades to empty.
            const [entry, activity_result] = await Promise.all([
                Billable_Entry_Model.fetch(this.args.id),
                Activity_Feed.can_view() ? Frontend_Billing_Controller.billing_activity({ id: this.args.id }).catch(() => ({ activity: [] })) : { activity: [] },
            ]);
            this.data.entry = entry;

            // GENUINE DEPENDENCY: which detail accessor to call is decided by entry.type_id,
            // which exists only once the entry has arrived. It resolves the detail from the
            // embedded payload (no network call); a fixed fee has no detail.
            if (entry.type_id === Billable_Entry_Model.TYPE_TIME) {
                this.data.detail = await entry.billable_entry_time();
            } else if (entry.type_id === Billable_Entry_Model.TYPE_EXPENSE) {
                this.data.detail = await entry.billable_entry_expense();
            } else {
                this.data.detail = null;
            }

            // GENUINE DEPENDENCY: a BILLED entry's invoice, named by entry.invoice_id. A singular
            // relationship answers null when the viewer's gates refuse the invoice, and the page
            // then names no number.
            this.data.invoice = entry.invoice_id ? await entry.invoice() : null;

            this.data.activity = activity_result.activity.map(a => ({ ...a, ...Activity_Feed.decorate(a.type_id) }));
        } catch (e) {
            this.data.error_data = e;
        } finally {
            this.data.loading = false;
        }
    }

    /** Is the entry still the author's to change (DRAFT or REJECTED), and is the viewer its author or an approver? */
    can_change() {
        const entry = this.data.entry;
        const editable = entry.status === Billable_Entry_Model.STATUS_DRAFT || entry.status === Billable_Entry_Model.STATUS_REJECTED;
        const is_author = entry.user_id === window.rsxapp.user.id;

        return editable && (is_author || Billing_Entry_Actions.can_approve());
    }

    /** Is the entry waiting for a decision the viewer may make? */
    can_decide() {
        return this.data.entry.status === Billable_Entry_Model.STATUS_SUBMITTED && Billing_Entry_Actions.can_approve();
    }

    on_render() {
        // NAMESPACED AND IDEMPOTENT, in on_render() so the cached paint responds at once.
        this.$.off('.bva');

        // KPI cells that jump to a tab (Kpi_Cell $clickable $tab).
        this.$.on('click.bva', '.Kpi_Cell--clickable', (e) => {
            const tab = $(e.currentTarget).attr('data-kpi-tab');
            const tab_bar = this.sid('tabs');
            if (tab && tab_bar) tab_bar.activate(tab);
        });

        this.$.on('click.bva', '[data-action="submit"]', async () => {
            await this.alive(this.submit_action.call(Frontend_Billing_Controller.submit, { id: this.args.id }));
            Flash_Alert.success('Entry submitted for approval');
            this.reload();
        });

        this.$.on('click.bva', '[data-action="approve"]', async () => {
            if (await this.alive(Billing_Entry_Actions.approve(this.args.id, this.approve_action))) {
                Flash_Alert.success('Entry approved');
                this.reload();
            }
        });

        this.$.on('click.bva', '[data-action="reject"]', async () => {
            if (await this.alive(Billing_Entry_Actions.reject(this.args.id))) {
                Flash_Alert.success('Entry rejected');
                this.reload();
            }
        });

        // Destructive action lives inside the overflow Action_Menu, never as a peer button.
        this.$.on('click.bva', '[data-action="delete"]', async () => {
            const confirmed = await Modal.confirm('Delete Entry', 'Delete this entry?', 'Delete', 'Cancel');
            if (!confirmed) return;
            await this.alive(this.delete_action.call(Frontend_Billing_Controller.delete, { id: this.args.id }));
            Rsx.navigate(Spa.return_url(Rsx.Route('Billing_Index_Action')));
        });
    }

    on_ready() {
        // The receipt is a CHILD component, ready only here: a new receipt is an entry change.
        const receipt = this.sid('receipt');
        if (receipt) {
            receipt.on('changed', () => this.refresh());
        }
    }

    async page_title() {
        await this.await_loaded();
        return this.data.entry.description || 'Entry';
    }

    async breadcrumb_label() {
        await this.await_loaded();
        return this.data.entry.description || 'Entry';
    }

    async breadcrumb_label_active() {
        return 'View Entry';
    }

    async breadcrumb_parent() {
        return Rsx.Route('Billing_Index_Action');
    }

    page_actions() {
        return {
            component: 'Record_Page_Actions',
            args: { back_href: Rsx.Route('Billing_Index_Action') },
        };
    }
}
