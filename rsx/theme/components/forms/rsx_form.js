/**
 * Rsx_Form
 *
 * Form container with validation, submission, and widget value management.
 * See rsx_form.jqhtml for full documentation.
 *
 * JavaScript Responsibilities:
 * - Parses and stores initial form data from $data attribute (JSON or object)
 * - Discovers and manages child Widget components via vals() getter/setter
 * - Handles form submission via Ajax to controller/method endpoints
 * - Applies validation errors to fields using Form_Utils
 * - Integrates with Rsx_Tabs for tab-aware error handling
 * - Provides seed() functionality for debug/testing
 * - Manages form state (values, errors) throughout lifecycle
 */
class Rsx_Form extends Component {
    on_create() {
        // Use this.state for form state (not loading from Ajax)
        this.state = {
            values: {}, // Current form values {name: value}
            errors: {}, // Validation errors {name: error_message}
            tabs: null  // Reference to Rsx_Tabs component if present
        };

        // Parse initial data from $data attribute (e.g., from $data=$client)
        let data = this.args.data;

        if (typeof data === 'string') {
            try {
                // Decode HTML entities before parsing JSON
                // This handles cases where JSON is passed through Blade {!! !!} syntax
                const decoded = $('<textarea>').html(data).text();
                data = json_decode(decoded);
            } catch (e) {
                console.error('Form: Failed to parse data JSON string', e);
                data = {};
            }
        }

        if (data && typeof data === 'object') {
            this.state.values = data;
        }
    }

    on_render() {
        // Form value persistence across cache revalidation re-renders.
        // When a form renders from cache, the user may change inputs before
        // the cache revalidates and the form re-renders with fresh data.
        // This system caches user changes and re-applies them after re-render.

        // Get parent component (closest .Component that isn't this one)
        const $parent = this.$.closest('.Component').not(this.$);
        const parent_component = $parent.exists() ? $parent.component() : null;

        // Determine cache storage location and key
        const cache_location = parent_component || this;
        const cache_key = parent_component ? `__formvals_${this._cid}` : '__this_formvals';

        // Initialize cache if it doesn't exist
        if (!cache_location[cache_key]) {
            cache_location[cache_key] = {};
        }
        const cache = cache_location[cache_key];

        // If cache has values from prior render, merge into state.values
        // These will be applied when on_ready calls vals()
        if (Object.keys(cache).length > 0) {
            Object.assign(this.state.values, cache);
        }

        // Register input listeners on all input components to track user changes
        const that = this;
        this.$.shallowFind('.Form_Input_Abstract').each(function () {
            const $input = $(this);
            const component = $input.component();
            if (component && 'on' in component) {
                const input_name = $input.data('name');
                if (input_name) {
                    component.on('input', function (comp, value) {
                        cache[input_name] = value;
                    });
                }
            }
        });
    }

    on_ready() {
        const that = this;

        // Validate that error container exists
        if (!this.$sid('error_container').exists()) {
            console.log(this.$.html());
            throw new Error(
                'Rsx_Form requires an error container with $sid="error_container". ' +
                    'Add <div $sid="error_container"></div> to your form template for displaying validation and error messages.'
            );
        }

        // Set up seed button handler if in debug mode
        if (window.rsxapp.debug && this.$sid('seed_btn').exists()) {
            that.$sid('seed_btn').on('click', function () {
                that.seed();
            });
        }

        // Find child Rsx_Tabs component if present for error handling integration
        const tabs_el = this.$.find('.Rsx_Tabs').first();
        if (tabs_el.length) {
            that.state.tabs = tabs_el.component();
        }

        // Automatically wire all submit buttons to call form submit()
        this.$.find('button[type="submit"]').each(function () {
            $(this).on('click', function (e) {
                e.preventDefault();
                that.submit();
            });
        });

        // Notify all fields to load their initial values
        // This happens in on_ready to ensure all Form_Field children are initialized
        this.vals(this.state.values);

        // Hide loading spinner and show form content (without re-rendering)
        this.$sid('loader').hide();
        this.$sid('form_content').show();
    }

    // Getter or setter for all form values, similar to jquery val
    vals(values) {
        if (values) {
            // Setter

            this.$.shallowFind('.Form_Input_Abstract').each(function () {
                let $input = $(this);
                let component = $input.component();
                // val() is guaranteed by Form_Input_Abstract contract
                let input_name = $input.data('name');
                if (input_name in values) {
                    component.val(values[input_name]);
                }
            });

            return null;
        } else {
            // Getter
            let data = {};

            // Get input component values
            this.$.shallowFind('.Form_Input_Abstract').each(function () {
                let $input = $(this);
                let component = $input.component();
                // val() is guaranteed by Form_Input_Abstract contract
                let input_name = $input.data('name');
                data[input_name] = component.val();
            });

            // Also get regular hidden inputs (non-widget inputs). Exclude the
            // framework-injected CSRF field (attached to native form submits) so it
            // never pollutes AJAX params - the token rides the X-CSRF-Token header.
            this.$.find('input[type="hidden"][name]').each(function () {
                let $input = $(this);
                let name = $input.attr('name');
                if (name && name !== '_csrf_token') {
                    data[name] = $input.val();
                }
            });

            return data;
        }
    }

    get_error(name) {
        return this.state.errors[name];
    }

    /**
     * Render an error in the form's error container
     *
     * Handles both field-specific validation errors and generic errors.
     * Can be called by external handlers (e.g., modal on_submit) or internally
     * by the form's own submit() method.
     *
     * @param {Error|Object} error - Error object from Ajax call
     */
    async render_error(error) {
        // Announce the failure before rendering it. Fires for BOTH submit()'s own catch and an
        // external handler (Modal.form's on_submit) that calls render_error() directly, which is
        // what makes it the one reliable "this form was rejected" signal a child can subscribe to.
        this.trigger('error', error);

        // Handle validation errors - apply to fields
        if (error.code === Ajax.ERROR_VALIDATION && error.metadata) {
            await Form_Utils.apply_form_errors(this.$, error.metadata);

            // Notify tabs of validation errors for error badges and auto-switching
            if (this.state.tabs) {
                this.state.tabs.handle_validation_errors(error.metadata);
            }

            // Check if an alert was shown (Form_Utils shows alert if there are unmatched errors)
            const $existing_alert = this.$sid('error_container').find('.alert-danger');

            // If no alert shown yet (all errors were inline), show generic validation message
            if (!$existing_alert.exists()) {
                const $alert = $('<div class="alert alert-danger" role="alert"></div>')
                    .text('There was an error with your request, please correct the issues below:');
                this.$sid('error_container').append($alert);
            }

            // Scroll to alert if needed
            this._scroll_to_alert();

            return;
        }

        // For non-form errors (fatal, auth, network, etc.), render in form's error container
        Rsx.render_error(error, this.$sid('error_container'));

        // Scroll to alert for fatal errors too
        this._scroll_to_alert();
    }

    /**
     * Scroll to error alert if it's above the viewport + 70px threshold
     * @private
     */
    _scroll_to_alert() {
        const $alert = this.$sid('error_container').find('.alert-danger');

        if (!$alert.exists()) {
            return;
        }

        const alert_top = $alert.offset().top;
        const viewport_top = $(window).scrollTop();
        const threshold = viewport_top + 70;

        // If alert is above viewport + 70px, scroll to position it at viewport + 70px
        if (alert_top < threshold) {
            const target_scroll = alert_top - 70;
            $('html, body').animate({
                scrollTop: target_scroll
            }, 300);
        }
    }

    async submit() {
        // Clear any previous errors
        Form_Utils.reset_form_errors(this.$);
        this.$sid('error_container').empty();

        // Clear tab error badges if tabs are present
        if (this.state.tabs) {
            this.state.tabs.clear_error_badges();
        }

        // Serialize all field values
        let values = this.vals();

        // Call submit handler
        if (!this.args.controller || !this.args.method) {
            console.error('Form: No controller/method provided');
            throw new Error('Form configuration error: Missing controller or method');
        }

        try {
            // Build Ajax URL from controller and method
            const ajax_url = `/_ajax/${this.args.controller}/${this.args.method}`;

            // Call Ajax endpoint - response is directly what PHP returned
            const result = await Ajax.call(ajax_url, values);

            // Counterpart to the 'error' event above: the endpoint accepted the submission.
            this.trigger('success', result);

            // Success! Handle result
            if (result && result.redirect) {
                // Redirect to URL
                window.location.href = result.redirect;
            } else {
                // Success without redirect
                console.log('Form submitted successfully', result);
            }
        } catch (error) {
            // Render error (handles both validation and generic errors)
            await this.render_error(error);
        }
    }

    async seed() {
        const promises = [];
        this.$.shallowFind('.Form_Field').each(function () {
            let component = $(this).component();
            if (component && 'seed' in component) {
                promises.push(component.seed());
            }
        });
        await Promise.all(promises);
    }
}
