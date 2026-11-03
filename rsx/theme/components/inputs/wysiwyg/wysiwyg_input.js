/**
 * Wysiwyg_Input - WYSIWYG editor widget using Quill.
 *
 * Edits a Rich_Text value and nothing else. ACCEPTS names the type, so wiring this widget
 * to a column whose $text_types entry is not Rich_Text is refused at the moment a value
 * arrives, rather than discovered when someone reads the rendered page.
 *
 * val() gets and sets a Rich_Text INSTANCE, never a string. Everything a caller would
 * otherwise have to handle belongs to the type:
 *
 *   - the write filter runs in Rich_Text (client-side at from_editor(), authoritatively
 *     again on the server), so no call site sanitizes before saving;
 *   - display goes through the type's PRINTER component, so no call site chooses between
 *     escape_html() and sanitize_rich_text_html();
 *   - a server-rendered document uses Rich_Text::to_html(), so no Blade template decides
 *     whether to escape.
 *
 * That is the point of the text-type system: the encoding is declared once on the model
 * and every sink asks the value, instead of every sink remembering.
 *
 * FILES. When the application accepts uploads (window.rsxapp.files.uploads_enabled - a
 * file.upload.authorize handler is registered), a file dropped onto the editor, pasted into
 * it, or picked with the toolbar's attach button is uploaded and embedded at that point as
 * a non-editable card (wysiwyg_file_blot.js). The stored value carries it as the token
 * {{#file:<attachment id>}}; saving the record makes the record own the file (Rich_Text).
 * Without uploads the editor keeps Quill's own image handling and offers no attach button.
 */
class Wysiwyg_Input extends Form_Input_Abstract {
    // A NAME, not a class reference - see Raw_Text_Input for why.
    static ACCEPTS = 'Rich_Text';

    on_create() {
        super.on_create();
        this.quill = null;
        this.state.file_cards = {};
        this.state.upload_seq = 0;
    }

    /**
     * @returns {Rich_Text|null}
     */
    _get_value() {
        if (!this.quill) {
            return null;
        }

        // Each embedded file's element back to its {{#file:N}} token (see the class docblock).

        // getSemanticHTML(), NOT `root.innerHTML`. The editor's live DOM is Quill's
        // RENDERING, not its document: it carries `<span class="ql-ui">` chrome nodes and
        // encodes a bullet list as `<ol><li data-list="bullet">`. Storing that meant
        // saving editor internals into the column, and worse - Rich_Text's filter drops
        // data-* attributes, so `data-list="bullet"` was stripped on the way in and a
        // bulleted list came back as a NUMBERED one.
        //
        // getSemanticHTML() is Quill's own answer to "give me this document as portable
        // HTML": plain `<ul><li>`, no chrome, nothing that depends on Quill to interpret.
        // That is what a text type should hold - the column outlives whichever editor
        // happens to be writing to it.
        return Rich_Text.from_editor(Inline_File_Tokens.from_editor_html(this.quill.getSemanticHTML()));
    }

    /**
     * @param {Rich_Text|null} value
     */
    _set_value(value) {
        if (!this.quill) {
            return;
        }

        // Editing always works on the raw form: the editor IS the thing that understands
        // this encoding, which is why the type hands it the storage string directly.
        const raw = value === null || value === undefined ? '' : value.to_storage();

        // dangerouslyPasteHTML, NOT `root.innerHTML = raw`, and this is a data-integrity
        // fix rather than a style preference.
        //
        // Quill keeps its own document model and treats the DOM as its rendering of that
        // model. Assigning innerHTML puts nodes on screen that the model has never heard
        // of, and the next reconciliation deletes them - so a stored `<ul><li>` list
        // appeared for one frame and was then silently removed. Because _get_value()
        // reads back from the DOM, saving such a record DESTROYED the list, with no
        // error anywhere.
        //
        // Content authored in this editor round-trips either way (Quill emits markup it
        // already understands), which is why this survived: it only bites HTML that
        // reached the column from somewhere else - an import, a migration through
        // Rich_Text::from_plain_text(), or a seed.
        //
        // "dangerously" refers to pasting untrusted HTML. This value was filtered by
        // Rich_Text on write and again by sanitize_rich_text_html() client-side, so what arrives here
        // has already been through the trust boundary twice.
        this.quill.setContents([]);
        this.quill.clipboard.dangerouslyPasteHTML(Inline_File_Tokens.to_editor_html(raw));
        this.$sid('hidden_input').val(raw);
        this._draw_file_embeds();
    }

    /**
     * Draw the card of every embedded file not drawn yet, in one request.
     */
    _draw_file_embeds() {
        Inline_File_Renderer.fill(this.quill.root, { interactive: false, cache: this.state.file_cards });
    }

    on_ready() {
        const that = this;

        // Wait for Quill to be loaded, then initialize
        quill_ready(function() {
            that._initialize_quill();
            that._mark_ready();
        });
    }

    _initialize_quill() {
        const that = this;
        const uploads_enabled = !!window.rsxapp.files.uploads_enabled;

        Wysiwyg_File_Blot.register();

        const insert_group = uploads_enabled ? ['link', 'image', 'inline_file'] : ['link', 'image'];
        if (uploads_enabled) {
            Quill.import('ui/icons')['inline_file'] = Wysiwyg_File_Blot.ATTACH_ICON;
        }

        // Initialize Quill editor
        this.quill = new Quill(this.$sid('editor')[0], {
            theme: 'snow',
            placeholder: this.args.placeholder || 'Enter text...',
            modules: {
                toolbar: {
                    container: [
                        [{ 'header': [1, 2, 3, false] }],
                        ['bold', 'italic', 'underline', 'strike'],
                        ['blockquote', 'code-block'],
                        [{ 'list': 'ordered'}, { 'list': 'bullet' }, { 'list': 'check' }],
                        [{ 'indent': '-1'}, { 'indent': '+1' }],
                        insert_group,
                        ['clean']
                    ],
                    handlers: {
                        inline_file: function () {
                            that._choose_files();
                        },
                    },
                },
            }
        });

        if (uploads_enabled) {
            // Quill's uploader module already turns a drop (at the drop point) and a paste of
            // files (at the selection) into upload(range, files); it would embed images as
            // base64. Every file is uploaded and embedded instead.
            this.quill.getModule('uploader').upload = function (range, files) {
                that._upload_files(range.index, Array.from(files));
            };
            this.$sid('editor').find('.ql-inline_file').attr('title', 'Attach a file');
        }

        // Update hidden input on text change and trigger events.
        //
        // The `source` check is required: Quill fires text-change for PROGRAMMATIC edits
        // too ('api'), and _set_value() above is one. _notify_input() means "the USER
        // changed this" - firing it while loading a record would mark the field dirty
        // before anyone touched it, and Rsx_Form's dirty tracking deliberately refuses to
        // let later data overwrite a dirty field. The form would then ignore its own
        // populate().
        this.quill.on('text-change', function (delta, old_delta, source) {
            that.$sid('hidden_input').val(that.quill.root.innerHTML);

            if (source === 'user') {
                that._notify_input(that.val());
            }
        });
    }

    /**
     * The toolbar's attach button: pick files, embed them at the selection as it was BEFORE
     * the file dialog took focus.
     */
    _choose_files() {
        const range = this.quill.getSelection(true);
        const index = range ? range.index : this.quill.getLength() - 1;
        const $picker = $('<input type="file" multiple>');
        $picker.on('change', () => {
            this._upload_files(index, Array.from($picker[0].files));
        });
        $picker[0].click();
    }

    /**
     * Upload files and embed each at `index`, in order. Each shows a loading card at once;
     * a file that fails is removed and the reason shown.
     *
     * @param {number} index
     * @param {File[]} files
     */
    _upload_files(index, files) {
        for (const file of files) {
            const pending = 'upload-' + Date.now() + '-' + (++this.state.upload_seq);
            this.quill.insertEmbed(index, 'inline_file', { id: '', pending: pending }, 'user');
            index++;
            this.quill.setSelection(index, 0, 'silent');
            this._upload_one(file, pending);
        }
    }

    /**
     * @param {File} file
     * @param {string} pending The pending embed's token.
     */
    async _upload_one(file, pending) {
        const $pending = () => $(this.quill.root).find('.Inline_File_Embed[data-pending="' + pending + '"]');
        $pending().find('.Inline_File_Embed__slot').component('Inline_File_Card', { interactive: false });

        try {
            const form_data = new FormData();
            form_data.append('file', file);
            const result = await this.alive(Ajax.upload(form_data));
            const card = await this.alive(Inline_File_Controller.resolve_attachment({ key: result.attachment.key }));
            this.state.file_cards[card.file_attachment_id] = card;
            this._replace_pending(pending, { id: card.file_attachment_id });
        } catch (error) {
            this._replace_pending(pending, null);
            Modal.error(error, 'Could not attach ' + file.name);
        }
    }

    /**
     * Swap a pending embed for the finished one (or remove it when `value` is null).
     */
    _replace_pending(pending, value) {
        const $node = $(this.quill.root).find('.Inline_File_Embed[data-pending="' + pending + '"]');
        if (!$node.exists()) {
            return;   // the user deleted the placeholder while it uploaded
        }
        const blot = Quill.find($node[0]);

        const index = this.quill.getIndex(blot);
        this.quill.deleteText(index, 1, 'user');
        if (value) {
            this.quill.insertEmbed(index, 'inline_file', value, 'user');
            this._draw_file_embeds();
        }
    }
}
