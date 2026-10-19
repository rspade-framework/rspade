@route('/frontend/system/email_queue')
@layout('Frontend_Spa_Layout')
@layout('System_Layout')
@spa('Frontend_Spa_Controller::index')
@title('Email Queue')
@auth('is_logged_in', 'can_manage_site_settings')
class System_Email_Queue_Action extends Spa_Action {
    scaffolded = true;

    on_create() {
        this.data.rows = [];
        this.data.total = 0;
        this.data.loading = true;
    }

    // The search and the status filter are INPUTS to the load, so they are args: a change
    // sets them and reload()s, and the template shows them.
    async on_load() {
        const result = await System_Email_Controller.queue_fetch({
            page: 1,
            per_page: 20,
            search: this.args.search || null,
            status: this.args.status || null,
        });
        this.data.rows = result.rows;
        this.data.total = result.total;
        this.data.loading = false;
    }

    on_render() {
        // NAMESPACED AND IDEMPOTENT, in on_render() so the cached paint responds at once
        // and every render gets its search box rebound: this.$ survives every
        // render() while on_render() re-fires on each one, so one .off('.seq') here
        // clears this component's prior delegated binds, and each $sid element (rebuilt
        // per render) is .off() before .on(). A one-shot instance flag would be wrong in
        // both directions - flags die with the instance, handlers live on the element.
        this.$.off('.seq');

        this.$.on('click.seq', '[data-resend]', async (e) => {
            const id = $(e.currentTarget).data('resend');
            if (!await Modal.confirm('Resend Email', 'Re-queue this email for delivery?')) return;
            await System_Email_Controller.queue_resend({id});
            this.reload();
        });

        this.$sid('search-btn').off('click.seq').on('click.seq', () => {
            this.args.search = this.$sid('search-input').val();
            this.reload();
        });

        this.$sid('search-input').off('keypress.seq').on('keypress.seq', (e) => {
            if (e.which === 13) {
                this.args.search = this.$sid('search-input').val();
                this.reload();
            }
        });

        this.$.on('change.seq', '[data-status-filter]', (e) => {
            this.args.status = $(e.currentTarget).val();
            this.reload();
        });
    }

    async breadcrumb_label_active() { return 'Email Queue'; }
    async breadcrumb_parent() { return Rsx.Route('System_Email_Config_Action'); }
}
