/**
 * Rich_Text - the browser registration for the sanitized-HTML column type.
 *
 * The PHP class of the same name owns the encoding and performs the authoritative filter
 * on every write. The client-side filter below is defense in depth at the editor, not a
 * substitute for it.
 */
class Rich_Text extends Rsx_Text_Abstract {
    static PRINTER = 'Rich_Text_Display';

    static EDITOR = 'Wysiwyg_Input';

    /**
     * DOMPurify, at the moment the editor's content becomes a value. The server filters
     * again on receipt - this one is here so a paste of hostile markup never even lives
     * in the page's own state.
     *
     * @param {string} raw
     * @returns {string}
     */
    static filter_set(raw) {
        return safe_html(raw);
    }

    /**
     * HTML is one of the encodings the browser CAN reduce on its own, so a value straight
     * out of the editor answers without a round trip. This matters in practice: an emptied
     * Quill leaves `<p><br></p>` behind, which the default whitespace check would call
     * non-empty and a section header would render above nothing.
     *
     * @returns {boolean}
     */
    _local_is_empty() {
        return $('<div>').html(this._raw).text().trim() === '';
    }
}
