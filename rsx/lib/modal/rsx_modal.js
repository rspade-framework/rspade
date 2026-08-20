/**
 * Rsx_Modal Component
 *
 * Instance of a modal dialog. Handles lifecycle, sizing, and user interaction.
 * Typically created and managed by the Modal static API class.
 */
class Rsx_Modal extends Component {
    on_create() {
        // Use this.state for modal state (not loading from Ajax)
        this.state = {
            title: '',
            body_content: null,
            buttons: [],
            closable: true,
            max_width: 800,
            close_on_submit: true,
            is_visible: false,
            result_promise: null,
            resolve_fn: null,
            skip_backdrop: false,
            icon: null
        };

        // Store reference to bootstrap modal instance
        this._bs_modal = null;
        this._resize_handler = null;
    }

    on_ready() {
        const that = this;

        // Set up close button handler
        this.$sid('close_btn').on('click', function (e) {
            e.preventDefault();
            if (that.state.closable) {
                that.close(false);
            }
        });

        // Close when the click lands outside the dialog. The full-screen `.modal`
        // container is layered ON TOP of the backdrop element, so the backdrop never
        // receives the click - the `.modal` container does. Closing on a click whose
        // target IS that container (i.e. the area around the .modal-dialog) gives the
        // expected "click the backdrop to dismiss" behavior; clicks inside the dialog
        // bubble from a child so e.target !== the container and do not close.
        this.$sid('modal').on('click', function (e) {
            if (that.state.closable && e.target === this) {
                that.close(false);
            }
        });

        // Set up ESC key handler
        $(document).on('keydown.rsx_modal_' + this._cid, function (e) {
            if (e.key === 'Escape' && that.state.closable && that.state.is_visible) {
                that.close(false);
            }
        });

        // Set up resize handler
        this._resize_handler = debounce(() => {
            if (that.state.is_visible) {
                that._apply_sizing();
            }
        }, 100);

        $(window).on('resize.rsx_modal_' + this._cid, this._resize_handler);
    }

    /**
     * Configure and show the modal
     * @param {Object} options - Modal options (title, body, buttons, etc.)
     * @param {Object} internal_options - Internal options (skip_backdrop, animate)
     */
    async show(options, internal_options = {}) {
        const that = this;
        const skip_backdrop = internal_options.skip_backdrop || false;
        const should_animate = internal_options.animate || false;

        console.log('[Rsx_Modal] show() called with options:', options);

        // Store options
        this.state.title = options.title || '';
        this.state.closable = options.closable !== undefined ? options.closable : true;
        this.state.max_width = options.max_width || 800;
        this.state.close_on_submit = options.close_on_submit !== undefined ? options.close_on_submit : true;
        this.state.buttons = options.buttons || [];
        this.state.skip_backdrop = skip_backdrop;
        this.state.icon = options.icon || null;

        console.log('[Rsx_Modal] Setting title to:', this.state.title);
        console.log('[Rsx_Modal] Title element:', this.$sid('title'));

        // Set title
        this.$sid('title').text(this.state.title);

        // Show/hide close button based on closable
        if (this.state.closable) {
            this.$sid('close_btn').show();
        } else {
            this.$sid('close_btn').hide();
        }

        // Set body content (with optional icon)
        this._set_body_content(options.body, this.state.icon);

        // Set buttons
        this._set_buttons();

        // Create promise that will resolve when modal closes
        const result_promise = new Promise((resolve) => {
            that.state.resolve_fn = resolve;
        });

        // Show modal and backdrop
        this.state.is_visible = true;

        // Append to body so it's on top (don't append backdrop if using shared)
        if (!skip_backdrop) {
            $('body').append(this.$sid('backdrop'));
        }
        $('body').append(this.$);

        // Apply sizing before showing
        this._apply_sizing();

        // Fade in modal (and backdrop if not using shared)
        await this._fade_in(should_animate);

        // Auto-focus first input element
        this._focus_first_input();

        return result_promise;
    }

    /**
     * Set body content with optional icon
     */
    _set_body_content(body, icon) {
        const $body = this.$sid('body');
        $body.empty();

        // If icon provided, add it
        if (icon) {
            const $icon = $(`<i class="bi bi-${icon} modal-icon"></i>`);
            $body.append($icon);
            $body.addClass('has-icon');
        } else {
            $body.removeClass('has-icon');
        }

        // Get or create body content wrapper
        let $content = this.$sid('body_content');
        if (!$content.exists()) {
            $content = $('<div class="modal-body-content"></div>');
            $body.append($content);
        }

        if (typeof body === 'string') {
            // Text content - escape and convert newlines
            const escaped = $('<div>').text(body).html().replace(/\n/g, '<br>');
            $content.html(escaped);
        } else if (body instanceof jQuery) {
            // jQuery element
            $content.append(body);
        } else if (body && typeof body === 'object') {
            // Assume it's a jqhtml component instance
            $content.append(body.$);
        }
    }

    /**
     * Set buttons in footer
     */
    _set_buttons() {
        const that = this;
        const $footer = this.$sid('footer');
        $footer.empty();

        if (this.state.buttons.length === 0) {
            $footer.hide();
            return;
        }

        $footer.show();

        for (let button_def of this.state.buttons) {
            const $button = $('<button>')
                .attr('type', 'button')
                .addClass('btn')
                .addClass(button_def.class || 'btn-secondary')
                .text(button_def.label || 'Button');

            $button.on('click', async function () {
                let result = button_def.value;
                let had_callback = false;

                // If button has a callback, call it and use return value as result
                if (button_def.callback && typeof button_def.callback === 'function') {
                    had_callback = true;
                    result = await button_def.callback();
                }

                // If callback returned false, keep modal open (but not if just button value is false)
                if (result === false && had_callback) {
                    return;
                }

                // Close modal with result
                that.close(result);
            });

            $footer.append($button);
        }
    }

    /**
     * Calculate and apply responsive sizing
     */
    _apply_sizing() {
        const viewport_width = $(window).width();
        const viewport_height = $(window).height();
        const is_mobile = viewport_width < 768;

        // Calculate max width based on viewport
        let max_width = this.state.max_width;
        const viewport_limit = is_mobile ? viewport_width * 0.9 : viewport_width * 0.8;

        max_width = Math.min(max_width, viewport_limit);

        // Try to constrain to 60% width for better proportions on desktop
        if (!is_mobile) {
            const preferred_width = viewport_width * 0.6;
            if (preferred_width < max_width) {
                max_width = preferred_width;
            }
        }

        // Apply width
        this.$sid('dialog').css('max-width', max_width + 'px');

        // Check if content exceeds 80% height
        const content_height = this.$sid('dialog').outerHeight();
        const max_height = viewport_height * 0.8;

        if (content_height > max_height) {
            // Enable scrolling
            this.$sid('dialog').css('max-height', max_height + 'px');
            this.$sid('body').css({
                'overflow-y': 'auto',
                'max-height': max_height - 150 + 'px', // Account for header/footer
            });
        } else {
            // Reset scrolling
            this.$sid('dialog').css('max-height', '');
            this.$sid('body').css({
                'overflow-y': '',
                'max-height': '',
            });
        }

        // Mobile edge spacing
        if (is_mobile) {
            this.$sid('dialog').css('margin', '5%');
        } else {
            this.$sid('dialog').css('margin', '0');
        }
    }

    /**
     * Show animation (instant or with fly-in)
     * @param {boolean} animate - Whether to animate the modal entrance
     */
    async _fade_in(animate = false) {
        if (animate) {
            // Initial state: modal positioned above final position
            this.$.css('display', 'flex').css('opacity', '0');
            this.$sid('modal').css({
                'transform': 'translate(0, -50px)',
                'opacity': '0'
            });
            this.$sid('backdrop').css('display', 'block').addClass('show');

            // Force reflow
            this.$sid('modal')[0].offsetHeight;

            // Trigger animation
            this.$sid('modal').addClass('show').css({
                'transform': 'translate(0, 0)',
                'opacity': '1'
            });
            this.$.css('opacity', '1');

            // Wait for animation to complete
            await new Promise(resolve => setTimeout(resolve, 150));
        } else {
            // Disable transitions temporarily for instant display
            this.$sid('dialog').css('transition', 'none');

            // Show modal and backdrop instantly
            this.$.css('display', 'flex').css('opacity', '1');
            this.$sid('modal').addClass('show').css('opacity', '1');
            this.$sid('backdrop').css('display', 'block').addClass('show');

            // Force reflow to apply the no-transition state
            this.$sid('dialog')[0].offsetHeight;

            // Re-enable transitions for future animations
            this.$sid('dialog').css('transition', '');
        }

        return Promise.resolve();
    }

    /**
     * Focus the first input element in the modal
     */
    _focus_first_input() {
        // Find first input/textarea/select in modal body
        const $first_input = this.$sid('body').find('input:not([type="hidden"]), textarea, select').first();
        if ($first_input.exists()) {
            requestAnimationFrame(() => {
                $first_input.focus();
                // Select text if it's an input with existing value
                if ($first_input.is('input[type="text"], input[type="email"]') && $first_input.val()) {
                    $first_input.select();
                }
            });
        }
    }

    /**
     * Close the modal instantly
     */
    async close(result) {
        const that = this;

        // Mark as not visible
        this.state.is_visible = false;

        // Remove event listeners
        $(document).off('keydown.rsx_modal_' + this._cid);
        $(window).off('resize.rsx_modal_' + this._cid);

        // Hide instantly (no fade out)
        this.$.hide();
        this.$sid('backdrop').hide();

        // Remove from DOM
        this.$.remove();
        this.$sid('backdrop').remove();

        // Resolve promise
        if (this.state.resolve_fn) {
            this.state.resolve_fn(result);
            this.state.resolve_fn = null;
        }
    }

    /**
     * Apply validation errors to form fields in modal body
     */
    apply_errors(errors) {
        // Use Form_Utils to apply errors to elements within modal body
        Form_Utils.apply_form_errors(this.$sid('body'), errors);
    }
}
