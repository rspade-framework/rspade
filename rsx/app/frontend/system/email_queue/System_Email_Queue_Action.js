@route('/frontend/system/email_queue')
@layout('Frontend_Spa_Layout')
@layout('System_Layout')
@spa('Frontend_Spa_Controller::index')
@title('Email Queue')
@auth('is_logged_in')
class System_Email_Queue_Action extends Spa_Action {
    scaffolded = true;

    on_create() {
        this.data.rows = [];
        this.data.total = 0;
        this.data.page = 1;
        this.data.per_page = 20;
        this.data.loading = true;
        this.state = { search: '', status_filter: '' };
    }

    async on_load() {
        const result = await System_Email_Controller.queue_fetch({
            page: this.data.page,
            per_page: this.data.per_page,
        });
        this.data.rows = result.rows;
        this.data.total = result.total;
        this.data.loading = false;
    }

    async _fetch() {
        const result = await System_Email_Controller.queue_fetch({
            page: this.data.page,
            per_page: this.data.per_page,
            search: this.state.search || null,
            status: this.state.status_filter || null,
        });
        this.data.rows = result.rows;
        this.data.total = result.total;
        this.render();
    }

    on_ready() {
        this.$.on('click', '[data-resend]', async (e) => {
            const id = $(e.currentTarget).data('resend');
            if (!await Modal.confirm('Resend Email', 'Re-queue this email for delivery?')) return;
            await System_Email_Controller.queue_resend({id});
            this.reload();
        });

        this.$sid('search-btn').click(() => {
            this.state.search = this.$sid('search-input').val();
            this.data.page = 1;
            this._fetch();
        });

        this.$sid('search-input').on('keypress', (e) => {
            if (e.which === 13) {
                this.state.search = this.$sid('search-input').val();
                this.data.page = 1;
                this._fetch();
            }
        });

        this.$.on('change', '[data-status-filter]', (e) => {
            this.state.status_filter = $(e.currentTarget).val();
            this.data.page = 1;
            this._fetch();
        });
    }

    async breadcrumb_label_active() { return 'Email Queue'; }
    async breadcrumb_parent() { return Rsx.Route('System_Email_Config_Action'); }
}
