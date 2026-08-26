/**
 * Settings_User_Management_Api_Keys_Action - an admin's view of ONE user's API keys.
 *
 * Deliberately narrower than the user's own Settings > API Keys screen: it lists the ACTIVE
 * keys and lets an admin revoke them, and it cannot create any. An admin needs to be able to
 * cut off a compromised integration without waiting for its owner; minting a credential that
 * would then act as somebody else is a different power, and not one this page grants.
 *
 * Revoked and expired keys are omitted for the same reason: the question this page answers is
 * "what can still reach the API as this user", and a dead key is not part of that answer.
 */
@route('/frontend/settings/user_management/:id/api_keys')
@layout('Frontend_Spa_Layout')
@layout('Settings_Layout')
@spa('Frontend_Spa_Controller::index')
@auth('can_manage_users')
class Settings_User_Management_Api_Keys_Action extends Spa_Action {
    scaffolded = true;

    on_create() {
        this.data.user = null;
        this.data.keys = [];
        this.data.error_data = null;
        this.data.loading = true;
    }

    async on_load() {
        try {
            const result = await Frontend_Settings_User_Management_Controller.get_user_api_keys({
                id: this.args.id,
            });

            this.data.user = result.user;
            this.data.keys = result.keys;
        } catch (e) {
            this.data.error_data = e;
        }

        this.data.loading = false;
    }

    on_ready() {
        const that = this;

        this.$.off('click.keys').on('click.keys', '[data-action="revoke"]', async function () {
            await that._revoke(int($(this).attr('data-id')));
        });
    }

    async _revoke(key_id) {
        const key = this.data.keys.find((k) => k.id === key_id);

        const confirmed = await Modal.confirm(
            'Revoke API Key',
            'Revoke "' + str(key ? key.name : 'this key') + '"?\n\n'
                + 'Anything using it stops working immediately. This cannot be undone.',
            'Revoke',
            'Cancel'
        );

        if (!confirmed) {
            return;
        }

        await Frontend_Settings_User_Management_Controller.revoke_user_api_key({
            id: this.args.id,
            key_id: key_id,
        });

        this.reload();
    }

    _user_name() {
        if (!this.data.user) {
            return 'User';
        }

        const name = `${str(this.data.user.first_name)} ${str(this.data.user.last_name)}`.trim();

        return name || this.data.user.email || 'User';
    }

    async page_title() {
        await this.await_loaded();
        return this._user_name() + ' - API Keys';
    }

    async breadcrumb_label() {
        await this.await_loaded();
        return this._user_name();
    }

    async breadcrumb_label_active() {
        return 'API Keys';
    }

    /**
     * The user's own view page, not the user LIST: this screen is about one user, and it is
     * reached from their record, so Back should return there.
     */
    async breadcrumb_parent() {
        return Rsx.Route('Settings_User_Management_View_Action', this.args.id);
    }
}
