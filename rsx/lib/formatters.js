/**
 * Formatters
 *
 * String formatting utilities (JS counterpart of Rsx\Lib\Formatters in
 * rsx/lib/formatters.php). Same-name pair convention (like Rsx_Time.php /
 * Rsx_Time.js) - PHP and JS produce IDENTICAL output.
 *
 * The phone formatter is backed by google-libphonenumber (exposed as the global
 * `libphonenumber` by Phone_Libphonenumber_Bundle), the official JS port sharing
 * Google's metadata with the PHP giggsey/libphonenumber-for-php library.
 *
 * Usage:
 *   const formatted = Formatters.phone(input);
 */
class Formatters {
    /**
     * Format a phone number
     *
     * Behavior matches Rsx\Lib\Formatters::phone() and the Phone_Text_Input
     * component:
     * - International mode (starts with '+'): Parsed with libphonenumber. A number
     *   whose country code is region's own is rendered in that region's national
     *   style (so a stored E.164 value round-trips to what the user typed); any
     *   other possible number is rendered in INTERNATIONAL format; anything else is
     *   returned unchanged (never throws for a display formatter).
     * - Default US mode (region === 'US'): Progressive formatting as
     *   (XXX) XXX-XXXX. Strips a leading "1" country code, limits to 10 digits,
     *   and formats partial input as-you-type. Deliberately NOT routed through
     *   libphonenumber so placeholder/invalid-plan numbers and partials survive.
     * - Explicit non-US region: Parsed with libphonenumber and rendered in that
     *   region's NATIONAL format when possible; otherwise returned unchanged.
     *
     * Uses isPossibleNumber() (length/pattern-based), NOT isValidNumber(), so
     * test/placeholder numbers are accepted.
     *
     * @param {string|null|undefined} input Phone number in any format
     * @param {string} region ISO region for non-'+' input (default 'US')
     * @returns {string} Formatted phone number
     *
     * @example
     * Formatters.phone('5551234567')        // "(555) 123-4567"
     * Formatters.phone('15551234567')       // "(555) 123-4567"
     * Formatters.phone('(555) 123-4567')    // "(555) 123-4567"
     * Formatters.phone('+442071234567')     // "+44 20 7123 4567"
     * Formatters.phone('+44 20 7123 4567')  // "+44 20 7123 4567"
     * Formatters.phone('+19206145140')      // "(920) 614-5140"  (US is the home region)
     * Formatters.phone('2071234567', 'GB')  // "020 7123 4567"
     */
    static phone(input, region = 'US') {
        // Handle null/undefined/empty. Strict check (not empty()) so the string
        // '0' proceeds to formatting, matching Phone_Text_Input.
        if (input === null || input === undefined || input === '') {
            return '';
        }

        const str_input = str(input);

        // International mode - input carries an explicit country code ('+').
        if (str_input.trim().charAt(0) === '+') {
            try {
                const util = libphonenumber.PhoneNumberUtil.getInstance();
                // Region is irrelevant when the number carries '+'.
                const proto = util.parse(str_input, null);
                if (util.isPossibleNumber(proto)) {
                    // A number belonging to region's own country code is shown the way
                    // that region writes it, WITHOUT the country code. E.164 is a storage
                    // format - Frontend_Contacts_Controller::save() stores
                    // "+19206145140" - and the number a user typed as (920) 614-5140
                    // has to come back to them as (920) 614-5140.
                    const home_country_code = util.getCountryCodeForRegion(region);

                    if (proto.getCountryCode() === home_country_code) {
                        return Formatters.phone(str(proto.getNationalNumber()), region);
                    }

                    return util.format(proto, libphonenumber.PhoneNumberFormat.INTERNATIONAL);
                }
            } catch (e) {
                // Expected for arbitrary display input - fall through to passthrough.
            }

            // Unparseable or not-possible - return unchanged.
            return str_input;
        }

        // Non-US region - defer to libphonenumber's NATIONAL format.
        if (region !== 'US') {
            try {
                const util = libphonenumber.PhoneNumberUtil.getInstance();
                const proto = util.parse(str_input, region);
                if (util.isPossibleNumber(proto)) {
                    return util.format(proto, libphonenumber.PhoneNumberFormat.NATIONAL);
                }
            } catch (e) {
                // Expected for arbitrary display input - fall through to passthrough.
            }

            return str_input;
        }

        // US mode - extract digits only
        const digits = str_input.replace(/[^0-9]/g, '');

        // Determine which digits to format
        let digits_to_format;

        if (digits.length === 11 && digits.charAt(0) === '1' && /[2-9]/.test(digits.charAt(1))) {
            // Exactly 11 digits starting with "1" followed by valid area code digit (2-9)
            // This is a US country code - strip the leading 1
            digits_to_format = digits.substr(1);
        } else if (digits.length > 10) {
            // More than 10 digits - take the first 10
            digits_to_format = digits.substr(0, 10);
        } else {
            // 10 or fewer digits - use as-is
            digits_to_format = digits;
        }

        // Format based on length
        return Formatters._format_us_phone(digits_to_format);
    }

    /**
     * Format US phone number as (XXX) XXX-XXXX
     *
     * Byte-for-byte identical to Phone_Text_Input._format_us_phone (the canonical
     * keystroke formatter) and Rsx\Lib\Formatters::_format_us_phone.
     *
     * @param {string} digits Clean numeric string (10 digits or less)
     * @returns {string} Formatted phone number
     */
    static _format_us_phone(digits) {
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
}
