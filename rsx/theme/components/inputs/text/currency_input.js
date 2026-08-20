/**
 * Currency_Input
 *
 * Extends Text_Input to provide automatic currency formatting.
 *
 * Features:
 * - Adds thousands separators (commas) every 3 digits
 * - Optional currency symbol prefix (default: hidden)
 * - Optional decimal support (default: disabled)
 * - Smart backspace over formatting characters
 * - No mid-string formatting (waits for blur)
 *
 * Arguments:
 * - $allow_decimals - Allow 2 decimal places (default: false)
 * - $show_symbol - Show currency symbol (default: false)
 * - $currency_symbol - Currency symbol to use (default: "$")
 *
 * Usage:
 *   <Currency_Input />
 *   <Currency_Input $show_symbol=true />
 *   <Currency_Input $allow_decimals=true />
 *   <Currency_Input $show_symbol=true $allow_decimals=true $currency_symbol="€" />
 *
 * Behavior:
 * - Type "1234567" -> displays "1,234,567", val() returns "1234567"
 * - Type "1234567.89" (with decimals) -> displays "1,234,567.89", val() returns "1234567.89"
 * - With symbol: displays "$1,234,567", val() still returns "1234567"
 */
class Currency_Input extends Text_Input {
    on_create() {
        super.on_create();

        // Set defaults for options
        if (this.args.allow_decimals === undefined) {
            this.args.allow_decimals = false;
        }
        if (this.args.show_symbol === undefined) {
            this.args.show_symbol = false;
        }
        if (this.args.currency_symbol === undefined) {
            this.args.currency_symbol = '$';
        }
    }

    /**
     * Format currency with commas and optional symbol
     * @param {string} value - Numeric value (may include decimal)
     * @returns {string} Formatted currency string
     */
    _format_currency(value) {
        if (!value) {
            return '';
        }

        // Split into integer and decimal parts
        let parts = value.split('.');
        let integer_part = parts[0];
        let decimal_part = parts[1];

        // Add commas to integer part
        integer_part = integer_part.replace(/\B(?=(\d{3})+(?!\d))/g, ',');

        // Reconstruct with decimal if allowed
        let formatted = integer_part;
        if (this.args.allow_decimals && decimal_part !== undefined) {
            // Limit to 2 decimal places
            decimal_part = decimal_part.substr(0, 2);
            formatted += '.' + decimal_part;
        }

        // Add currency symbol if enabled
        if (this.args.show_symbol) {
            formatted = this.args.currency_symbol + formatted;
        }

        return formatted;
    }

    /**
     * Extract numeric value from formatted string
     * @param {string} formatted - Formatted currency string
     * @returns {string} Clean numeric value (digits and decimal only)
     */
    _get_numeric_value(formatted) {
        if (!formatted) {
            return '';
        }

        // Remove currency symbol and commas
        let cleaned = formatted.replace(/[^0-9.]/g, '');

        // Ensure only one decimal point
        const decimal_count = (cleaned.match(/\./g) || []).length;
        if (decimal_count > 1) {
            // Keep only first decimal point
            const first_decimal = cleaned.indexOf('.');
            cleaned = cleaned.substr(0, first_decimal + 1) + cleaned.substr(first_decimal + 1).replace(/\./g, '');
        }

        return cleaned;
    }

    /**
     * Override _get_value to return numeric value only
     */
    _get_value() {
        const raw = this.$sid('input').val();
        return this._get_numeric_value(raw);
    }

    /**
     * Override _set_value to format and display
     */
    _set_value(value) {
        if (!value) {
            this.$sid('input').val('');
            return;
        }

        // Clean the input value
        const numeric = this._get_numeric_value(str(value));
        const formatted = this._format_currency(numeric);
        this.$sid('input').val(formatted);
    }

    on_ready() {
        // Call parent to _mark_ready and setup base input events
        super.on_ready();

        const $input = this.$sid('input');

        // Handle keydown to intercept backspace at end of string
        $input.on('keydown', (e) => {
            const raw = $input.val();

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

                        const numeric = this._get_numeric_value(raw);
                        if (numeric.length > 0) {
                            // Remove last character from numeric value
                            const new_numeric = numeric.substr(0, numeric.length - 1);
                            const formatted = this._format_currency(new_numeric);
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
            const input_element = $input[0];
            const cursor_pos = input_element.selectionStart;
            const value_length = raw.length;

            // Only apply live formatting if cursor is at the end
            if (cursor_pos === value_length) {
                // Extract numeric value
                let numeric = this._get_numeric_value(raw);

                // Limit decimal places to 2 if decimals allowed
                if (this.args.allow_decimals) {
                    const parts = numeric.split('.');
                    if (parts[1] && parts[1].length > 2) {
                        numeric = parts[0] + '.' + parts[1].substr(0, 2);
                    }
                }

                // Format the numeric value
                const formatted = this._format_currency(numeric);
                $input.val(formatted);
            } else {
                // Cursor is not at end - user is editing in the middle
                const numeric = this._get_numeric_value(raw);

                // Only update if we removed invalid characters
                if (this._format_currency(numeric) !== raw) {
                    const symbol_offset = this.args.show_symbol ? this.args.currency_symbol.length : 0;
                    const cleaned = (this.args.show_symbol ? this.args.currency_symbol : '') + numeric;

                    if (cleaned !== raw) {
                        $input.val(cleaned);
                        const new_cursor = Math.min(cursor_pos, cleaned.length);
                        input_element.setSelectionRange(new_cursor, new_cursor);
                    }
                }
            }
        });

        // Handle blur to reformat when done editing
        $input.on('blur', () => {
            const raw = $input.val();

            if (!raw) {
                return;
            }

            // Reformat the entire value on blur
            const numeric = this._get_numeric_value(raw);
            const formatted = this._format_currency(numeric);
            $input.val(formatted);
        });

        // Handle focus to select all for easy replacement
        $input.on('focus', () => {
            setTimeout(() => {
                $input[0].select();
            }, 0);
        });

        // Initialize formatting if there's a value
        const initial_value = $input.val();
        if (initial_value) {
            this._set_value(initial_value);
        }
    }
}
