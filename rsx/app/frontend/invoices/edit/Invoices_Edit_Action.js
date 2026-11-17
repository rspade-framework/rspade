/**
 * Invoice edit - the invoice's dates and notes, while it is a DRAFT or ISSUED (a paid or void
 * invoice is closed; the server refuses it). Lines are never edited here: they are removed
 * from a draft on its view page, and an invoice is created from approved entries.
 *
 * Saves through Frontend_Invoices_Controller::save (Form_Edit: changed fields only, under a row
 * lock, asking when another editor changed the same field); the seed carries updated_at.
 */
@route('/invoices/edit/:id')
@layout('Frontend_Spa_Layout')
@spa('Frontend_Spa_Controller::index')
@auth('is_logged_in', 'can_view_data', 'can_edit_data', 'can_approve_billing')
@spinner_until_ready('always')
class Invoices_Edit_Action extends Spa_Action {
    // Composes with Page_Scaffold: the layout yields max-width and page
    // padding to the scaffold (see Frontend_Spa_Layout.on_action).
    scaffolded = true;

    on_create() {
        // `notes` edits a Raw_Text value object: its empty is null, never ''.
        this.data.form_data = { id: this.args.id, issue_date: '', due_date: '', notes: null };
        this.data.invoice = { invoice_number_formatted: '' };
        this.data.error_data = null;
        this._watched_form = null;
    }

    async on_load() {
        try {
            const invoice = await Invoice_Model.fetch(this.args.id);
            this.data.invoice = invoice;
            // The fetched record IS the form's seed, with the page's input shapes assigned
            // onto it: the form judges a value a before_submit hook supplies against the
            // record by the column's own rule (Invoice_Model.same_value()), which a plain
            // copy of the fields cannot offer (rsx:man form_conventions, EDITING A RECORD).
            this.data.form_data = Object.assign(invoice, {
                issue_date: invoice.issue_date || '',
                due_date: invoice.due_date || '',
            });
        } catch (e) {
            this.data.error_data = e;
        }
    }

    /** Is the invoice still open to editing (DRAFT or ISSUED)? */
    is_open() {
        const status = this.data.invoice.status;
        return status === Invoice_Model.STATUS_DRAFT || status === Invoice_Model.STATUS_ISSUED;
    }

    on_ready() {
        this._watch_form_for_changes();
    }

    /**
     * The navigation guard follows the form's 'dirty' event (the fully commented example is
     * Contacts_Edit_Action), and goes off on the Cancel link and in on_stop(). A re-render
     * replaces the form component, so this wires the instance it is given and only that one.
     */
    _watch_form_for_changes() {
        const $form = this.$.find('.Rsx_Form').first();
        if (!$form.exists()) {
            return;
        }
        const form = $form.component();
        if (form === this._watched_form) {
            return;
        }
        this._watched_form = form;

        form.on('dirty', (form, dirty) => (dirty ? Rsx.set_navigation_guard() : Rsx.clear_navigation_guard()));
    }

    /** The Cancel link: the changes are being thrown away, so nothing is left to ask about. */
    discard_changes() {
        Rsx.clear_navigation_guard();
    }

    on_stop() {
        Rsx.clear_navigation_guard();
    }

    async page_title() {
        await this.await_loaded();
        return 'Edit ' + (this.data.invoice.invoice_number_formatted || 'Invoice');
    }

    async breadcrumb_label_active() {
        return 'Edit Invoice';
    }

    async breadcrumb_parent() {
        return Rsx.Route('Invoices_View_Action', this.args.id);
    }

    page_actions() {
        return {
            component: 'Record_Page_Actions',
            args: { back_href: Rsx.Route('Invoices_View_Action', this.args.id) },
        };
    }
}
