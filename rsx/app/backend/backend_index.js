/**
 * Backend Module JavaScript
 */
class Backend_Index {
    /**
     * Initialize the backend/admin page
     * This method is automatically called by RSX framework for any class with a static on_app_ready() method
     * No manual registration is required
     */
    static on_app_ready() {
        // Only initialize if we're on the backend page
        if (!$(".Backend_Index").exists()) {
            return;
        }
        
        Debugger.console_debug("JS_INIT", "Backend module initialized");
        
        // Add any backend-specific JavaScript here
        // Example: Data tables, charts, admin functionality
        
        // Add active class to current sidebar link
        const currentPath = window.location.pathname;
        $('.sidebar .nav-link').each(function() {
            const $element = $(this);
            if ($element.attr('href') === currentPath) {
                $element.addClass('active');
            }
        });
    }
}