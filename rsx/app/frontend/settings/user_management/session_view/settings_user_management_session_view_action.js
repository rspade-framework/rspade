/**
 * Settings_User_Management_Session_View_Action - one session of one user of this site.
 *
 * The session is loaded by id and must be one the user's view page lists (this site, active
 * within the listing window); anything else is the not-found state. Sign out ends it through
 * the same endpoint as the view page's row button and repaints, which then shows it signed
 * out. The activity list below is its own region (User_Session_Activity) with its own pages.
 */
@route('/frontend/settings/user_management/:id/sessions/:user_session_id')
@layout('Frontend_Spa_Layout')
@layout('Settings_Layout')
@spa('Frontend_Spa_Controller::index')
@auth('is_logged_in', 'can_manage_users')
class Settings_User_Management_Session_View_Action extends Spa_Action {
    scaffolded = true;

    on_create() {
        this.data.user = null;
        this.data.session = null;
        this.data.can_administer = false;
        this.data.error_data = null;
        this.data.loading = true;
    }

    async on_load() {
        try {
            const result = await Frontend_Settings_User_Management_Controller.get_user_session({
                id: this.args.id,
                user_session_id: this.args.user_session_id,
            });

            this.data.user = result.user;
            this.data.session = result.session;
            this.data.can_administer = result.can_administer;
        } catch (e) {
            this.data.error_data = e;
        }

        this.data.loading = false;
    }

    on_render() {
        // Own markup, bound in on_render() so the cached paint responds at once; namespaced
        // and idempotent because on_render() re-fires on every render.
        const that = this;

        that.$sid('btn_sign_out').off('click.session_view').on('click.session_view', async function () {
            await that.handle_sign_out();
        });
    }

    async handle_sign_out() {
        const confirmed = await Modal.confirm(
            'Sign Out Session',
            'That device is signed out now. The user can sign in again.',
            'Sign Out'
        );

        if (!confirmed) {
            return;
        }

        await Frontend_Settings_User_Management_Controller.terminate_user_session({
            id: this.data.user.id,
            user_session_id: this.data.session.id,
        });

        this.reload();
    }

    user_name() {
        if (!this.data.user) {
            return 'User';
        }

        const name = `${str(this.data.user.first_name)} ${str(this.data.user.last_name)}`.trim();

        return name || this.data.user.email || 'User';
    }

    async page_title() {
        await this.await_loaded();
        return this.user_name() + ' - Session';
    }

    async breadcrumb_label() {
        await this.await_loaded();
        return this.user_name();
    }

    async breadcrumb_label_active() {
        return 'Session';
    }

    /**
     * The user's own view page: the session was reached from their Sessions list.
     */
    async breadcrumb_parent() {
        return Rsx.Route('Settings_User_Management_View_Action', this.args.id);
    }
}
