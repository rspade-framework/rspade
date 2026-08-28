/**
 * Wysiwyg_Input - WYSIWYG editor widget using Quill
 *
 * Implements the form widget interface:
 * - val() - Get/set HTML content (sanitized via safe_html())
 * - seed() - Fills with random content
 *
 * SECURITY NOTE: Data from this widget is rich HTML that will likely be displayed
 * without HTML escaping. To prevent XSS attacks, always sanitize at every step:
 *
 * 1. CLIENT DISPLAY: This widget's val() already sanitizes output, but when
 *    displaying stored content, always use safe_html():
 *        container.innerHTML = safe_html(data.description);
 *
 * 2. SERVER DISPLAY (Blade): Use safe_html() before outputting:
 *        {!! safe_html($project->description) !!}
 *
 * 3. DATABASE SAVE: Sanitize before storing to stop malicious content early:
 *        $model->description = safe_html($params['description']);
 *
 * Defense in depth - sanitize at ALL layers, not just one.
 */
class Wysiwyg_Input extends Form_Input_Abstract {
    on_create() {
        super.on_create();
        this.quill = null;
    }

    _get_value() {
        if (!this.quill) return '';
        return safe_html(this.quill.root.innerHTML);
    }

    _set_value(value) {
        if (value && this.quill) {
            this.quill.root.innerHTML = value;
            this.$sid('hidden_input').val(value);
        }
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

        // Update hidden input on text change and trigger events
        this.quill.on('text-change', function() {
            that.$sid('hidden_input').val(that.quill.root.innerHTML);
            that._notify_input(that.val());
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

        this.val(sample_content);
    }
}
