/**
 * Settings User Management View Action
 *
 * View single user details with admin actions.
 */
@route('/frontend/settings/user_management/:id')
@layout('Frontend_Spa_Layout')
@layout('Settings_Layout')
@spa('Frontend_Spa_Controller::index')
@title('User Details - Settings')
@auth('is_logged_in', 'can_manage_users')
class Settings_User_Management_View_Action extends Spa_Action {
    scaffolded = true;

    on_create() {
        this.data.user = {
            id: null,
            email: '',
            first_name: '',
            last_name: '',
            phone: '',
            is_enabled: true,
            is_2fa_enrolled: false,
            role_id__label: '',
            can_administer: false,
            is_self: false,
            invitation_status: null,
            created_at: null,
            profile_photo_attachment_id: null,
            user_profile: null,
            sessions: [],
        };
        this.data.error_data = null;
        this.data.loading = true;
    }

    async on_load() {
        try {
            this.data.user = await Frontend_Settings_User_Management_Controller.get_user({
                id: this.args.id,
            });
        } catch (e) {
            this.data.error_data = e;
        }
        this.data.loading = false;
    }

    on_render() {
        // Own markup, bound in on_render() so the cached paint responds at once; the
        // handlers read this.data when they fire. Namespaced and idempotent because
        // on_render() re-fires on every render.
        let that = this;

        // Handle Edit User button click
        that.$sid('btn_edit_user').off('click.user_view').on('click.user_view', async function () {
            await that.handle_edit_user();
        });

        // Handle Resend Invite button click
        that.$sid('btn_resend_invite').off('click.user_view').on('click.user_view', async function () {
            await that.handle_resend_invite();
        });

        // Handle Enable / Disable button click
        that.$sid('btn_set_enabled').off('click.user_view').on('click.user_view', async function () {
            const $button = $(this);
            await that.handle_set_enabled($button.attr('data-enabled') === '1');
        });

        // Sessions: one Sign out per row (delegated - the rows are rebuilt per render) and
        // Sign out everywhere in the section header.
        that.$.off('click.user_view_sessions').on('click.user_view_sessions', '[data-sign-out-session]', async function () {
            await that.handle_sign_out_session(int($(this).attr('data-sign-out-session')));
        });

        that.$sid('btn_sign_out_all').off('click.user_view').on('click.user_view', async function () {
            await that.handle_sign_out_all();
        });
    }

    /**
     * Sign one of the user's sessions out, after a confirm.
     *
     * @param {number} user_session_id
     */
    async handle_sign_out_session(user_session_id) {
        const confirmed = await Modal.confirm(
            'Sign Out Session',
            'That device is signed out now. The user can sign in again.',
            'Sign Out'
        );

        if (!confirmed) {
            return;
        }

        await this.alive(Frontend_Settings_User_Management_Controller.terminate_user_session({
            id: this.data.user.id,
            user_session_id: user_session_id,
        }));

        this.reload();
    }

    /**
     * Sign the user out of every browser on this site, after a confirm.
     */
    async handle_sign_out_all() {
        const confirmed = await Modal.confirm(
            'Sign Out Everywhere',
            'Every device signed in to this site as this user is signed out now. The user can sign in again.',
            'Sign Out Everywhere'
        );

        if (!confirmed) {
            return;
        }

        await this.alive(Frontend_Settings_User_Management_Controller.terminate_all_user_sessions({
            id: this.data.user.id,
        }));

        this.reload();
    }

    /**
     * Enable / disable workflow: confirm a disable, then switch users.is_enabled
     *
     * @param {boolean} is_enabled - the state to switch the membership to
     */
    async handle_set_enabled(is_enabled) {
        let that = this;

        if (!is_enabled) {
            const confirmed = await Modal.confirm(
                'Disable User',
                'This user will be signed out and unable to sign in to this site until re-enabled.'
            );

            if (!confirmed) {
                return;
            }
        }

        await that.alive(Frontend_Settings_User_Management_Controller.set_user_enabled({
            id: that.data.user.id,
            is_enabled: is_enabled ? 1 : 0,
        }));

        that.reload();
    }

    /**
     * Edit user workflow: show edit modal, reload on save
     */
    async handle_edit_user() {
        let that = this;

        // Show edit user modal
        const result = await that.alive(Edit_User_Modal.show(that.data.user.id));

        if (result) {
            // Reload action to show updated user information
            that.reload();
        }
    }

    /**
     * Resend invite workflow: call send invite modal
     */
    async handle_resend_invite() {
        let that = this;

        // Show send invite modal
        const result = await that.alive(Send_User_Invite_Modal.show(that.data.user.id));

        if (result) {
            // Reload action to show updated invite status
            that.reload();
        }
    }

    // Breadcrumb methods
    _user_name() {
        const name = `${str(this.data.user.first_name)} ${str(this.data.user.last_name)}`.trim();
        return name || this.data.user.email || 'User';
    }

    async page_title() {
        await this.await_loaded();
        return this._user_name();
    }

    async breadcrumb_label() {
        await this.await_loaded();
        return this._user_name();
    }

    async breadcrumb_label_active() {
        return 'View User';
    }

    async breadcrumb_parent() {
        return Rsx.Route('Settings_User_Management_Index_Action');
    }
}
