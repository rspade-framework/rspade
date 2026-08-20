/**
 * Resend_Invite_Modal_Body
 *
 * Body for the "Resend Invitation" modal - shows a ready-to-paste invitation
 * message (company name + registration link) in a read-only, selectable textbox.
 * See resend_invite_modal_body.jqhtml for the markup.
 */
class Resend_Invite_Modal_Body extends Component {
    on_render() {
        // Set the value from JS rather than via <%= %> in the template: jqhtml
        // does not reliably interpolate inside a <textarea> (an HTML raw-text element).
        this.$sid('message').val(this.args.invite_message);
    }
}
