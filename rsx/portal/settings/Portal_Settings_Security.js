/**
 * Portal_Settings_Security
 *
 * See Portal_Settings_Security.jqhtml for the contract. The data is two independent server
 * calls under one await - the connected accounts are not asked for at all when the portal
 * has no provider live, since nothing would render them.
 */
class Portal_Settings_Security extends Component {
    on_create() {
        this.data.two_factor = null;
        this.data.sso_identities = [];
        this.data.sso_enabled = Rsx_Sso.is_enabled();

        this.state = { show_totp: false };
    }

    async on_load() {
        const [two_factor, sso_identities] = await Promise.all([
            Rsx_Portal_Two_Factor_Controller.credentials_list(),
            this.data.sso_enabled ? Rsx_Portal_Sso_Controller.identities_list() : Promise.resolve([]),
        ]);

        this.data.two_factor = two_factor;
        this.data.sso_identities = sso_identities;
    }

    on_ready() {
        // Namespaced and idempotent: on_ready() re-fires on every render and this.$ survives.
        this.$.off('.pss');

        this.$.on('click.pss', '[data-remove-credential]', async (e) => {
            const credential_id = $(e.currentTarget).data('remove-credential');
            if (!await Modal.confirm('Remove Passkey', 'Remove this sign-in method from your account?')) return;
            await this._attempt(() => Rsx_Portal_Two_Factor_Controller.credential_remove({ id: credential_id }));
        });

        this.$.on('click.pss', '[data-unlink-identity]', async (e) => {
            const identity_id = $(e.currentTarget).data('unlink-identity');
            if (!await Modal.confirm('Disconnect Account', 'Stop signing in with this account?')) return;
            await this._attempt(() => Rsx_Portal_Sso_Controller.identity_unlink({ id: identity_id }));
        });

        const $regenerate = this.$sid('regenerate');
        if ($regenerate.exists()) {
            $regenerate.off('click.pss').on('click.pss', async () => {
                if (!await Modal.confirm('New Recovery Codes', 'Your current recovery codes will stop working. Continue?')) return;
                let result;
                try {
                    result = await Rsx_Portal_Two_Factor_Controller.recovery_regenerate();
                } catch (error) {
                    await Modal.alert('Error', error.message || 'That could not be done.');
                    return;
                }
                await Modal.alert('Save your recovery codes', result.recovery_codes.join('\n'));
                this.reload();
            });
        }

        const register = this.sid('register');
        if (register) {
            register.on('registered', () => this.reload());
        }

        const $show_totp = this.$sid('show_totp');
        if ($show_totp.exists()) {
            $show_totp.off('click.pss').on('click.pss', () => {
                this.state.show_totp = true;
                this.render();
            });
        }

        const totp = this.sid('totp');
        if (totp) {
            totp.on('enrolled', () => {
                this.state.show_totp = false;
                this.reload();
            });
        }
    }

    /**
     * Run one mutating call and reload, or show why it was refused (impersonation included).
     */
    async _attempt(fn) {
        try {
            await fn();
        } catch (error) {
            await Modal.alert('Error', error.message || 'That could not be done.');
            return;
        }

        this.reload();
    }
}
