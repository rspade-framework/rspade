/**
 * Turnstile_Input
 *
 * See Turnstile_Input.jqhtml for the usage contract. This file owns the widget's lifecycle:
 * loading Cloudflare's script through Turnstile, rendering the widget into the $sid container,
 * writing the resulting token into the hidden input, and resetting after a failed submit.
 *
 * NOT A Form_Input_Abstract, deliberately. Form_Input_Abstract and Rsx_Form are APP-owned
 * (rsx/theme/components/), and a framework-core component may not depend on app classes. So
 * instead of implementing an input contract, the template renders an ordinary
 * <input type="hidden" name="__turnstile">, which Rsx_Form.vals()'s hidden-input sweep collects
 * and a native <form> POST serializes. One component, both transports, zero coupling.
 *
 * THE FIELD NAME IS A CONTRACT: FIELD and INACTIVE below mirror Rsx_Turnstile::FIELD and
 * Rsx_Turnstile::INACTIVE. The server requires the field on EVERY submit - the sentinel when
 * the feature is off, a token when it is on - so neither value may be renamed on one side only.
 *
 * TOKENS ARE SINGLE-USE. Every submit consumes the token, including a submit the server
 * rejected, so the widget is reset whenever the surrounding Rsx_Form reports an error.
 *
 * See: php artisan rsx:man turnstile
 */
class Turnstile_Input extends Component {
    /** Mirrors Rsx_Turnstile::FIELD - the fixed POST field name. */
    static FIELD = '__turnstile';

    /** Mirrors Rsx_Turnstile::INACTIVE - the value posted while the feature is disabled. */
    static INACTIVE = 'inactive';

    on_create() {
        // Cloudflare's widget id, for reset()/remove(). Null whenever no widget is live.
        this._widget_id = null;

        // One-time guard for the Rsx_Form 'error' subscription (on_ready runs on every render).
        this._form_bound = false;

        if (this.args.theme === undefined || this.args.theme === null) {
            this.args.theme = 'auto';
        }
        if (this.args.size === undefined || this.args.size === null) {
            this.args.size = 'normal';
        }
        if (this.args.appearance === undefined || this.args.appearance === null) {
            this.args.appearance = 'always';
        }
    }

    on_render() {
        const active = Turnstile.is_active();

        // A render() recreates child DOM, so any iframe we had is gone with its container -
        // drop the stale widget and start the state clean. on_ready re-activates immediately
        // afterwards (Turnstile.init calls back synchronously once the script has loaded), so
        // clearing here costs nothing and keeps this hook idempotent.
        this._remove_widget();

        this.$sid('widget').toggle(active);
        this.$sid('placeholder').toggle(!active);
        this.$sid('token').val(active ? '' : Turnstile_Input.INACTIVE);
    }

    on_ready() {
        const that = this;

        // Tokens are SINGLE-USE and the server validates BEFORE field validation, so every
        // failed submit - even one that failed on an unrelated field - has already spent the
        // token. Without this reset the user's retry dies on timeout-or-duplicate.
        //
        // jqhtml events are sticky: registering after an error already fired replays it, which
        // resets a freshly-minted widget once. Harmless (the widget just re-solves).
        if (!this._form_bound) {
            const form = this.closest('.Rsx_Form');
            if (form) {
                this._form_bound = true;
                form.on('error', function () {
                    that._reset();
                });
            }
        }

        // No-op when inactive: init() never calls back, and the placeholder is already showing.
        Turnstile.init(function () {
            that._activate();
        });
    }

    on_stop() {
        this._remove_widget();
    }

    /**
     * Render Cloudflare's widget into our container. Safe to call again after a re-render.
     */
    _activate() {
        if (this._stopped || !this.$.is_in_dom() || !Turnstile.is_active()) {
            return;
        }

        const that = this;

        this._remove_widget();

        this._widget_id = window.turnstile.render(this.$sid('widget')[0], {
            sitekey: Turnstile.site_key(),
            theme: this.args.theme,
            size: this.args.size,
            appearance: this.args.appearance,

            // We own the transport - Cloudflare must not inject its own cf-turnstile-response
            // field alongside our __turnstile input.
            'response-field': false,

            callback: function (token) {
                that.$sid('token').val(token);
            },
            'expired-callback': function () {
                that._reset();
            },
            'error-callback': function () {
                // The challenge itself failed. Drop the value and leave the widget to Cloudflare's
                // own retry UI; an empty field is rejected by the server, which is correct.
                that.$sid('token').val('');
            },
        });
    }

    /**
     * Clear the token and ask Cloudflare for a fresh challenge.
     */
    _reset() {
        // Inactive: the sentinel is the correct value and there is no widget to reset.
        if (!Turnstile.is_active()) {
            return;
        }

        this.$sid('token').val('');

        if (this._widget_id === null || !window.turnstile) {
            return;
        }

        window.turnstile.reset(this._widget_id);
    }

    /**
     * Drop the live widget, if there is one.
     */
    _remove_widget() {
        if (this._widget_id === null) {
            return;
        }

        const widget_id = this._widget_id;
        this._widget_id = null;

        if (!window.turnstile) {
            return;
        }

        window.turnstile.remove(widget_id);
    }
}
