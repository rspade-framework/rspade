/**
 * Form utilities for validation and error handling
 */
class Form_Utils {
    /**
     * Framework initialization hook to register jQuery plugin
     * Creates $.fn.ajax_submit() for form elements
     * @private
     */
    static _on_framework_core_define(params = {}) {
        $.fn.ajax_submit = function(options = {}) {
            const $element = $(this);

            if (!$element.is('form')) {
                throw new Error('ajax_submit() can only be called on form elements');
            }

            const url = $element.attr('action');
            if (!url) {
                throw new Error('Form must have an action attribute');
            }

            const { controller, action } = Ajax.ajax_url_to_controller_action(url);

            return Form_Utils.ajax_submit($element, controller, action, options);
        };
    }

    /**
     * Shows form validation errors
     *
     * REQUIRED HTML STRUCTURE:
     * For inline field errors to display properly, form fields must follow this structure:
     *
     * <div class="form-group">
     *   <label class="form-label" for="field-name">Field Label</label>
     *   <input class="form-control" id="field-name" name="field-name" type="text">
     * </div>
     *
     * Key requirements:
     * - Wrap each field in a container with class "form-group" (or "form-check" / "input-group")
     * - Input must have a "name" attribute matching the error key
     * - Use "form-control" class on inputs for Bootstrap 5 styling
     *
     * Accepts three formats:
     * - String: Single error shown as alert
     * - Array of strings: Multiple errors shown as bulleted alert
     * - Object: Field names mapped to errors, shown inline (unmatched shown as alert)
     *
     * @param {string} parent_selector - jQuery selector for parent element
     * @param {string|Object|Array} errors - Error messages to display
     * @returns {Promise} Promise that resolves when all animations complete
     */
    static apply_form_errors(parent_selector, errors) {
        console.error(errors);

        const $parent = $(parent_selector);

        // Reset the form errors before applying new ones
        Form_Utils.reset_form_errors(parent_selector);

        // Normalize input to standard format
        const normalized = Form_Utils._normalize_errors(errors);

        return new Promise((resolve) => {
            let animations = [];

            if (normalized.type === 'string') {
                // Single error message
                animations = Form_Utils._apply_general_errors($parent, normalized.data);
            } else if (normalized.type === 'array') {
                // Array of error messages
                const deduplicated = Form_Utils._deduplicate_errors(normalized.data);
                animations = Form_Utils._apply_general_errors($parent, deduplicated);
            } else if (normalized.type === 'fields') {
                // Field-specific errors
                const result = Form_Utils._apply_field_errors($parent, normalized.data);
                animations = result.animations;

                // Count matched fields
                const matched_count = Object.keys(normalized.data).length - Object.keys(result.unmatched).length;
                const unmatched_deduplicated = Form_Utils._deduplicate_errors(result.unmatched);
                const unmatched_count = Object.keys(unmatched_deduplicated).length;

                // Show summary alert if there are any field errors (matched or unmatched)
                if (matched_count > 0 || unmatched_count > 0) {
                    // Build summary message
                    let summary_msg = '';
                    if (matched_count > 0) {
                        summary_msg = matched_count === 1
                            ? 'Please correct the error highlighted below.'
                            : 'Please correct the errors highlighted below.';
                    }

                    // If there are unmatched errors, add them as a bulleted list
                    if (unmatched_count > 0) {
                        const summary_animations = Form_Utils._apply_combined_error($parent, summary_msg, unmatched_deduplicated);
                        animations.push(...summary_animations);
                    } else {
                        // Just the summary message, no unmatched errors
                        const summary_animations = Form_Utils._apply_general_errors($parent, summary_msg);
                        animations.push(...summary_animations);
                    }
                }
            }

            // Resolve the promise once all animations are complete
            Promise.all(animations).then(() => {
                // Scroll to error container if it exists
                const component = $parent.component();
                const $error_container = component ? component.$sid('error_container') : $();
                if ($error_container.length > 0) {
                    const container_top = $error_container.offset().top;

                    // Calculate fixed header offset
                    const fixed_header_height = Form_Utils._get_fixed_header_height();

                    // Scroll to position error container 20px below any fixed headers
                    const target_scroll = container_top - fixed_header_height - 20;
                    $('html, body').animate({
                        scrollTop: target_scroll
                    }, 500);
                }

                resolve();
            });
        });
    }

    /**
     * Clears form validation errors and resets all form values to defaults
     * @param {string|jQuery} form_selector - jQuery selector or jQuery object for form element
     */
    static reset(form_selector) {
        const $form = typeof form_selector === 'string' ? $(form_selector) : form_selector;

        Form_Utils.reset_form_errors(form_selector);
        $form.trigger('reset');
    }

    /**
     * Serializes form data into key-value object
     * Returns all input elements with name attributes as object properties
     * @param {string|jQuery} form_selector - jQuery selector or jQuery object for form element
     * @returns {Object} Form data as key-value pairs
     */
    static serialize(form_selector) {
        const $form = typeof form_selector === 'string' ? $(form_selector) : form_selector;
        const data = {};

        $form.serializeArray().forEach((item) => {
            data[item.name] = item.value;
        });

        return data;
    }

    /**
     * Submits form to RSX controller action via AJAX
     * @param {string|jQuery} form_selector - jQuery selector or jQuery object for form element
     * @param {string} controller - Controller class name (e.g., 'User_Controller')
     * @param {string} action - Action method name (e.g., 'save_profile')
     * @param {Object} options - Optional configuration {on_success: fn, on_error: fn}
     * @returns {Promise} Promise that resolves with response data
     */
    static async ajax_submit(form_selector, controller, action, options = {}) {
        const $form = typeof form_selector === 'string' ? $(form_selector) : form_selector;
        const form_data = Form_Utils.serialize($form);

        Form_Utils.reset_form_errors(form_selector);

        try {
            const response = await Ajax.call(controller, action, form_data);

            if (options.on_success) {
                options.on_success(response);
            }

            return response;
        } catch (error) {
            if (error.code === Ajax.ERROR_VALIDATION && error.metadata) {
                await Form_Utils.apply_form_errors(form_selector, error.metadata);
            } else {
                await Form_Utils.apply_form_errors(form_selector, error.message || 'An error occurred');
            }

            if (options.on_error) {
                options.on_error(error);
            }

            throw error;
        }
    }

    /**
     * Removes form validation errors
     * @param {string} parent_selector - jQuery selector for parent element
     */
    static reset_form_errors(parent_selector) {
        const $parent = $(parent_selector);

        // Remove flash messages
        $('.flash-messages').remove();

        // Remove alert-danger messages
        $parent.find('.alert-danger').remove();

        // Remove validation error classes and text from form elements
        $parent.find('.is-invalid').removeClass('is-invalid');
        $parent.find('.invalid-feedback').remove();
    }

    // ------------------------

    /**
     * Normalizes error input into standard formats
     * @param {string|Object|Array} errors - Raw error input
     * @returns {Object} Normalized errors as {type: 'string'|'array'|'fields', data: ...}
     * @private
     */
    static _normalize_errors(errors) {
        // Handle null/undefined
        if (!errors) {
            return { type: 'string', data: 'An error has occurred' };
        }

        // Handle string
        if (typeof errors === 'string') {
            return { type: 'string', data: errors };
        }

        // Handle array
        if (Array.isArray(errors)) {
            // Array of strings - general errors
            if (errors.every((e) => typeof e === 'string')) {
                return { type: 'array', data: errors };
            }
            // Array with object as first element - extract it
            if (errors.length > 0 && typeof errors[0] === 'object') {
                return Form_Utils._normalize_errors(errors[0]);
            }
            // Empty or mixed array
            return { type: 'array', data: [] };
        }

        // Handle object - check for Laravel response wrapper
        if (typeof errors === 'object') {
            // Unwrap {errors: {...}} or {error: {...}}
            const unwrapped = errors.errors || errors.error;
            if (unwrapped) {
                return Form_Utils._normalize_errors(unwrapped);
            }

            // Convert Laravel validator format {field: [msg1, msg2]} to {field: msg1}
            // Skip reserved keys (prefixed with underscore) - these are metadata, not field errors
            const normalized = {};
            for (const field in errors) {
                if (errors.hasOwnProperty(field) && !field.startsWith('_')) {
                    const value = errors[field];
                    if (Array.isArray(value) && value.length > 0) {
                        normalized[field] = value[0];
                    } else if (typeof value === 'string') {
                        normalized[field] = value;
                    } else {
                        normalized[field] = String(value);
                    }
                }
            }

            return { type: 'fields', data: normalized };
        }

        // Final catch-all*
        return { type: 'string', data: String(errors) };
    }

    /**
     * Removes duplicate error messages from array or object values
     * @param {Array|Object} errors - Errors to deduplicate
     * @returns {Array|Object} Deduplicated errors
     * @private
     */
    static _deduplicate_errors(errors) {
        if (Array.isArray(errors)) {
            return [...new Set(errors)];
        }

        if (typeof errors === 'object') {
            const seen = new Set();
            const result = {};
            for (const key in errors) {
                const value = errors[key];
                if (!seen.has(value)) {
                    seen.add(value);
                    result[key] = value;
                }
            }
            return result;
        }

        return errors;
    }

    /**
     * Applies field-specific validation errors to form inputs
     * @param {jQuery} $parent - Parent element containing form
     * @param {Object} field_errors - Object mapping field names to error messages
     * @returns {Object} Object containing {animations: Array, unmatched: Object}
     * @private
     */
    static _apply_field_errors($parent, field_errors) {
        const animations = [];
        const unmatched = {};

        for (const field_name in field_errors) {
            const error_message = field_errors[field_name];
            const $input = $parent.find(`[name="${field_name}"]`);

            if (!$input.length) {
                unmatched[field_name] = error_message;
                continue;
            }

            const $error = $('<div class="invalid-feedback"></div>').html(error_message);
            const $target = $input.closest('.form-group, .form-check, .input-group');

            if (!$target.length) {
                unmatched[field_name] = error_message;
                continue;
            }

            $input.addClass('is-invalid');
            $error.appendTo($target);
            animations.push($error.hide().fadeIn(300).promise());
        }

        return { animations, unmatched };
    }

    /**
     * Applies combined error message with summary and unmatched field errors
     * @param {jQuery} $parent - Parent element containing form
     * @param {string} summary_msg - Summary message (e.g., "Please correct the errors below")
     * @param {Object} unmatched_errors - Object of field errors that couldn't be matched to fields
     * @returns {Array} Array of animation promises
     * @private
     */
    static _apply_combined_error($parent, summary_msg, unmatched_errors) {
        const animations = [];
        const component = $parent.component();
        const $error_container = component ? component.$sid('error_container') : $();
        const $target = $error_container.length > 0 ? $error_container : $parent;

        // Create alert with summary message and bulleted list of unmatched errors
        const $alert = $('<div class="alert alert-danger" role="alert"></div>');

        // Add summary message if provided
        if (summary_msg) {
            $('<p class="mb-2"></p>').text(summary_msg).appendTo($alert);
        }

        // Add unmatched errors as bulleted list
        if (Object.keys(unmatched_errors).length > 0) {
            const $list = $('<ul class="mb-0"></ul>');
            for (const field_name in unmatched_errors) {
                const error_msg = unmatched_errors[field_name];
                $('<li></li>').html(error_msg).appendTo($list);
            }
            $list.appendTo($alert);
        }

        if ($error_container.length > 0) {
            animations.push($alert.hide().appendTo($target).fadeIn(300).promise());
        } else {
            animations.push($alert.hide().prependTo($target).fadeIn(300).promise());
        }

        return animations;
    }

    /**
     * Applies general error messages as alert box
     * @param {jQuery} $parent - Parent element to prepend alert to
     * @param {string|Array} messages - Error message(s) to display
     * @returns {Array} Array of animation promises
     * @private
     */
    static _apply_general_errors($parent, messages) {
        const animations = [];

        // Look for a specific error container div (e.g., in Rsx_Form component)
        const component = $parent.component();
        const $error_container = component ? component.$sid('error_container') : $();
        const $target = $error_container.length > 0 ? $error_container : $parent;

        if (typeof messages === 'string') {
            // Single error - simple alert without list
            const $alert = $('<div class="alert alert-danger" role="alert"></div>').text(messages);
            if ($error_container.length > 0) {
                animations.push($alert.hide().appendTo($target).fadeIn(300).promise());
            } else {
                animations.push($alert.hide().prependTo($target).fadeIn(300).promise());
            }
        } else if (Array.isArray(messages) && messages.length > 0) {
            // Multiple errors - bulleted list
            const $alert = $('<div class="alert alert-danger" role="alert"><ul class="mb-0"></ul></div>');
            const $list = $alert.find('ul');

            messages.forEach((msg) => {
                const text = (msg + '').trim() || 'An error has occurred';
                $('<li></li>').html(text).appendTo($list);
            });

            if ($error_container.length > 0) {
                animations.push($alert.hide().appendTo($target).fadeIn(300).promise());
            } else {
                animations.push($alert.hide().prependTo($target).fadeIn(300).promise());
            }
        } else if (typeof messages === 'object' && !Array.isArray(messages)) {
            // Object of unmatched field errors - convert to array
            const error_list = Object.values(messages)
                .map((v) => String(v).trim())
                .filter((v) => v);
            if (error_list.length > 0) {
                return Form_Utils._apply_general_errors($parent, error_list);
            }
        }

        return animations;
    }

    /**
     * Calculates the total height of fixed/sticky headers at the top of the page
     * @returns {number} Total height in pixels of fixed top elements
     * @private
     */
    static _get_fixed_header_height() {
        let total_height = 0;

        // Find all fixed or sticky positioned elements
        $('*').each(function() {
            const $el = $(this);
            const position = $el.css('position');

            // Only check fixed or sticky elements
            if (position !== 'fixed' && position !== 'sticky') {
                return;
            }

            // Check if element is positioned at or near the top
            const top = parseInt($el.css('top')) || 0;
            if (top > 50) {
                return; // Not a top header
            }

            // Check if element is visible
            if (!$el.is(':visible')) {
                return;
            }

            // Check if element spans significant width (likely a header/navbar)
            const width = $el.outerWidth();
            const viewport_width = $(window).width();
            if (width < viewport_width * 0.5) {
                return; // Too narrow to be a header
            }

            // Add this element's height
            total_height += $el.outerHeight();
        });

        return total_height;
    }
}
