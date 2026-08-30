/**
 * Add_Api_Key_Modal - Modal for creating new API keys
 *
 * Displays form to collect key name, creates the key, and shows the
 * plaintext key (only time it's visible) in a success modal.
 *
 * Returns true if key was created, false on cancel.
 */
class Add_Api_Key_Modal extends Modal_Abstract {
    /**
     * Show add API key modal
     *
     * @returns {Promise<boolean>} True if key created, false on cancel
     */
    static async show() {
        const result = await Modal.form({
            title: 'Generate New API Key',
            component: 'Add_Api_Key_Modal_Form',
            submit_label: 'Generate Key',
        });

        // If key was created successfully, show the success modal with copyable key
        if (result && result.key) {
            await Add_Api_Key_Modal._show_key_created(result);
        }

        return result || false;
    }

    /**
     * Show modal with the newly created key
     *
     * @param {Object} result The create_key result containing the plaintext key
     */
    static async _show_key_created(result) {
        // Create component instance for the modal body
        const $container = $('<div>');
        const key_display = $container.component('Api_Key_Created_Modal_Body', {
            name: result.name,
            api_key: result.key,
            scopes: result.scopes
        }).component();

        // Wait for component to be ready
        await new Promise((resolve) => {
            key_display.on('ready', () => resolve());
        });

        await Modal.show({
            title: 'API Key Created',
            body: key_display.$,
            buttons: [{
                label: 'Close',
                value: true,
                class: 'btn-primary',
                default: true
            }],
            max_width: 600,
            closable: true,
            close_on_submit: true
        });
    }
}
