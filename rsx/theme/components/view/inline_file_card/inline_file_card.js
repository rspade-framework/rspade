/**
 * Inline_File_Card - see the template. A ready card opens Attachment_Preview_Modal on click
 * unless $interactive is false.
 */
class Inline_File_Card extends Component {
    on_render() {
        // $card is a tri-state, not an optional value: undefined while loading, null for
        // a file that is gone or not visible, the card once it is.
        const card = this.args.card;
        const ready = card !== undefined && card !== null;
        const interactive = ready && this.args.interactive !== false;

        this.$.toggleClass('Inline_File_Card--interactive', interactive);
        this.$.toggleClass('Inline_File_Card--image', ready && card.is_image === true);
        this.$.off('click.inline_file');
        if (interactive) {
            this.$.on('click.inline_file', (e) => {
                e.preventDefault();
                Attachment_Preview_Modal.show(card);
            });
        }
    }
}
