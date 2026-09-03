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
            is_2fa_required: false,
            is_2fa_enrolled: false,
            role_id__label: '',
            invitation_status: null,
            created_at: null,
            profile_photo_attachment_id: null,
            user_profile: null,
            recent_sessions: [],
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

    on_ready() {
        let that = this;

        // Handle Edit User button click
        that.$sid('btn_edit_user').click(async function () {
            await that.handle_edit_user();
        });

        // Handle Resend Invite button click
        that.$sid('btn_resend_invite').click(async function () {
            await that.handle_resend_invite();
        });
    }

    /**
     * Edit user workflow: show edit modal, reload on save
     */
    async handle_edit_user() {
        let that = this;

        // Show edit user modal
        const result = await Edit_User_Modal.show(that.data.user.id);

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
        const result = await Send_User_Invite_Modal.show(that.data.user.id);

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
