@route('/frontend/system/email_recipients')
@layout('Frontend_Spa_Layout')
@layout('System_Layout')
@spa('Frontend_Spa_Controller::index')
@title('Email Recipients')
@auth('is_logged_in', 'can_manage_site_settings')
class System_Email_Recipients_Action extends Spa_Action {
    scaffolded = true;

    on_create() {
        this.data.rows = [];
        this.data.total = 0;
        this.data.loading = true;
    }

    // The search is an INPUT to the load, so it is an arg: a change
    // sets it and reload()s, and the template shows it.
    async on_load() {
        const result = await System_Email_Controller.recipients_fetch({
            page: 1,
            per_page: 20,
            search: this.args.search || null,
        });
        this.data.rows = result.rows;
        this.data.total = result.total;
        this.data.loading = false;
    }

    on_render() {
        // NAMESPACED AND IDEMPOTENT, in on_render() so the cached paint responds at once
        // and every render gets its search box rebound: this.$ survives every
        // render() while on_render() re-fires on each one, so one .off('.ser') here
        // clears this component's prior delegated binds, and each $sid element (rebuilt
        // per render) is .off() before .on(). A one-shot instance flag would be wrong in
        // both directions - flags die with the instance, handlers live on the element.
        this.$.off('.ser');

        this.$.on('change.ser', '[data-toggle-block]', async (e) => {
            const $el = $(e.currentTarget);
            const id = $el.data('toggle-block');
            const field = $el.data('field');
            await System_Email_Controller.recipients_toggle_block({id, field});
        });

        this.$sid('search-btn').off('click.ser').on('click.ser', () => {
            this.args.search = this.$sid('search-input').val();
            this.reload();
        });

        this.$sid('search-input').off('keypress.ser').on('keypress.ser', (e) => {
            if (e.which === 13) {
                this.args.search = this.$sid('search-input').val();
                this.reload();
            }
        });
    }

    async breadcrumb_label_active() { return 'Email Recipients'; }
    async breadcrumb_parent() { return Rsx.Route('System_Email_Config_Action'); }
}
