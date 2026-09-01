/**
 * Api_Scope_Preview
 *
 * See api_scope_preview.jqhtml for the argument contract.
 *
 * JavaScript responsibilities:
 * - resolve this.args.scopes into the endpoint list, in on_load().
 *
 * The whole component is one Ajax call and a render. It carries no debounce of its own:
 * the OWNER decides how often a rule set is worth resolving (the mint form debounces
 * keystrokes; the view modal resolves exactly once), and a component that debounced
 * internally would make a single deliberate reload() arrive late for no reason.
 */
class Api_Scope_Preview extends Component {
    on_create() {
        // Rendered before the first resolve lands, so the panel has a shape from the start
        // rather than appearing when the answer does.
        this.data.error = null;
        this.data.unrestricted = true;
        this.data.groups = [];
    }

    async on_load() {
        const result = await Frontend_Settings_Api_Keys_Controller.preview_scopes({
            scopes: this.args.scopes || '',
        });

        // A malformed scope comes back as a normal result carrying the validator's message
        // - the operator is mid-keystroke, and half-written scopes are the expected state of
        // this panel, not a failure of the request.
        this.data.error = result.error;
        this.data.groups = result.groups;

        // Blank scopes are unrestricted to Api_Scopes, and to a saved key. They are NOT
        // unrestricted to a form whose mode says "scoped" - see $blank_is_unrestricted.
        this.data.unrestricted = result.unrestricted && this.args.blank_is_unrestricted !== false;
    }
}
