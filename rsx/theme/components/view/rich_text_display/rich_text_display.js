/**
 * Rich_Text_Display - the PRINTER for Rich_Text (see the template).
 *
 * A file embedded in the text is stored as a {{#file:N}} token. on_render() turns each into
 * a loading card at once, so the token text is never seen; on_ready() fetches every card the
 * text names in one call and draws them (Inline_File_Renderer).
 */
class Rich_Text_Display extends Component {
    on_render() {
        this.state.file_ids = Inline_File_Renderer.expand(this.$[0]);
    }

    async on_ready() {
        if (this.state.file_ids.length) {
            await this.alive(Inline_File_Renderer.fill(this.$[0]));
        }
    }
}
