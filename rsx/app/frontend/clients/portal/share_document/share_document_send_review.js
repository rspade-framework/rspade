/**
 * Share_Document_Send_Review
 *
 * See share_document_send_review.jqhtml. The email renders in a sandboxed frame, so its
 * markup and styles meet neither the page nor its theme.
 */
class Share_Document_Send_Review extends Component {
    on_render() {
        this.$sid('preview').prop('srcdoc', this.args.preview.html);

        this.$.off('click.share_review').on('click.share_review', '[data-link]', async function () {
            const $button = $(this);

            await navigator.clipboard.writeText($button.attr('data-link'));
            $button.text('Copied');
        });
    }
}
