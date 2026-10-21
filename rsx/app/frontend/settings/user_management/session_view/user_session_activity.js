/**
 * User_Session_Activity - one page of a session's activity. See the .jqhtml.
 *
 * Paging moves this.args.offset and reloads - "args changed -> reload()" - rather than
 * keeping an accumulating buffer coherent.
 */
class User_Session_Activity extends Component {
    on_create() {
        this.data.rows = [];
        this.data.total = 0;
        this.data.offset = 0;
        this.data.limit = 0;
        this.data.loading = true;
    }

    async on_load() {
        const page = await Frontend_Settings_User_Management_Controller.get_user_session_activity({
            id: this.args.user_id,
            user_session_id: this.args.user_session_id,
            offset: this.args.offset || 0,
        });

        this.data.rows = page.rows;
        this.data.total = page.total;
        this.data.offset = page.offset;
        this.data.limit = page.limit;
        this.data.loading = false;
    }

    on_render() {
        // Rebound on every render (the buttons are recreated), namespaced and idempotent;
        // the offsets are read when the click lands.
        this.$sid('older').off('click.user_session_activity').on('click.user_session_activity', () => {
            this.args.offset = this.data.offset + this.data.limit;
            this.reload();
        });

        this.$sid('newer').off('click.user_session_activity').on('click.user_session_activity', () => {
            this.args.offset = Math.max(0, this.data.offset - this.data.limit);
            this.reload();
        });
    }
}
