/**
 * Wysiwyg_File_Blot - the Quill embed that holds a file inside Wysiwyg_Input:
 * `<span class="Inline_File_Embed" data-file-id="N">`, non-editable, drawn as an
 * Inline_File_Card. While its file is still uploading it carries data-pending (a per-upload
 * token) and no id.
 *
 * Inline_File_Tokens converts between this element and the stored {{#file:N}} token, so a
 * loaded document and a live insert produce the same DOM. Quill is a CDN global that loads
 * after the bundle, so the blot class is built from Quill's Embed the first time an editor
 * initializes (register()), not at file scope.
 */
class Wysiwyg_File_Blot {
    /** The toolbar attach button's icon, in Quill's toolbar icon style. */
    static ATTACH_ICON = '<svg viewBox="0 0 18 18"><path class="ql-stroke" d="M11.5 5.5 6.6 10.4a1.5 1.5 0 0 0 2.1 2.1l5.3-5.3a3 3 0 0 0-4.2-4.2L4.4 8.4a4.5 4.5 0 0 0 6.4 6.4l4-4"/></svg>';

    /**
     * Register the blot with Quill, once.
     */
    static register() {
        if (Quill.imports['formats/inline_file']) {
            return;
        }

        const Embed = Quill.import('blots/embed');
        const blot = class extends Embed {
            static create(value) {
                const node = super.create(value);
                node.setAttribute('data-file-id', value && value.id ? str(value.id) : '');
                if (value && value.pending) {
                    node.setAttribute('data-pending', value.pending);
                }

                // Moved into the embed's non-editable content node by Embed's constructor;
                // the card is drawn inside it.
                $(node).append($('<span class="Inline_File_Embed__slot">'));

                return node;
            }

            /**
             * What getSemanticHTML() emits for the embed: the bare element, never the drawn
             * card (whose block markup would split the paragraph when reparsed).
             * Inline_File_Tokens.from_editor_html() turns it into the stored token.
             */
            html() {
                return $('<span class="Inline_File_Embed">')
                    .attr('data-file-id', this.domNode.getAttribute('data-file-id') || '')[0].outerHTML;
            }

            static value(node) {
                return {
                    id: node.getAttribute('data-file-id') || '',
                    pending: node.getAttribute('data-pending') || null,
                };
            }
        };

        blot.blotName = 'inline_file';
        blot.tagName = 'span';
        blot.className = 'Inline_File_Embed';

        Quill.register('formats/inline_file', blot);
    }
}
