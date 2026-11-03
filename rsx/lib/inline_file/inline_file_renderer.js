/**
 * Inline_File_Renderer - draws the file embeds in a piece of rich text as Inline_File_Card.
 *
 *     const ids = Inline_File_Renderer.expand(container);   // tokens -> loading cards (sync)
 *     await Inline_File_Renderer.fill(container);           // ONE resolve_files call
 *
 * Rich_Text_Display does both; the editor calls fill() over its own embed elements.
 */
class Inline_File_Renderer {
    /**
     * Replace each {{#file:N}} token in the container's text with a loading card host.
     * @param {Element} container
     * @returns {number[]} The ids found.
     */
    static expand(container) {
        return Inline_File_Tokens.expand(container, (id) => $('<span class="Inline_File_Embed">').attr('data-file-id', str(id))[0]);
    }

    /**
     * Fetch the cards for every embed element in the container that has not been drawn yet,
     * in one call, and draw them.
     * @param {Element} container
     * @param {Object} [options]
     * @param {boolean} [options.interactive=true] Passed to each card.
     * @param {Object} [options.cache] id -> card map, read before fetching and filled after.
     * @returns {Promise<void>}
     */
    static async fill(container, options = {}) {
        const interactive = options.interactive !== false;
        const cache = options.cache || {};
        const hosts = Array.from(container.querySelectorAll('.Inline_File_Embed[data-file-id]'))
            .filter((host) => host.getAttribute('data-file-id') && !host.hasAttribute('data-drawn'));
        if (!hosts.length) {
            return;
        }

        const slot_of = (host) => host.querySelector('.Inline_File_Embed__slot') || host;
        for (const host of hosts) {
            $(slot_of(host)).empty().component('Inline_File_Card', { interactive });
        }

        const wanted = [];
        for (const host of hosts) {
            const id = int(host.getAttribute('data-file-id'));
            if (!(id in cache) && !wanted.includes(id)) {
                wanted.push(id);
            }
        }

        if (wanted.length) {
            const result = await Inline_File_Controller.resolve_files({ ids: wanted });
            for (const id of wanted) {
                cache[id] = result.files[id] || null;
            }
        }

        for (const host of hosts) {
            const id = int(host.getAttribute('data-file-id'));
            host.setAttribute('data-drawn', '1');
            $(slot_of(host)).empty().component('Inline_File_Card', { card: cache[id], interactive });
        }
    }
}
