/**
 * Settings User Settings Action
 *
 * User preferences. ONE section is real: the timezone preference, which reads and
 * writes the framework's own Rsx_Timezone_Controller (the same setter the early-boot
 * auto-set uses - see Rsx_Timezone_Auto). Everything else on this page is still the
 * original static scaffolding.
 */
@route('/frontend/settings/user_settings')
@layout('Frontend_Spa_Layout')
@layout('Settings_Layout')
@spa('Frontend_Spa_Controller::index')
@title('User Settings')
@auth('is_logged_in')
class Settings_User_Settings_Action extends Spa_Action {
    scaffolded = true;

    // The timezone and appearance sections are REAL (they load and save through the
    // framework's own Rsx_Timezone_Controller / Rsx_Dark_Mode_Controller). The remaining
    // controls - notifications, privacy, language/date format - are still a static demo
    // with no persistence layer, and their Save/Reset buttons are non-functional.
    // Escalated: those need a real settings model + load/save endpoints to round-trip.

    on_create() {
        // Instance property, not this.data: this.data is cached, and a cached
        // "settled" would lie on a revisit while revalidation was still in flight.
        this._settings_settled = false;

        this.data.timezone_options = [];
        this.data.timezone_form_data = {
            timezone: '',
            timezone_auto: true,
        };

        this.data.theme_options = [];
        this.data.theme_form_data = {
            dark_mode: Rsx_Dark_Mode.MODE_AUTO,
        };

        // UI state: whether the post-change forced-navigation handler is armed.
        this.state.forced_navigation_installed = false;
    }

    async on_load() {
        const [options, settings, theme] = await Promise.all([
            Rsx_Timezone_Controller.timezone_options(),
            Rsx_Timezone_Controller.get_settings(),
            Rsx_Dark_Mode_Controller.get_settings(),
        ]);

        this.data.timezone_options = options;

        this.data.theme_options = theme.options;
        this.data.theme_form_data = {
            dark_mode: theme.mode,
        };

        this.data.timezone_form_data = {
            // The selector shows the EFFECTIVE zone: a user who never chose one is
            // rendering in the site default, and showing an empty select would suggest
            // the app has no opinion about their clock.
            timezone: settings.timezone ?? settings.resolved_timezone,
            timezone_auto: settings.timezone_auto,
        };
    }

    /**
     * Both sections load from the same on_load(), so both forms wear the overlay for
     * the same window.
     *
     * @param {boolean} loading
     */
    _set_form_loading(loading) {
        const that = this;

        foreach(['timezone_form', 'theme_form'], function (sid) {
            const form = that.sid(sid);
            if (form) {
                form.set_loading(loading);
            }
        });
    }

    on_render() {
        // Armed on EVERY render until this instance's load settles - renders rebuild
        // the DOM, so the overlay has to be state rather than a one-shot operation.
        this._set_form_loading(!this._settings_settled);
    }

    on_ready() {
        const that = this;
        const auto_input = this.sid('timezone_auto_input');
        const select_input = this.sid('timezone_input');
        const form = this.sid('timezone_form');

        // Ready means this action's load is complete, by definition - so this is where
        // the overlay comes off, and why it could never have been raised here. The
        // framework only re-renders when loaded data CHANGED, so a cache-match revisit
        // keeps the cached form instance: seed it explicitly.
        this._settings_settled = true;
        form.vals(this.data.timezone_form_data);
        this.sid('theme_form').vals(this.data.theme_form_data);
        this._set_form_loading(false);

        // The form has applied the loaded values by now, so the interlock reads real
        // state here.
        this._apply_timezone_interlock();

        // 'input', NOT 'val': the base val() setter fires 'val' on EVERY change,
        // including the programmatic one Rsx_Form makes when it seeds the form - and
        // component events are sticky, so a 'val' subscription registered here would
        // fire immediately on that seeding and undo the loaded state. 'input' is
        // raised only by real user interaction with the widget.
        auto_input.on('input', function () {
            that._apply_timezone_interlock();
        });

        // A manual choice means the user is overriding the browser: the toggle goes off
        // in the UI, and the save posts timezone_auto=false with it.
        select_input.on('input', function () {
            auto_input.val(false);
            that._apply_timezone_interlock();
        });

        // 'submitted' is the form's successful-submit event, and its payload is the
        // server's result.
        form.on('submitted', function (component, result) {
            that._on_timezone_saved(result);
        });

        this.sid('theme_form').on('submitted', function (component, result) {
            that._on_theme_saved(result);
        });
    }

    /**
     * The theme is rendered SERVER-SIDE onto <body> (that is what stops a dark-mode user
     * seeing a white flash), so a saved change cannot be applied to the page currently on
     * screen - only a real request can paint it.
     *
     * Spa.disable() is how that is arranged: the SPA keeps working, but the next
     * navigation - a link click or a programmatic redirect alike - becomes a full page
     * load, which re-renders <body> in the new theme. Deliberately not a reload here: the
     * user may still be changing other settings on this page, and yanking the page out
     * from under them to recolour it would be worse than the mismatch.
     */
    _on_theme_saved(result) {
        if (result && result.changed === true) {
            Spa.disable();
            Flash_Alert.success('Theme saved. The app will switch over when you navigate away.');

            return;
        }

        Flash_Alert.success('Theme saved.');
    }

    /**
     * Auto ON means the browser owns the zone, so the selector is not the user's to
     * edit; unchecking hands it back. The value is still submitted either way
     * (Select_Input.val() reads a disabled widget).
     */
    _apply_timezone_interlock() {
        const auto_on = this.sid('timezone_auto_input').val() === '1';
        const select_input = this.sid('timezone_input');

        if (auto_on) {
            select_input.tom_select.disable();
        } else {
            select_input.tom_select.enable();
        }
    }

    /**
     * @param {Object} result - {changed, timezone} from Rsx_Timezone_Controller
     */
    _on_timezone_saved(result) {
        if (result && result.changed === true) {
            // Every server-formatted datetime already on the screen - and in every page
            // the SPA would render next from cached client state - was produced in the
            // OLD zone. We do not reload out from under the user, but the next time they
            // leave this screen by a link, they leave through a full page load.
            this._install_forced_navigation();
            Flash_Alert.success('Timezone updated. The rest of the app will refresh when you navigate away.');

            return;
        }

        Flash_Alert.success('Timezone settings saved.');
    }

    /**
     * Arm a ONE-TIME capture-phase click handler: the next click on an internal link
     * navigates the browser instead of dispatching through the SPA.
     *
     * Capture phase, on document, so it runs before the SPA's own link interception.
     * It is deliberately NOT removed when this action stops - the whole point is that
     * it survives to the navigation that fires it. A programmatic navigation
     * (Spa.dispatch from code) is not a link click and bypasses it.
     */
    _install_forced_navigation() {
        if (this.state.forced_navigation_installed) {
            return;
        }

        this.state.forced_navigation_installed = true;

        const handler = function (event) {
            const anchor = event.target.closest('a[href]');
            if (!anchor) {
                return;
            }

            const href = anchor.getAttribute('href');
            if (!href || href.startsWith('#') || href.startsWith('mailto:') || href.startsWith('tel:')) {
                return;
            }

            if (anchor.target && anchor.target !== '_self') {
                return;
            }

            // Same-origin only: an external link already leaves the app.
            const url = new URL(anchor.href, document.location.href);
            if (url.origin !== document.location.origin) {
                return;
            }

            event.preventDefault();
            event.stopPropagation();
            document.removeEventListener('click', handler, true);

            document.location.href = anchor.href;
        };

        document.addEventListener('click', handler, true);
    }

    // Breadcrumb methods
    async breadcrumb_label_active() {
        return 'Notifications, privacy, and preferences';
    }
}
