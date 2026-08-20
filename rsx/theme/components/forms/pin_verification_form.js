/**
 * Pin_Verification_Form
 *
 * Specialized 6-digit PIN entry form with auto-navigation between inputs.
 * See pin_verification_form.jqhtml for full documentation.
 *
 * JavaScript Responsibilities:
 * - Auto-advances to next input when digit is entered
 * - Smart backspace: clears current box and moves to previous
 * - Paste support: distributes pasted digits across all 6 inputs
 * - Arrow key navigation between inputs
 * - Numeric-only input validation
 * - Select-all on focus for easy digit replacement
 * - Validates all 6 digits entered before allowing submission
 * - Provides val() getter/setter for programmatic PIN access
 */
class Pin_Verification_Form extends Rsx_Form {
    on_create() {
        super.on_create();
        this.pin_length = 6;
    }

    /**
     * Get or set the PIN value
     * @param {string} [value] - If provided, sets the PIN (distributes across inputs)
     * @returns {string} Current PIN value when called as getter
     */
    val(value) {
        if (arguments.length === 0) {
            // Getter - collect all digits
            let pin = '';
            for (let i = 0; i < this.pin_length; i++) {
                pin += this.$sid(`digit_${i}`).val() || '';
            }
            return pin;
        } else {
            // Setter - distribute digits across inputs
            const digits = str(value || '').replace(/[^0-9]/g, '');
            for (let i = 0; i < this.pin_length; i++) {
                this.$sid(`digit_${i}`).val(digits[i] || '');
            }
            // Focus first empty input or last input
            const first_empty = this._find_first_empty_index();
            if (first_empty !== -1) {
                this.$sid(`digit_${first_empty}`)[0].focus();
            } else {
                this.$sid(`digit_${this.pin_length - 1}`)[0].focus();
            }
        }
    }

    /**
     * Find the first empty input index
     * @returns {number} Index of first empty input, or -1 if all filled
     */
    _find_first_empty_index() {
        for (let i = 0; i < this.pin_length; i++) {
            if (!this.$sid(`digit_${i}`).val()) {
                return i;
            }
        }
        return -1;
    }

    /**
     * Move focus to specific input index
     * @param {number} index
     */
    _focus_input(index) {
        if (index >= 0 && index < this.pin_length) {
            const $input = this.$sid(`digit_${index}`);
            if ($input.exists()) {
                $input[0].focus();
                // Select the content if there is any
                $input[0].select();
            }
        }
    }

    /**
     * Handle paste event - distribute digits across inputs
     * @param {ClipboardEvent} e
     * @param {number} start_index
     */
    _handle_paste(e, start_index) {
        e.preventDefault();

        // Get pasted data
        const paste = (e.originalEvent || e).clipboardData.getData('text');
        const digits = paste.replace(/[^0-9]/g, '');

        if (!digits) {
            return;
        }

        // Distribute digits starting from current input
        for (let i = 0; i < digits.length && (start_index + i) < this.pin_length; i++) {
            this.$sid(`digit_${start_index + i}`).val(digits[i]);
        }

        // Focus next empty input or last input
        const next_index = Math.min(start_index + digits.length, this.pin_length - 1);
        this._focus_input(next_index);
    }

    on_ready() {
        super.on_ready();

        const that = this;

        // Set up event handlers for each input
        for (let i = 0; i < this.pin_length; i++) {
            const $input = this.$sid(`digit_${i}`);
            const index = i;

            // Handle input event - auto-advance
            $input.on('input', function(e) {
                const value = $(this).val();

                // Only allow numeric input
                const numeric = value.replace(/[^0-9]/g, '');
                if (numeric !== value) {
                    $(this).val(numeric);
                }

                // If multiple digits were entered (paste), distribute them
                if (numeric.length > 1) {
                    that._handle_paste({
                        preventDefault: () => {},
                        originalEvent: {
                            clipboardData: {
                                getData: () => numeric
                            }
                        }
                    }, index);
                    return;
                }

                // Auto-advance to next input if digit was entered
                if (numeric.length === 1 && index < that.pin_length - 1) {
                    that._focus_input(index + 1);
                }
            });

            // Handle keydown for backspace
            $input.on('keydown', function(e) {
                // Backspace key
                if (e.key === 'Backspace') {
                    const current_value = $(this).val();

                    // If current input is empty, move to previous and clear it
                    if (!current_value && index > 0) {
                        e.preventDefault();
                        that.$sid(`digit_${index - 1}`).val('');
                        that._focus_input(index - 1);
                    }
                    // If current input has value, it will be cleared by default behavior
                    // and we stay on current input
                }

                // Arrow left
                if (e.key === 'ArrowLeft' && index > 0) {
                    e.preventDefault();
                    that._focus_input(index - 1);
                }

                // Arrow right
                if (e.key === 'ArrowRight' && index < that.pin_length - 1) {
                    e.preventDefault();
                    that._focus_input(index + 1);
                }
            });

            // Handle paste event
            $input.on('paste', function(e) {
                that._handle_paste(e, index);
            });

            // Select all on focus for easy replacement
            $input.on('focus', function() {
                $(this)[0].select();
            });
        }

        // Focus first input on load
        this._focus_input(0);
    }

    /**
     * Override submit to validate PIN is complete
     */
    async submit() {
        const pin = this.val();

        // Clear previous errors
        this.$sid('error_container').hide().empty();

        // Validate PIN is 6 digits
        if (pin.length !== this.pin_length) {
            this.$sid('error_container')
                .text('Please enter all 6 digits')
                .show();

            // Mark inputs as invalid
            for (let i = 0; i < this.pin_length; i++) {
                if (!this.$sid(`digit_${i}`).val()) {
                    this.$sid(`digit_${i}`).addClass('is-invalid');
                }
            }

            return;
        }

        // Remove invalid class from all inputs
        for (let i = 0; i < this.pin_length; i++) {
            this.$sid(`digit_${i}`).removeClass('is-invalid');
        }

        // Call parent submit (which will use controller/method if provided)
        await super.submit();
    }
}
