/**
 * Modal Static API
 *
 * Primary interface for displaying modals throughout the application.
 * Provides simple methods for common dialogs and flexible options for custom modals.
 *
 * Usage:
 *   await Modal.alert("File saved")
 *   if (await Modal.confirm("Delete?")) { ... }
 *   let name = await Modal.prompt("Enter name:")
 *   let result = await Modal.show({ title, body, buttons })
 */
class Modal {
    // Internal state
    static _queue = [];
    static _current = null;
    static _initialized = false;
    static _backdrop = null;
    static _original_body_overflow = null;
    static _original_body_padding = null;
    static _unlock_timeout = null;
    static _last_close_timestamp = 0;

    /**
     * Framework initialization hook
     * Registers global event handlers for SPA integration
     */
    static on_app_modules_init() {
        // Close any open modal when SPA navigation starts
        // This prevents stale modals from persisting across page transitions
        Rsx.on('spa_dispatch_start', () => {
            if (Modal.is_open()) {
                Modal.close();
            }
        });
    }

    /**
     * Initialize global handlers (called automatically on first modal)
     * @private
     */
    static _init() {
        if (this._initialized) return;
        this._initialized = true;

        // Create shared backdrop element
        this._backdrop = $('<div class="modal-backdrop fade"></div>');
        $('body').append(this._backdrop);
    }

    /**
     * Calculate scrollbar width
     * @private
     * @returns {number}
     */
    static _get_scrollbar_width() {
        // Create temporary element to measure scrollbar width
        const $outer = $('<div>').css({
            visibility: 'hidden',
            overflow: 'scroll',
            width: '100px',
            position: 'absolute',
            top: '-9999px',
        });
        $('body').append($outer);

        const width_with_scrollbar = $outer[0].offsetWidth;

        const $inner = $('<div>').css('width', '100%');
        $outer.append($inner);

        const width_without_scrollbar = $inner[0].offsetWidth;

        $outer.remove();

        return width_with_scrollbar - width_without_scrollbar;
    }

    /**
     * Lock body scroll and compensate for scrollbar width
     * Only locks if we haven't already saved the original state (first modal in chain)
     * @private
     */
    static _lock_body_scroll() {
        // Cancel any pending unlock timeout
        if (this._unlock_timeout) {
            clearTimeout(this._unlock_timeout);
            this._unlock_timeout = null;
        }

        // Only lock scroll if we haven't already saved state (first modal)
        // This is the true indicator - not backdrop visibility which can be transitional
        if (this._original_body_overflow === null) {
            const $body = $('body');

            // Store original values
            this._original_body_overflow = $body.css('overflow');
            this._original_body_padding = $body.css('padding-right');

            // Check if body currently has vertical scroll
            const has_scrollbar = document.body.scrollHeight > window.innerHeight;

            // If there's a scrollbar, add padding to compensate for its removal
            if (has_scrollbar) {
                const scrollbar_width = this._get_scrollbar_width();
                const current_padding = int(this._original_body_padding) || 0;
                $body.css('padding-right', current_padding + scrollbar_width + 'px');
            }

            // Lock scroll
            $body.css('overflow', 'hidden');
        }
    }

    /**
     * Unlock body scroll and restore original state
     * Uses delayed check to ensure no other modals are opening
     * @private
     */
    static _unlock_body_scroll() {
        // Clear any existing timeout
        if (this._unlock_timeout) {
            clearTimeout(this._unlock_timeout);
        }

        // Minimal delay before unlocking
        this._unlock_timeout = setTimeout(() => {
            // Double-check no modal is currently open and queue is empty
            if (!this._current && this._queue.length === 0) {
                const $body = $('body');

                // Restore original values
                if (this._original_body_overflow !== null) {
                    $body.css('overflow', this._original_body_overflow);
                    this._original_body_overflow = null;
                }

                if (this._original_body_padding !== null) {
                    $body.css('padding-right', this._original_body_padding);
                    this._original_body_padding = null;
                }
            }

            this._unlock_timeout = null;
        }, 50); // Minimal safety buffer
    }

    /**
     * Show the shared backdrop (instant - no animation)
     * @private
     */
    static async _show_backdrop() {
        if (!this._backdrop.hasClass('show')) {
            // Lock body scroll before showing backdrop
            this._lock_body_scroll();

            this._backdrop.css('display', 'block').addClass('show');
            // No delay - return immediately
        }
    }

    /**
     * Hide the shared backdrop (instant - no animation)
     * @private
     */
    static async _hide_backdrop() {
        this._backdrop.removeClass('show').css('display', 'none');

        // Unlock body scroll after backdrop is hidden
        this._unlock_body_scroll();
    }

    /**
     * Create a new Rsx_Modal instance
     * @private
     */
    static async _create_modal() {
        // Create modal component using jQuery plugin
        const $modal_element = $('<div>');

        // Create component instance directly (setter returns $element, call .component() to get instance)
        const modal_instance = $modal_element.component('Rsx_Modal', {}).component();

        // Wait for component to be fully ready (DOM elements queryable)
        await new Promise((resolve) => {
            modal_instance.on('ready', () => {
                console.log('[Modal] Component ready, elements:', {
                    title: modal_instance.$sid('title').length,
                    body: modal_instance.$sid('body').length,
                    footer: modal_instance.$sid('footer').length,
                });
                resolve();
            });
        });

        return modal_instance;
    }

    /**
     * Show a modal and manage queue
     * @private
     */
    static async _show_modal(options) {
        return new Promise((resolve) => {
            this._queue.push({ options, resolve });

            // Process queue if no modal currently showing
            if (!this._current) {
                this._process_queue();
            }
        });
    }

    /**
     * Process the modal queue
     * @private
     */
    static async _process_queue() {
        if (this._queue.length === 0) {
            this._current = null;
            // Hide backdrop when queue is empty
            await this._hide_backdrop();
            return;
        }

        const { options, resolve } = this._queue.shift();

        // Ensure initialized
        this._init();

        // Show backdrop if not already visible (instant - no delay between modals)
        const backdrop_visible = this._backdrop.hasClass('show');
        if (!backdrop_visible) {
            await this._show_backdrop();
        }
        // No delay between sequential modals - immediate transition

        // Create modal instance
        const modal_instance = await this._create_modal();
        this._current = modal_instance;

        // Trigger modal open event
        Rsx.trigger('modal_open', { modal: modal_instance, options });

        // Determine if we should animate based on:
        // 1. Desktop viewport (>= 1000px)
        // 2. More than 1 second since last modal closed
        const viewport_width = $(window).width();
        const is_desktop = viewport_width >= 1000;
        const time_since_last_close = Date.now() - this._last_close_timestamp;
        const should_animate = is_desktop && time_since_last_close > 1000;

        // Show modal and wait for result (modal won't create its own backdrop)
        const result = await modal_instance.show(options, { skip_backdrop: true, animate: should_animate });

        // Record close timestamp BEFORE resolving (ensures it's set before next modal can start)
        this._last_close_timestamp = Date.now();

        // Trigger modal close event before resolving
        Rsx.trigger('modal_close', { modal: modal_instance, result });

        // Resolve the promise with the result
        resolve(result);

        // Clear current and process next
        this._current = null;
        this._process_queue();
    }

    // ================================================================================
    // State Management Methods
    // ================================================================================

    /**
     * Check if a modal is currently open
     * @returns {boolean}
     */
    static is_open() {
        return this._current !== null;
    }

    /**
     * Get the currently open modal instance
     * @returns {Rsx_Modal|null}
     */
    static get_current() {
        return this._current;
    }

    /**
     * Force close the current modal
     * @returns {Promise<void>}
     */
    static async close() {
        if (this._current) {
            await this._current.close(false);
        }
    }

    /**
     * Apply validation errors to the current modal
     * @param {Object} errors - Error object {field: message}
     */
    static apply_errors(errors) {
        if (this._current) {
            this._current.apply_errors(errors);
        }
    }

    // ================================================================================
    // Simple Dialog Methods
    // ================================================================================

    /**
     * Show an alert dialog
     * @param {string|jQuery} title_or_body - Message (if only 1 arg) or Title (if 2 args). Can be string or jQuery element.
     * @param {string|jQuery} body - Message body (if 2 args). Can be string or jQuery element.
     * @param {string} button_label - Button text (default: "OK")
     * @returns {Promise<void>}
     */
    static async alert(title_or_body, body = null, button_label = 'OK') {
        let title = 'Notice';
        let message = title_or_body;

        if (body !== null) {
            title = title_or_body;
            message = body;
        }

        await this._show_modal({
            title: title,
            body: message,
            buttons: [
                {
                    label: button_label,
                    value: true,
                    class: 'btn-primary',
                    default: true,
                },
            ],
            closable: true,
            close_on_submit: true,
        });
    }

    /**
     * Show a confirmation dialog
     * @param {string|jQuery} title_or_body - Message (if 1-2 args) or Title (if 3-4 args). Can be string or jQuery element.
     * @param {string|jQuery} body - Message body (optional). Can be string or jQuery element.
     * @param {string} confirm_label - Confirm button text (default: "Confirm")
     * @param {string} cancel_label - Cancel button text (default: "Cancel")
     * @returns {Promise<boolean>}
     */
    static async confirm(title_or_body, body = null, confirm_label = 'Confirm', cancel_label = 'Cancel') {
        let title = 'Confirm';
        let message = title_or_body;

        if (body !== null) {
            title = title_or_body;
            message = body;
        }

        const result = await this._show_modal({
            title: title,
            body: message,
            buttons: [
                {
                    label: cancel_label,
                    value: false,
                    class: 'btn-secondary',
                },
                {
                    label: confirm_label,
                    value: true,
                    class: 'btn-primary',
                    default: true,
                },
            ],
            closable: true,
            close_on_submit: true,
        });

        return result === true;
    }

    /**
     * Show a prompt dialog for text input
     * @param {string|jQuery} title_or_body - Message (if 1-3 args) or Title (if 4 args). Can be string or jQuery element.
     * @param {string|jQuery} body - Message body (optional). Can be string or jQuery element.
     * @param {string} default_value - Default input value
     * @param {boolean} multiline - Show textarea instead of input
     * @param {string} error - Optional error message to display as validation feedback
     * @param {boolean} allow_empty - Allow empty input (default: false - requires non-empty after trim)
     * @returns {Promise<string|false>}
     */
    static async prompt(title_or_body, body = null, default_value = '', multiline = false, error = null, allow_empty = false) {
        let title = 'Input';
        let message = title_or_body;

        // Handle overloaded arguments
        if (typeof body === 'string' && body !== '') {
            title = title_or_body;
            message = body;
        }

        // Create input element with minimum width constraints
        const $input = multiline
            ? $('<textarea class="form-control" rows="4" style="min-width: 315px;"></textarea>')
            : $('<input type="text" class="form-control" style="min-width: 245px;">');

        $input.val(default_value);

        // Mark as invalid if there's an error
        if (error) {
            $input.addClass('is-invalid');
        }

        // Prevent leading spaces when input is otherwise empty (unless allow_empty)
        // Also clear validation error when user starts typing
        if (!allow_empty) {
            $input.on('input', function () {
                const val = $(this).val();
                // If input is only whitespace, clear it
                if (val.length > 0 && val.trim() === '') {
                    $(this).val('');
                }
                // Clear validation error when user types valid content
                if (val.trim() !== '') {
                    $(this).removeClass('is-invalid');
                }
            });
        }

        // Create body with message and input
        let $body;
        if (message instanceof jQuery) {
            // If message is a jQuery element, use it as the container and append input
            $body = message.append($input);
        } else {
            // If message is a string, create wrapper with text and input (36px spacing)
            $body = $('<div class="form-group">').append($('<div style="margin-bottom: 36px;">').text(message)).append($input);
        }

        // Add error feedback container (hidden initially)
        const $error_feedback = $('<div class="invalid-feedback"></div>');
        if (error) {
            $error_feedback.addClass('d-block').text(error);
        }
        $body.append($error_feedback);

        const result = await this._show_modal({
            title: title,
            body: $body,
            buttons: [
                {
                    label: 'Cancel',
                    value: false,
                    class: 'btn-secondary',
                },
                {
                    label: 'Submit',
                    value: null, // Will be replaced by callback
                    class: 'btn-primary',
                    default: true,
                    callback: function () {
                        const raw_value = $input.val();
                        const trimmed_value = raw_value.trim();

                        // Validate non-empty unless allow_empty
                        if (!allow_empty && trimmed_value === '') {
                            $input.addClass('is-invalid');
                            $error_feedback.addClass('d-block').text('This field is required');
                            $input.focus();
                            return false; // Keep modal open
                        }

                        // Return trimmed value (or raw if allow_empty)
                        return allow_empty ? raw_value : trimmed_value;
                    },
                },
            ],
            closable: true,
            close_on_submit: true,
            max_width: 500,
        });

        // Focus and select input after modal shows
        requestAnimationFrame(() => {
            $input.focus();
            if (!multiline) {
                $input.select();
            }
        });

        return result;
    }

    /**
     * Show a select dialog with TomSelect dropdown
     * @param {string|jQuery} title_or_body - Message (if 1-3 args) or Title (if 4+ args). Can be string or jQuery element.
     * @param {string|jQuery} body - Message body (optional). Can be string or jQuery element.
     * @param {Array} options - Array of options: [{value: 'val', label: 'Label'}, ...] or simple array ['val1', 'val2']
     * @param {string} default_value - Default selected value
     * @param {string} placeholder - Placeholder text for empty selection
     * @param {boolean} allow_empty - Allow empty selection (default: false - requires selection)
     * @returns {Promise<string|false>}
     */
    static async select(title_or_body, body = null, options = [], default_value = '', placeholder = 'Select an option...', allow_empty = false) {
        let title = 'Select';
        let message = title_or_body;

        // Handle overloaded arguments
        if (typeof body === 'string' && body !== '') {
            title = title_or_body;
            message = body;
        }

        // Create container for the Select_Input component
        const $select_container = $('<div style="min-width: 280px;"></div>');

        // Create the Select_Input component
        const select_component = $select_container.component('Select_Input', {
            options: options,
            placeholder: placeholder
        }).component();

        // Wait for component to be ready
        await new Promise((resolve) => {
            select_component.on('ready', () => resolve());
        });

        // Set default value after component is ready
        if (default_value) {
            select_component.val(default_value);
        }

        // Create body with message and select
        let $body;
        if (message instanceof jQuery) {
            $body = message.append($select_container);
        } else {
            $body = $('<div class="form-group">').append($('<div style="margin-bottom: 36px;">').text(message)).append($select_container);
        }

        // Add error feedback container (hidden initially)
        const $error_feedback = $('<div class="invalid-feedback"></div>');
        $body.append($error_feedback);

        const result = await this._show_modal({
            title: title,
            body: $body,
            buttons: [
                {
                    label: 'Cancel',
                    value: false,
                    class: 'btn-secondary',
                },
                {
                    label: 'Submit',
                    value: null,
                    class: 'btn-primary',
                    default: true,
                    callback: function () {
                        const selected_value = select_component.val();

                        // Validate selection unless allow_empty
                        if (!allow_empty && (!selected_value || selected_value === '')) {
                            // Mark the TomSelect control as invalid
                            $select_container.find('.ts-control').addClass('is-invalid');
                            $error_feedback.addClass('d-block').text('Please select an option');
                            return false; // Keep modal open
                        }

                        return selected_value;
                    },
                },
            ],
            closable: true,
            close_on_submit: true,
            max_width: 500,
        });

        return result;
    }

    /**
     * Show a fatal error dialog with technical details (file, line, backtrace)
     *
     * Used for displaying PHP exceptions and fatal errors in development mode.
     * Shows error details in monospace preformatted text for developer debugging.
     *
     * @param {string|Error|Object} error - Error message string, Error object, or structured error with metadata
     * @param {string} title - Modal title (default: "Fatal Error")
     * @returns {Promise<void>}
     */
    static async fatal_error(error, title = 'Fatal Error') {
        let message = '';

        // Handle different error types
        if (typeof error === 'string') {
            message = error;
        } else if (error instanceof Error) {
            // Check for metadata with file/line info (from Ajax.js fatal error handling)
            if (error.metadata && error.metadata.file && error.metadata.line) {
                message = `Uncaught Fatal Error in ${error.metadata.file}:${error.metadata.line}:\n\n${error.metadata.error || error.message}`;
            } else {
                message = error.message || error.toString();
            }
        } else if (error && error.metadata && error.metadata.file) {
            // Object with metadata (structured fatal error)
            const details = error.metadata;
            message = `Uncaught Fatal Error in ${details.file}:${details.line}:\n\n${details.error || error.message || 'Unknown error'}`;
        } else if (error && error.message) {
            message = error.message;
        } else {
            message = 'An unknown error occurred';
        }

        // Create error body with red alert styling and monospace text
        const $body = $('<div class="alert alert-danger mb-0" role="alert">').append(
            $('<pre class="mb-0" style="white-space: pre-wrap; word-wrap: break-word; font-family: monospace; font-size: 0.9em;">').text(
                message
            )
        );

        await this._show_modal({
            title: title,
            body: $body,
            buttons: [
                {
                    label: 'Close',
                    value: true,
                    class: 'btn-danger',
                    default: true,
                },
            ],
            closable: true,
            close_on_submit: true,
            max_width: 600,
        });
    }

    // ================================================================================
    // Custom Modal Methods
    // ================================================================================

    /**
     * Show a custom modal with specified content and buttons
     * @param {Object} options
     * @returns {Promise<*>}
     */
    static async show(options) {
        const defaults = {
            title: 'Modal',
            body: '',
            buttons: [],
            max_width: 800,
            closable: true,
            close_on_submit: true,
        };

        const final_options = Object.assign({}, defaults, options);

        return await this._show_modal(final_options);
    }

    /**
     * Show a modal with a jqhtml form component
     * @param {Object} options
     * @param {string} options.component - Component class name
     * @param {Object} options.component_args - Arguments to pass to component
     * @param {Function} options.on_submit - Callback function called on submit. Receives form component instance.
     *                                        Return false to keep modal open, or return data to close and resolve.
     * @returns {Promise<Object|false>}
     */
    static async form(options) {
        const defaults = {
            title: 'Form',
            component: null,
            component_args: {},
            max_width: 800,
            closable: true,
            submit_label: 'Submit',
            cancel_label: 'Cancel',
            on_submit: null,
        };

        const final_options = Object.assign({}, defaults, options);

        if (!final_options.component) {
            console.error('Modal.form() requires a component');
            return false;
        }

        // Create component instance (setter returns $element, call .component() to get instance)
        let $component_container = $('<div>');
        let component_instance = $component_container.component(final_options.component, final_options.component_args).component();

        // Wait for component to be ready
        await new Promise((resolve) => {
            component_instance.on('ready', () => resolve());
        });

        // Find a form instance if component instance doesnt have .vals()
        if (!component_instance.vals) {
            let $form = component_instance.$.find('.Rsx_Form');
            if ($form.exists()) {
                component_instance = $form.component();
            }
        }

        // Create buttons
        const buttons = [
            {
                label: final_options.cancel_label,
                value: false,
                class: 'btn-secondary',
            },
            {
                label: final_options.submit_label,
                value: null,
                class: 'btn-primary',
                default: true,
                callback: async function () {
                    // If on_submit callback provided, use it
                    if (final_options.on_submit && typeof final_options.on_submit === 'function') {
                        const result = await final_options.on_submit(component_instance);
                        // If callback returns null/undefined, keep modal open
                        if (result === null || result === undefined) {
                            return false;
                        }
                        // Otherwise (including false), return the result to close modal
                        return result;
                    }

                    // No on_submit callback - get form data and close modal
                    if (component_instance.submit && typeof component_instance.submit === 'function') {
                        return await component_instance.submit();
                    } else if (component_instance.vals && typeof component_instance.vals === 'function') {
                        return component_instance.vals();
                    } else {
                        console.warn('Form component has no submit() or vals() method');
                        return true;
                    }
                },
            },
        ];

        return await this._show_modal({
            title: final_options.title,
            body: component_instance.$,
            buttons: buttons,
            max_width: final_options.max_width,
            closable: final_options.closable,
        });
    }

    /**
     * Show an unclosable modal
     * @param {string} title_or_body
     * @param {string} body
     * @returns {Promise<void>}
     */
    static async unclosable(title_or_body, body = null) {
        let title = 'Please Wait';
        let message = title_or_body;

        if (body !== null) {
            title = title_or_body;
            message = body;
        }

        // Don't wait for this promise - it never resolves until closed manually
        this._show_modal({
            title: title,
            body: message,
            buttons: [], // No buttons
            closable: false, // Can't close
            close_on_submit: false,
        });

        // Wait for next animation frame for modal to render
        await new Promise(resolve => requestAnimationFrame(resolve));
    }

    /**
     * Show a modal with custom jQuery content
     * @param {Object} options
     * @returns {Promise<*>}
     */
    static async custom(options) {
        // Alias for show() - same functionality
        return await this.show(options);
    }

    // ================================================================================
    // Helper Methods
    // ================================================================================

    /**
     * Show an error alert
     * @param {*} errors
     * @param {string} title
     * @returns {Promise<void>}
     */
    static async error(errors, title = 'Error') {
        let message = 'An error occurred';

        // Handle various error formats
        if (typeof errors === 'string') {
            message = errors;
        } else if (errors && 'responseJSON' in errors && 'message' in errors.responseJSON) {
            message = errors.responseJSON.message;
        } else if (errors && 'message' in errors) {
            message = errors.message;
        } else if (errors && typeof errors === 'object') {
            // Try to format error object
            const error_messages = [];
            for (const key in errors) {
                if (is_array(errors[key])) {
                    error_messages.push(errors[key][0]);
                } else {
                    error_messages.push(errors[key]);
                }
            }
            if (error_messages.length > 0) {
                message = error_messages.join('\n');
            }
        }

        await this._show_modal({
            title: title,
            body: message,
            icon: 'exclamation-circle',
            buttons: [
                {
                    label: 'OK',
                    value: true,
                    class: 'btn-danger',
                    default: true,
                },
            ],
            closable: true,
            close_on_submit: true,
        });
    }

    /**
     * Reopen current modal with validation errors
     * @param {Object} errors
     * @returns {Promise<void>}
     */
    static async reopen_with_errors(errors) {
        if (this._current) {
            // Modal is still open, just apply errors
            this.apply_errors(errors);
        } else {
            console.warn('No modal open to apply errors to');
        }
    }
}
