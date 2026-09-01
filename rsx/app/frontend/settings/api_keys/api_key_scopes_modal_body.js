/**
 * Api_Key_Scopes_Modal_Body
 *
 * See api_key_scopes_modal_body.jqhtml for the argument contract.
 *
 * JavaScript responsibilities:
 * - fetch the key's scopes in on_load(), so the template renders once with them in hand.
 */
class Api_Key_Scopes_Modal_Body extends Component {
    on_create() {
        this.data.name = '';
        this.data.scopes = null;
        this.data.unrestricted = true;
    }

    async on_load() {
        const key = await Frontend_Settings_Api_Keys_Controller.get_key_scopes({ id: this.args.key_id });

        this.data.name = key.name;
        this.data.scopes = key.scopes;
        this.data.unrestricted = key.unrestricted;
    }
}
