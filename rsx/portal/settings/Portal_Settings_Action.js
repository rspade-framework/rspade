@route('/settings')
@layout('Portal_Layout')
@portal_spa('Portal_Spa_Controller::index')
@title('Settings')
@auth('is_logged_in')
class Portal_Settings_Action extends Spa_Action {
    // Composes with Page_Scaffold; Portal_Layout yields page width/padding to it.
    scaffolded = true;

    on_create() {
        this.data.profile = null;
        this.data.sessions = [];
        this.data.loading = true;
    }

    async on_load() {
        const [profile, sessions] = await Promise.all([
            Portal_Settings_Controller.get_profile(),
            Portal_Settings_Controller.get_sessions(),
        ]);
        this.data.profile = profile;
        this.data.sessions = sessions.sessions;
        this.data.loading = false;
    }

    on_ready() {
        // Delegated handlers, NAMESPACED AND IDEMPOTENT: this.$ survives every
        // render() while on_ready() re-fires on each one, so one .off('.psa') here
        // clears this component's prior binds before they are re-attached. A one-shot
        // instance flag would be wrong in both directions - flags die with the
        // instance, handlers live on the element.
        this.$.off('.psa');

        // Change password form
        if (this.$sid('password-form').exists()) {
            this.$sid('password-form').on('submit', async (e) => {
                e.preventDefault();
                const current = this.$sid('current-password').val();
                const new_pw = this.$sid('new-password').val();
                const confirm_pw = this.$sid('confirm-password').val();

                try {
                    await Portal_Settings_Controller.change_password({
                        current_password: current,
                        new_password: new_pw,
                        confirm_password: confirm_pw,
                    });
                    await Modal.alert('Password Updated', 'Your password has been changed successfully.');
                    this.$sid('current-password').val('');
                    this.$sid('new-password').val('');
                    this.$sid('confirm-password').val('');
                } catch (e) {
                    if (e.errors) {
                        // Show field-specific errors
                        for (const [field, msg] of Object.entries(e.errors)) {
                            this.$sid(field.replace('_', '-') + '-error').text(msg).show();
                        }
                    } else {
                        await Modal.alert('Error', e.message || 'Failed to update password');
                    }
                }
            });
        }

        // Terminate session
        this.$.on('click.psa', '[data-terminate-session]', async (e) => {
            const session_id = $(e.currentTarget).data('terminate-session');
            if (!await Modal.confirm('Terminate Session', 'End this session? The device will be logged out.')) return;
            await Portal_Settings_Controller.terminate_session({session_id});
            this.reload();
        });
    }
}
