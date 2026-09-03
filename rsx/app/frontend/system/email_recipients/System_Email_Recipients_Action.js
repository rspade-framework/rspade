@route('/frontend/system/email_recipients')
@layout('Frontend_Spa_Layout')
@layout('System_Layout')
@spa('Frontend_Spa_Controller::index')
@title('Email Recipients')
@auth('is_logged_in')
class System_Email_Recipients_Action extends Spa_Action {
    scaffolded = true;

    on_create() {
        this.data.rows = [];
        this.data.total = 0;
        this.data.page = 1;
        this.data.per_page = 20;
        this.data.loading = true;
        this.state = { search: '' };
    }

    async on_load() {
        const result = await System_Email_Controller.recipients_fetch({
            page: this.data.page,
            per_page: this.data.per_page,
        });
        this.data.rows = result.rows;
        this.data.total = result.total;
        this.data.loading = false;
    }

    async _fetch() {
        const result = await System_Email_Controller.recipients_fetch({
            page: this.data.page,
            per_page: this.data.per_page,
            search: this.state.search || null,
        });
        this.data.rows = result.rows;
        this.data.total = result.total;
        this.render();
    }

    on_ready() {
        // Delegated handlers, NAMESPACED AND IDEMPOTENT: this.$ survives every
        // render() while on_ready() re-fires on each one, so one .off('.ser') here
        // clears this component's prior binds before they are re-attached. A one-shot
        // instance flag would be wrong in both directions - flags die with the
        // instance, handlers live on the element.
        this.$.off('.ser');

        this.$.on('change.ser', '[data-toggle-block]', async (e) => {
            const $el = $(e.currentTarget);
            const id = $el.data('toggle-block');
            const field = $el.data('field');
            await System_Email_Controller.recipients_toggle_block({id, field});
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
    }

    async breadcrumb_label_active() { return 'Email Recipients'; }
    async breadcrumb_parent() { return Rsx.Route('System_Email_Config_Action'); }
}
