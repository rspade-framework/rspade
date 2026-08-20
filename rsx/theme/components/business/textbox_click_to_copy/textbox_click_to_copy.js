/**
 * Textbox_Click_To_Copy
 *
 * Read-only text input with click-to-copy functionality.
 * See textbox_click_to_copy.jqhtml for full documentation.
 *
 * JavaScript Responsibilities:
 * - Handle click events on input and button
 * - Copy value to clipboard using Clipboard API
 * - Show visual feedback (icon swap to checkmark)
 * - Reset icon after delay
 */
class Textbox_Click_To_Copy extends Component {
    on_ready() {
        // Prevent focus on the input (including programmatic focus from modals)
        // This keeps it purely as a click-to-copy element, not a focusable input
        this.$sid('input').on('focus', (e) => {
            e.target.blur();
        });

        // Handle click on input field - copy to clipboard
        this.$sid('input').click(() => {
            this.copy_to_clipboard();
        });

        // Handle click on copy button
        this.$sid('copy_btn').click(() => {
            this.copy_to_clipboard();
        });
    }

    /**
     * Copy the value to clipboard and show visual feedback
     */
    async copy_to_clipboard() {
        const value = this.args.value;

        try {
            // Use Clipboard API to copy text
            await navigator.clipboard.writeText(value);

            // Show flash alert
            Flash_Alert.info('Copied to clipboard', 2000);

            // Show success feedback - swap icon to checkmark
            this.$sid('icon').removeClass('bi-clipboard').addClass('bi-check-lg text-success');

            // Reset icon after 2 seconds
            setTimeout(() => {
                this.$sid('icon').removeClass('bi-check-lg text-success').addClass('bi-clipboard');
            }, 2000);
        } catch (err) {
            console.error('Failed to copy to clipboard:', err);

            // Show error flash alert
            Flash_Alert.error('Failed to copy to clipboard');

            // Also show error icon feedback
            this.$sid('icon').removeClass('bi-clipboard').addClass('bi-x-lg text-danger');

            setTimeout(() => {
                this.$sid('icon').removeClass('bi-x-lg text-danger').addClass('bi-clipboard');
            }, 2000);
        }
    }
}
