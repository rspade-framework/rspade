/**
 * Quill Editor - Utility Functions
 *
 * Provides utility functions for working with Quill editor.
 */

/**
 * Ensures Quill is loaded before executing callback
 * @param {Function} callback - Function to call when Quill is ready
 */
function quill_ready(callback) {
    if (typeof window.Quill !== 'undefined') {
        callback();
    } else {
        setTimeout(() => quill_ready(callback), 50);
    }
}
