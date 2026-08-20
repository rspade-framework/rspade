/**
 * View_Transitions - Smooth page-to-page transitions using View Transitions API
 *
 * Enables cross-document view transitions so the browser doesn't paint the new page
 * until it's ready, creating smooth animations between pages.
 *
 * Falls back gracefully if View Transitions API is not available.
 */
class Rsx_View_Transitions {
    /**
     * Called during framework core init phase
     * Checks for View Transitions API support and enables if available
     */
    static _on_framework_core_init() {
        if (Rsx.is_ssr()) return;

        // Check if View Transitions API is supported
        if (!document.startViewTransition) {
            console_debug('VIEW_TRANSITIONS', 'View Transitions API not supported, skipping');
            return;
        }

        // Enable cross-document view transitions via CSS
        Rsx_View_Transitions._inject_transition_css();
    }

    /**
     * Inject CSS to enable cross-document view transitions
     *
     * The @view-transition { navigation: auto; } rule tells the browser to:
     * 1. Capture a snapshot of the current page before navigation
     * 2. Fetch the new page
     * 3. Wait until the new page is fully loaded and painted (document.ready)
     * 4. Animate smoothly between the two states
     *
     * This prevents the white flash during navigation and creates app-like transitions.
     */
    static _inject_transition_css() {
        const style = document.createElement('style');

        style.textContent = `
            @view-transition {
                navigation: auto;
            }

            /* Disable animation - instant transition */
            ::view-transition-group(*),
            ::view-transition-old(*),
            ::view-transition-new(*) {
                animation-duration: 0s;
            }
        `;

        document.head.appendChild(style);
    }
}
