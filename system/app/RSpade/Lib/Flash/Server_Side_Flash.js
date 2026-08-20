/**
 * Server_Side_Flash - Bridge between server-generated flash messages and Flash_Alert display
 *
 * Processes flash_alerts arrays from server (page renders and Ajax responses)
 * and displays them using the Flash_Alert component.
 *
 * Integration points:
 * - Page render: window.rsxapp.flash_alerts populated by bundle renderer
 * - Ajax responses: response.flash_alerts added to Ajax success/error responses
 *
 * Initialization:
 * - Automatically called on framework init hook
 * - Restores persisted queue state from sessionStorage before processing server messages
 */
class Server_Side_Flash {
    /**
     * Framework initialization hook
     * Restores queue state and processes server flash messages
     * @private
     */
    static _on_framework_core_init() {
        console.log('[Server_Side_Flash] Framework init hook called');

        // Restore any persisted queue state from sessionStorage first
        console.log('[Server_Side_Flash] Calling Flash_Alert._restore_queue_state()');
        Flash_Alert._restore_queue_state();

        // Then process server-generated flash messages
        const has_server_messages = !!(window.rsxapp && window.rsxapp.flash_alerts);
        console.log('[Server_Side_Flash] Checking for server messages:', {
            has_rsxapp: !!window.rsxapp,
            has_flash_alerts: has_server_messages,
            flash_alerts: window.rsxapp?.flash_alerts
        });

        if (has_server_messages) {
            console.log('[Server_Side_Flash] Processing server messages');
            Server_Side_Flash.process(window.rsxapp.flash_alerts);
        }
    }

    /**
     * Process an array of flash alert messages
     * @param {Array} flash_alerts - Array of {type: string, message: string} objects
     */
    static process(flash_alerts) {
        if (!Array.isArray(flash_alerts)) {
            return;
        }

        flash_alerts.forEach((alert) => {
            const method = alert.type; // 'success', 'error', 'info', 'warning'

            // Call Flash_Alert method if it exists
            if (Flash_Alert[method] && typeof Flash_Alert[method] === 'function') {
                Flash_Alert[method](alert.message);
            } else {
                console.error('Unknown flash alert type:', alert.type);
            }
        });
    }
}
