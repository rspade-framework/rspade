/**
 * Rsx_Behaviors - Core Framework User Experience Enhancements
 *
 * This class provides automatic quality-of-life behaviors that improve the default
 * browser experience for RSX applications. These behaviors are transparent to
 * application developers and run automatically on framework initialization.
 *
 * These behaviors use jQuery event delegation to handle both existing and dynamically
 * added content. They are implemented with low priority to allow application code to
 * override default behaviors when needed.
 *
 * @internal Framework use only - not part of public API
 */
class Rsx_Behaviors {
    static _on_framework_core_init() {
        if (Rsx.is_ssr()) return;
        Rsx_Behaviors._init_ignore_invalid_anchor_links();
        Rsx_Behaviors._trim_copied_text();
    }

    /**
     * - Anchor link handling: Prevents broken "#" links from causing page jumps or URL changes
     * - Ignores "#" (empty hash) to prevent scroll-to-top behavior
     * - Ignores "#placeholder*" links used as route placeholders during development
     * - Validates anchor targets exist before allowing navigation
     * - Preserves normal anchor behavior when targets exist
     */
    static _init_ignore_invalid_anchor_links() {
        return; // disabled for now - make this into a configurable option

        // Use event delegation on document to handle all current and future anchor clicks
        // Use mousedown instead of click to run before most application handlers
        $(document).on('mousedown', 'a[href^="#"]', function (e) {
            const $link = $(this);
            const href = $link.attr('href');

            // Check if another handler has already prevented default
            if (e.isDefaultPrevented()) {
                return;
            }

            // Allow data-rsx-allow-hash attribute to bypass this behavior
            if ($link.data('rsx-allow-hash')) {
                return;
            }

            // Handle empty hash - prevent scroll to top
            if (href === '#') {
                e.preventDefault();
                e.stopImmediatePropagation();
                return false;
            }

            // Handle placeholder links used during development
            if (href.startsWith('#placeholder')) {
                e.preventDefault();
                e.stopImmediatePropagation();
                return false;
            }

            // For other hash links, check if target exists
            const targetId = href.substring(1);
            if (targetId) {
                // Check for element with matching ID or name attribute
                const targetExists = document.getElementById(targetId) !== null || document.querySelector(`[name="${targetId}"]`) !== null;

                if (!targetExists) {
                    // Target doesn't exist - prevent navigation
                    e.preventDefault();
                    e.stopImmediatePropagation();
                    return false;
                }
                // Target exists - allow normal anchor behavior
            }
        });
    }

    /**
     * - Copy text trimming: Automatically removes leading/trailing whitespace from copied text
     * - Hold Shift to preserve whitespace
     * - Skips trimming in code blocks, textareas, and contenteditable elements
     */
    static _trim_copied_text() {
        document.addEventListener('copy', function (event) {
            // Don't trim if user is holding Shift (allows copying with whitespace if needed)
            if (event.shiftKey) return;

            let selection = window.getSelection();
            let selected_text = selection.toString();

            // Don't trim if selection is empty
            if (!selected_text) return;

            // Don't trim if copying from code blocks, textareas, or content-editable (preserve formatting)
            let container = selection.getRangeAt(0).commonAncestorContainer;
            if (container.nodeType === 3) container = container.parentNode; // Text node to element
            if (container.closest('pre, code, .code-block, textarea, [contenteditable="true"]')) return;

            let trimmed_text = selected_text.trim();

            // Only modify if there's actually whitespace to trim
            if (trimmed_text !== selected_text && trimmed_text.length > 0) {
                event.preventDefault();
                event.clipboardData.setData('text/plain', trimmed_text);
                console.log('Copy: trimmed whitespace from selection');
            }
        });
    }

}
