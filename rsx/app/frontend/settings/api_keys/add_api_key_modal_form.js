/**
 * Add_Api_Key_Modal_Form
 *
 * See add_api_key_modal_form.jqhtml for what the form collects.
 *
 * JavaScript responsibilities:
 * - fetch the application's scope presets (on_load - the one Ajax hook);
 * - show or hide the scoped fields as the access mode changes;
 * - keep the effective-access preview in step with the current union, debounced.
 *
 * The preset RULES are held here only to compute the preview's union client-side. What is
 * submitted is the list of preset NAMES, and create_key expands them from config - so the
 * rules in this object are display data, and editing them in a console changes nothing.
 */
class Add_Api_Key_Modal_Form extends Component {
    on_create() {
        this.data.presets = [];

        this.data.access_options = [
            {
                value: 'unrestricted',
                label: 'Unrestricted',
                description: 'The key inherits every permission this user has, now and in future.',
            },
            {
                value: 'scoped',
                label: 'Scoped',
                description: 'The key reaches only the endpoints the rules below grant. It can never exceed this user\'s permissions either way.',
            },
        ];

        // The stdlib debounce - a keystroke in the rules box should not be one round trip.
        // The DELAY is a UI cadence, not a bound on any operation: nothing is cancelled or
        // failed when it elapses, the preview simply refreshes.
        this._refresh_preview = debounce(() => this._resolve_preview(), 250);
    }

    async on_load() {
        const result = await Frontend_Settings_Api_Keys_Controller.get_scope_presets();
        this.data.presets = result.presets;
    }

    on_ready() {
        // Unrestricted is the default because it is what a key has always been: the mode
        // that changes nothing is the one a distracted operator gets.
        this.sid('access_mode').val('unrestricted');
        this._apply_access_mode();

        this.sid('form').on('input', (form, name) => {
            if (name === 'access_mode') {
                this._apply_access_mode();
            }

            this._refresh_preview();
        });

        this._refresh_preview();
    }

    /**
     * Show the rule fields only while the key is scoped.
     */
    _apply_access_mode() {
        this.$sid('scoped_section').toggleClass('d-none', !this._is_scoped());
    }

    _is_scoped() {
        return this.sid('access_mode').val() === 'scoped';
    }

    /**
     * The rule text the current selection amounts to: the ticked presets' rules, then
     * whatever was typed. Order is presentational only - Api_Scopes decides by specificity.
     */
    _scope_text() {
        if (!this._is_scoped()) {
            return '';
        }

        const lines = [];

        for (const name of this.sid('presets').val()) {
            for (const preset of this.data.presets) {
                if (preset.name === name) {
                    lines.push(preset.rules);
                }
            }
        }

        const custom = str(this.sid('custom_rules').val()).trim();
        if (custom !== '') {
            lines.push(custom);
        }

        return lines.join('\n');
    }

    /**
     * Hand the current union to the preview panel. Changing a child's args and reloading it
     * is the sanctioned re-render path: the fetch stays in the panel's own on_load().
     */
    _resolve_preview() {
        const preview = this.sid('preview');
        preview.args.scopes = this._scope_text();

        // In Scoped mode a blank rule set means "nothing chosen", not "everything" - and
        // create_key refuses to mint it for the same reason.
        preview.args.blank_is_unrestricted = !this._is_scoped();
        preview.reload();
    }
}
