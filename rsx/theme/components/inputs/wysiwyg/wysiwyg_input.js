/**
 * Wysiwyg_Input - WYSIWYG editor widget using Quill.
 *
 * Edits a Rich_Text value and nothing else. ACCEPTS names the type, so wiring this widget
 * to a column whose $text_types entry is not Rich_Text is refused at the moment a value
 * arrives, rather than discovered when someone reads the rendered page.
 *
 * val() gets and sets a Rich_Text INSTANCE, never a string. Everything that used to be a
 * caller's responsibility now belongs to the type:
 *
 *   - the write filter runs in Rich_Text (client-side at from_editor(), authoritatively
 *     again on the server), so no call site sanitizes before saving;
 *   - display goes through the type's PRINTER component, so no call site chooses between
 *     html() and safe_html();
 *   - a server-rendered document uses Rich_Text::to_html(), so no Blade template decides
 *     whether to escape.
 *
 * That is the point of the text-type system: the encoding is declared once on the model
 * and every sink asks the value, instead of every sink remembering.
 */
class Wysiwyg_Input extends Form_Input_Abstract {
    // A NAME, not a class reference - see Raw_Text_Input for why.
    static ACCEPTS = 'Rich_Text';

    on_create() {
        super.on_create();
        this.quill = null;
    }

    /**
     * @returns {Rich_Text|null}
     */
    _get_value() {
        if (!this.quill) {
            return null;
        }

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
        return Rich_Text.from_editor(this.quill.getSemanticHTML());
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
        // Rich_Text::from_string(), or a seed.
        //
        // "dangerously" refers to pasting untrusted HTML. This value was filtered by
        // Rich_Text on write and again by safe_html() client-side, so what arrives here
        // has already been through the trust boundary twice.
        this.quill.setContents([]);
        this.quill.clipboard.dangerouslyPasteHTML(raw);
        this.$sid('hidden_input').val(raw);
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

        // Initialize Quill editor
        this.quill = new Quill(this.$sid('editor')[0], {
            theme: 'snow',
            placeholder: this.args.placeholder || 'Enter text...',
            modules: {
                toolbar: [
                    [{ 'header': [1, 2, 3, false] }],
                    ['bold', 'italic', 'underline', 'strike'],
                    ['blockquote', 'code-block'],
                    [{ 'list': 'ordered'}, { 'list': 'bullet' }],
                    [{ 'indent': '-1'}, { 'indent': '+1' }],
                    ['link', 'image'],
                    ['clean']
                ]
            }
        });

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
     * Seed - Fill with random content for testing
     */
    async seed() {
        if (!this.quill) return;

        const sample_content = `
            <h2>Sample Heading</h2>
            <p>This is a sample paragraph with <strong>bold text</strong> and <em>italic text</em>.</p>
            <ul>
                <li>First bullet point</li>
                <li>Second bullet point</li>
                <li>Third bullet point</li>
            </ul>
            <p>Another paragraph with <a href="#">a sample link</a>.</p>
        `;

        this.val(Rich_Text.from_editor(sample_content));
    }
}
