/**
 * Inline_File_Tokens - the {{#file:<attachment id>}} token a Rich_Text value embeds a file
 * with, converted to and from the elements the editor and the display draw.
 *
 * The stored form is plain text inside the HTML, so these work on TEXT NODES of a parsed
 * document: a token never matches inside an attribute or a tag, and nothing here builds
 * HTML by string concatenation.
 */
class Inline_File_Tokens {
    /**
     * One embed, capturing the id. Rich_Text::FILE_EMBED_PATTERN is the PHP twin.
     * @returns {RegExp}
     */
    static pattern() {
        return /\{\{#file:(\d+)\}\}/g;
    }

    /**
     * The attachment ids a stored value embeds, first appearance first.
     * @param {string} raw
     * @returns {number[]}
     */
    static ids_in(raw) {
        const ids = [];
        for (const match of str(raw || '').matchAll(Inline_File_Tokens.pattern())) {
            const id = int(match[1]);
            if (!ids.includes(id)) {
                ids.push(id);
            }
        }

        return ids;
    }

    /**
     * Replace every token in the text under `root` with the element make_element(id) builds.
     * @param {Node} root
     * @param {function(number): Element} make_element
     * @returns {number[]} The ids replaced, first appearance first.
     */
    static expand(root, make_element) {
        const ids = [];
        const walker = document.createTreeWalker(root, NodeFilter.SHOW_TEXT);
        const text_nodes = [];
        while (walker.nextNode()) {
            if (Inline_File_Tokens.pattern().test(walker.currentNode.nodeValue)) {
                text_nodes.push(walker.currentNode);
            }
        }

        for (const text_node of text_nodes) {
            const text = text_node.nodeValue;
            const fragment = document.createDocumentFragment();
            let last = 0;
            for (const match of text.matchAll(Inline_File_Tokens.pattern())) {
                fragment.appendChild(document.createTextNode(text.slice(last, match.index)));
                const id = int(match[1]);
                fragment.appendChild(make_element(id));
                if (!ids.includes(id)) {
                    ids.push(id);
                }
                last = match.index + match[0].length;
            }
            fragment.appendChild(document.createTextNode(text.slice(last)));
            text_node.parentNode.replaceChild(fragment, text_node);
        }

        return ids;
    }

    /**
     * Stored HTML -> the same HTML with each token as the editor's embed element
     * (`<span class="Inline_File_Embed" data-file-id="N">`), ready for Quill to load.
     * @param {string} raw
     * @returns {string}
     */
    static to_editor_html(raw) {
        // Parsed into an inert <template> (nothing in it runs or loads), and the stored value
        // was filtered on write; sanitize_rich_text_html() keeps it to the same allow-list.
        const template = $('<template>')[0];
        template.innerHTML = sanitize_rich_text_html(raw);
        Inline_File_Tokens.expand(template.content, (id) => $('<span class="Inline_File_Embed">').attr('data-file-id', str(id))[0]);

        return template.innerHTML;
    }

    /**
     * Editor HTML -> stored HTML: each embed element with an id becomes its token again, and
     * an embed still uploading (no id yet) is dropped.
     * @param {string} html
     * @returns {string}
     */
    static from_editor_html(html) {
        // An inert <template>: nothing in it runs or loads. Not sanitized HERE because the
        // filter drops the data-file-id this reads; the result is the editor's value, which
        // Rich_Text.from_editor() filters before anything keeps it.
        const template = $('<template>')[0];
        template.innerHTML = html; // @JS-RAW-HTML-01-EXCEPTION parsed into an inert template element and re-sanitized by Rich_Text.from_editor()
        for (const element of Array.from(template.content.querySelectorAll('.Inline_File_Embed'))) {
            const id = element.getAttribute('data-file-id');
            element.replaceWith(document.createTextNode(id ? '{{#file:' + id + '}}' : ''));
        }

        return template.innerHTML;
    }
}
