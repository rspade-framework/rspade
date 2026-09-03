/**
 * Settings Password Security Action
 *
 * Password change, two-factor authentication, and session management.
 *
 * THE TWO-FACTOR SECTION IS REAL; the rest of this page is not yet. The 2FA data is loaded
 * from the framework's own Rsx_Two_Factor_Controller in on_load() and repainted with
 * reload() after every change, so what the screen shows is always what the server holds.
 * The active-sessions list is still static sample data (see the settings CLAUDE.md).
 *
 * ENROLLMENT IS THE FRAMEWORK'S COMPONENTS, hosted in a modal: <Totp_Enrollment> and
 * <Passkey_Register> own their whole ceremony including the one-time recovery-code reveal,
 * and this page listens for the single event each fires when the user is finished.
 */
@route('/frontend/settings/password_security')
@layout('Frontend_Spa_Layout')
@layout('Settings_Layout')
@spa('Frontend_Spa_Controller::index')
@title('Password & Security')
@auth('is_logged_in')
class Settings_Password_Security_Action extends Spa_Action {
    scaffolded = true;

    // The credential type ids, mirroring Two_Factor_Credential_Model::TYPE_TOTP / TYPE_PASSKEY.
    // That model ships no JS stub, so the two ids are spelled here; the payload carries
    // type_id__label for display and these are used only to group the rows.
    static TYPE_TOTP = 1;
    static TYPE_PASSKEY = 2;

    on_create() {
        // null until the load answers: "not loaded yet" and "nothing enrolled" render
        // differently, and an empty object would make them indistinguishable.
        this.data.two_factor = null;

        this.state = {
            // Taken once: whether this browser can run a passkey ceremony does not change
            // while the page is open.
            passkeys_supported: Rsx_Two_Factor.is_supported(),
        };

        // Static mock data for active sessions
        this.data.active_sessions = [
            {
                device: 'Chrome on Windows',
                ip: '192.168.1.100',
                location: 'New York, NY',
                last_active: 'Just now',
                is_current: true,
            },
            {
                device: 'Safari on iPhone',
                ip: '192.168.1.105',
                location: 'New York, NY',
                last_active: '2 hours ago',
                is_current: false,
            },
        ];
    }

    async on_load() {
        this.data.two_factor = await Rsx_Two_Factor_Controller.credentials_list();
    }

    on_ready() {
        // Delegated handlers, NAMESPACED AND IDEMPOTENT: this.$ survives every
        // render() while on_ready() re-fires on each one, so one .off('.sps') here
        // clears this component's prior binds before they are re-attached. A one-shot
        // instance flag would be wrong in both directions - flags die with the
        // instance, handlers live on the element.
        this.$.off('.sps');

        const that = this;

        this.$.on('click.sps', '[data-action="enroll_totp"]', async function () {
            await that._enroll('Totp_Enrollment', 'enrolled', 'Set Up Authenticator App');
        });

        this.$.on('click.sps', '[data-action="register_passkey"]', async function () {
            await that._enroll('Passkey_Register', 'registered', 'Register a Passkey');
        });

        this.$.on('click.sps', '[data-action="remove_credential"]', async function () {
            await that._remove_credential(int($(this).data('id')));
        });

        this.$.on('click.sps', '[data-action="regenerate_codes"]', async function () {
            await that._regenerate_codes();
        });
    }

    /**
     * Host one of the framework's enrollment components in a modal and wait it out.
     *
     * The component is mounted from JS rather than placed in a template because mounting is
     * what yields the instance to listen on. The dialog carries no submit button: the
     * ceremony's own controls are inside the component, and the completion event is what
     * closes the dialog.
     *
     * @param {string} name Component name.
     * @param {string} event The event that means "finished".
     * @param {string} title The dialog title.
     */
    async _enroll(name, event, title) {
        const that = this;
        const $container = $('<div>');
        const component = $container.component(name).component();

        component.on(event, async function () {
            await Modal.close();
            that.reload();
        });

        await Modal.show({
            title: title,
            body: component.$,
            buttons: [{
                label: 'Cancel',
                value: false,
                class: 'btn-outline-secondary',
            }],
            max_width: 520,
            closable: true,
        });
    }

    /**
     * Remove one factor. The endpoint answers the refreshed state, but the page reloads
     * rather than reading it: the recovery codes cascade away with the last factor, so more
     * than the one row can change.
     *
     * @param {number} credential_id
     */
    async _remove_credential(credential_id) {
        const confirmed = await Modal.confirm(
            'Remove Method',
            'Are you sure you want to remove this verification method?\n\nIf it is your last one, your recovery codes are removed with it and two-factor authentication is turned off.',
            'Remove'
        );

        if (!confirmed) {
            return;
        }

        await Rsx_Two_Factor_Controller.credential_remove({ id: credential_id });

        this.reload();
    }

    /**
     * Mint a fresh recovery sheet and show it once - the previous sheet stops working the
     * moment the endpoint returns, which is what the confirmation has to say out loud.
     */
    async _regenerate_codes() {
        const confirmed = await Modal.confirm(
            'Regenerate Recovery Codes',
            'Your existing recovery codes will stop working immediately.\n\nYou will be shown the new codes once.',
            'Regenerate'
        );

        if (!confirmed) {
            return;
        }

        const result = await Rsx_Two_Factor_Controller.recovery_regenerate();

        await this._show_recovery_codes(result.recovery_codes);

        this.reload();
    }

    /**
     * The one-time reveal. A dialog with no endpoint behind it, so it is Modal.show().
     *
     * @param {array} codes
     */
    async _show_recovery_codes(codes) {
        const $body = $('<div>');

        $body.append(
            $('<p>').addClass('text-muted').text(
                'Each code signs you in once if you lose your device. This is the only time they are shown.'
            )
        );

        const $list = $('<ul>').addClass('list-group');

        for (const code of codes) {
            $list.append($('<li>').addClass('list-group-item font-monospace').text(code));
        }

        $body.append($list);

        await Modal.show({
            title: 'Your New Recovery Codes',
            body: $body,
            buttons: [{
                label: 'I have saved these',
                value: true,
                class: 'btn-primary',
                default: true,
            }],
            max_width: 480,
            closable: false,
        });
    }

    // Breadcrumb methods
    async breadcrumb_label_active() {
        return 'Manage your password and active sessions';
    }
}
