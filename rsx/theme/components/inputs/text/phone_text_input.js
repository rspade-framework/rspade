/**
 * Phone_Text_Input
 *
 * Extends Text_Input to provide automatic phone number formatting.
 *
 * Features:
 * - US Mode (default): Formats as (XXX) XXX-XXXX on every keystroke
 * - International Mode: Triggered by starting with '+', disables formatting
 * - val() getter returns formatted string as displayed
 * - val() setter accepts any format and displays appropriately
 *
 * Usage:
 *   <Phone_Text_Input $placeholder="Phone number" />
 *
 * Behavior:
 * - Type "5551234567" -> displays "(555) 123-4567", val() returns "(555) 123-4567"
 * - Type "+44 20 7123 4567" -> displays as typed, val() returns "+44 20 7123 4567"
 * - Leading "1" is stripped: "15551234567" -> "(555) 123-4567"
 */
class Phone_Text_Input extends Text_Input {
    on_create() {
        super.on_create();
        this._is_international = false;
    }

    /**
     * Check if input is in international mode (starts with +)
     * @param {string} value
     * @returns {boolean}
     */
    _check_international_mode(value) {
        return value && str(value).charAt(0) === '+';
    }

    /**
     * Format US phone number as (XXX) XXX-XXXX
     *
     * Canonical LIVE-KEYSTROKE formatter for partial digit strings. Kept local and
     * self-contained (no Formatters dependency) so as-you-type formatting works in
     * every bundle shipping this component. For full-string formatting (val()/blur)
     * the canonical formatter is Formatters.phone() (rsx/lib/formatters.js), which
     * also handles international ('+') input and mirrors the PHP Formatters::phone.
     *
     * @param {string} digits - Clean numeric string (should be 10 digits or less after processing)
     * @returns {string} Formatted phone number
     */
    _format_us_phone(digits) {
        // Format based on length (assumes digits are already cleaned and limited to 10)
        if (digits.length >= 6) {
            // (XXX) XXX-XXXX
            return '(' + digits.substr(0, 3) + ') ' + digits.substr(3, 3) + '-' + digits.substr(6);
        } else if (digits.length >= 3) {
            // (XXX) XXX
            return '(' + digits.substr(0, 3) + ') ' + digits.substr(3);
        } else if (digits.length > 0) {
            // (XX
            return '(' + digits;
        }

        return digits;
    }

    /**
     * Override _get_value to return formatted value as displayed
     */
    _get_value() {
        return this.$sid('input').val() || '';
    }

    /**
     * Override _set_value to format and display
     *
     * Delegates full-string formatting to Formatters.phone() - the canonical
     * formatter shared with PHP (Rsx\Lib\Formatters::phone) - covering both US
     * and international ('+') input.
     */
    _set_value(value) {
        if (!value) {
            this.$sid('input').val('');
            return;
        }

        this.$sid('input').val(Formatters.phone(str(value)));
    }

    on_ready() {
        // Call parent to _mark_ready and setup base input events
        super.on_ready();

        const $input = this.$sid('input');

        // Handle keydown to intercept backspace at end of string
        $input.on('keydown', (e) => {
            const raw = $input.val();

            // Skip if international mode
            if (this._check_international_mode(raw)) {
                return;
            }

            // Only handle backspace key
            if (e.key !== 'Backspace') {
                return;
            }

            const input_element = $input[0];
            const cursor_pos = input_element.selectionStart;
            const cursor_end = input_element.selectionEnd;
            const value_length = raw.length;

            // Only handle if cursor is at the end and no selection
            if (cursor_pos === value_length && cursor_pos === cursor_end) {
                // Check if character before cursor is non-numeric
                if (cursor_pos > 0) {
                    const char_before = raw.charAt(cursor_pos - 1);
                    if (!/[0-9]/.test(char_before)) {
                        // Character before cursor is not a digit
                        // Delete the last digit instead
                        e.preventDefault();

                        const digits = raw.replace(/[^0-9]/g, '');
                        if (digits.length > 0) {
                            const new_digits = digits.substr(0, digits.length - 1);
                            const formatted = this._format_us_phone(new_digits);
                            $input.val(formatted);

                            // Place cursor at end
                            setTimeout(() => {
                                const new_length = $input.val().length;
                                input_element.setSelectionRange(new_length, new_length);
                            }, 0);
                        }
                    }
                }
            }
        });

        // Handle input event for live formatting
        $input.on('input', () => {
            const raw = $input.val();

            if (this._check_international_mode(raw)) {
                // International mode - allow anything
                this._is_international = true;
                return;
            }

            // US mode
            this._is_international = false;

            const input_element = $input[0];
            const cursor_pos = input_element.selectionStart;
            const value_length = raw.length;

            // Only apply live formatting if cursor is at the end
            if (cursor_pos === value_length) {
                // Remove any non-digit, non-formatting characters
                const cleaned = raw.replace(/[^0-9\s\-()]/g, '');
                const digits = cleaned.replace(/[^0-9]/g, '');

                // Determine which digits to format
                let digits_to_format;

                if (digits.length === 11 && digits.charAt(0) === '1' && /[2-9]/.test(digits.charAt(1))) {
                    // Exactly 11 digits starting with "1" followed by valid area code digit (2-9)
                    digits_to_format = digits.substr(1);
                } else if (digits.length > 10) {
                    // More than 10 digits - just take the first 10
                    digits_to_format = digits.substr(0, 10);
                } else {
                    // 10 or fewer digits - use as-is
                    digits_to_format = digits;
                }

                // Format the digits
                const formatted = this._format_us_phone(digits_to_format);
                $input.val(formatted);
            } else {
                // Cursor is not at end - user is editing in the middle
                const cleaned = raw.replace(/[^0-9\s\-()]/g, '');
                if (cleaned !== raw) {
                    $input.val(cleaned);
                    input_element.setSelectionRange(cursor_pos, cursor_pos);
                }
            }
        });

        // Handle blur to reformat when done editing
        $input.on('blur', () => {
            const raw = $input.val();

            // Skip if empty
            if (!raw) {
                return;
            }

            // International mode - format the completed number via the canonical
            // formatter (mirrors PHP Formatters::phone; not-possible input passes
            // through unchanged).
            if (this._check_international_mode(raw)) {
                $input.val(Formatters.phone(raw));
                return;
            }

            // US mode - reformat the entire value on blur
            const digits = raw.replace(/[^0-9]/g, '');

            // Determine which digits to format
            let digits_to_format;

            if (digits.length === 11 && digits.charAt(0) === '1' && /[2-9]/.test(digits.charAt(1))) {
                digits_to_format = digits.substr(1);
            } else if (digits.length > 10) {
                digits_to_format = digits.substr(0, 10);
            } else {
                digits_to_format = digits;
            }

            const formatted = this._format_us_phone(digits_to_format);
            $input.val(formatted);
        });

        // Initialize formatting if there's a value
        const initial_value = $input.val();
        if (initial_value) {
            this._set_value(initial_value);
        }
    }
}
