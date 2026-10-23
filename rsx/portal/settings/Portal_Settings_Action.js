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

    on_render() {
        // NAMESPACED AND IDEMPOTENT, in on_render() so the cached paint responds at once:
        // this.$ survives every render() while on_render() re-fires on each one, so one
        // .off('.psa') here clears this component's prior delegated binds, and the form
        // (rebuilt per render) is .off() before .on(). A one-shot instance flag would be
        // wrong in both directions - flags die with the instance, handlers live on the
        // element.
        this.$.off('.psa');

        // Change password form
        if (this.$sid('password-form').exists()) {
            this.$sid('password-form').off('submit.psa').on('submit.psa', async (e) => {
                e.preventDefault();
                const current = this.$sid('current-password').val();
                const new_pw = this.$sid('new-password').val();
                const confirm_pw = this.$sid('confirm-password').val();

                try {
                    await this.alive(Portal_Settings_Controller.change_password({
                        current_password: current,
                        new_password: new_pw,
                        confirm_password: confirm_pw,
                    }));
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

        // Sign out another device: the id is a portal user session id.
        this.$.on('click.psa', '[data-terminate-session]', async (e) => {
            const user_session_id = int($(e.currentTarget).data('terminate-session'));
            if (!await Modal.confirm('Sign Out Device', 'That device is signed out of the portal now. You can sign in on it again.', 'Sign Out')) return;
            await this.alive(Portal_Settings_Controller.terminate_session({user_session_id}));
            this.reload();
        });
    }
}
