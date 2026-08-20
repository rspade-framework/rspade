/*
 * Core utility functions for the RSpade framework.
 * These functions handle type checking, type conversion, string manipulation,
 * and object/array utilities. They mirror functionality from PHP functions.
 *
 * Other utility functions are organized in:
 * - async.js: Async utilities (sleep, debounce, mutex)
 * - browser.js: Browser/DOM utilities (is_mobile, scroll functions)
 * - datetime.js: Date/time utilities
 * - hash.js: Hashing and comparison
 * - error.js: Error handling
 */

// Todo: test that prod build identifies and removes uncalled functions from the final bundle.

// ============================================================================
// CONSTANTS AND HELPERS
// ============================================================================

// Define commonly used constants
const undef = 'undefined';

/**
 * Iterates over arrays or objects with promise support
 *
 * Works with both synchronous and asynchronous callbacks. If the callback
 * returns promises, they are executed in parallel and this function returns
 * a promise that resolves when all parallel tasks complete.
 *
 * @param {Array|Object} obj - Collection to iterate
 * @param {Function} callback - Function to call for each item (value, key) - can be async
 * @returns {Promise|undefined} Promise if any callbacks return promises, undefined otherwise
 *
 * @example
 * // Synchronous usage
 * foreach([1,2,3], (val) => console.log(val));
 *
 * @example
 * // Asynchronous usage - waits for all to complete
 * await foreach([1,2,3], async (val) => {
 *     await fetch('/api/process/' + val);
 * });
 */
function foreach(obj, callback) {
    const results = [];

    if (Array.isArray(obj)) {
        obj.forEach((value, index) => {
            results.push(callback(value, index));
        });
    } else if (obj && typeof obj === 'object') {
        for (let key in obj) {
            if (obj.hasOwnProperty(key)) {
                results.push(callback(obj[key], key));
            }
        }
    }

    // Filter for promises
    const promises = results.filter((result) => result && typeof result.then === 'function');

    // If there are any promises, return Promise.all to wait for all to complete
    if (promises.length > 0) {
        return Promise.all(promises);
    }

    // No promises returned, so we're done
    return undefined;
}


// ============================================================================
// TYPE CHECKING FUNCTIONS
// ============================================================================

/**
 * Checks if a value is numeric
 * @param {*} n - Value to check
 * @returns {boolean} True if the value is a finite number
 */
function is_numeric(n) {
    return !isNaN(parseFloat(n)) && isFinite(n);
}

/**
 * Checks if a value is a string
 * @param {*} s - Value to check
 * @returns {boolean} True if the value is a string
 */
function is_string(s) {
    return typeof s == 'string';
}

/**
 * Checks if a value is an integer
 * @param {*} n - Value to check
 * @returns {boolean} True if the value is an integer
 */
function is_integer(n) {
    return Number.isInteger(n);
}

/**
 * Checks if a value is a promise-like object
 * @param {*} obj - Value to check
 * @returns {boolean} True if the value has a then method
 */
function is_promise(obj) {
    return typeof obj == 'object' && typeof obj.then == 'function';
}

/**
 * Checks if a value is an array
 * @param {*} obj - Value to check
 * @returns {boolean} True if the value is an array
 */
function is_array(obj) {
    return Array.isArray(obj);
}

/**
 * Checks if a value is an object (excludes null)
 * @param {*} obj - Value to check
 * @returns {boolean} True if the value is an object and not null
 */
function is_object(obj) {
    return typeof obj === 'object' && obj !== null;
}

/**
 * Checks if a value is a function
 * @param {*} function_to_check - Value to check
 * @returns {boolean} True if the value is a function
 */
function is_function(function_to_check) {
    return function_to_check && {}.toString.call(function_to_check) === '[object Function]';
}

/**
 * Checks if a string is a valid email address
 * Uses a practical RFC 5322 compliant regex that matches 99.99% of real-world email addresses
 * @param {string} email - Email address to validate
 * @returns {boolean} True if the string is a valid email address
 */
function is_email(email) {
    if (!is_string(email)) {
        return false;
    }
    const regex = /^[a-z0-9!#$%&'*+/=?^_`{|}~-]+(?:\.[a-z0-9!#$%&'*+/=?^_`{|}~-]+)*@(?:[a-z0-9](?:[a-z0-9-]*[a-z0-9])?\.)+[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$/i;
    return regex.test(email);
}

/**
 * Checks if a value is defined (not undefined)
 * @param {*} value - Value to check
 * @returns {boolean} True if value is not undefined
 */
function isset(value) {
    return typeof value != undef;
}

/**
 * Checks if a value is empty (null, undefined, 0, "", empty array/object)
 * @param {*} object - Value to check
 * @returns {boolean} True if the value is considered empty
 */
function empty(object) {
    if (typeof object == undef) {
        return true;
    }
    if (object === null) {
        return true;
    }
    if (typeof object == 'string' && object == '') {
        return true;
    }
    if (typeof object == 'number') {
        return object == 0;
    }
    if (Array.isArray(object)) {
        return !object.length;
    }
    if (typeof object == 'function') {
        return false;
    }
    for (let key in object) {
        if (object.hasOwnProperty(key)) {
            return false;
        }
    }
    return true;
}

// ============================================================================
// TYPE CONVERSION FUNCTIONS
// ============================================================================

/**
 * Converts a value to a floating point number
 * Returns 0 for null, undefined, NaN, or non-numeric values
 * @param {*} val - Value to convert
 * @returns {number} Floating point number
 */
function float(val) {
    // Handle null, undefined, empty string
    if (val === null || val === undefined || val === '') {
        return 0.0;
    }

    // Try to parse the value
    const parsed = parseFloat(val);

    // Check for NaN and return 0 if parsing failed
    return isNaN(parsed) ? 0.0 : parsed;
}

/**
 * Converts a value to an integer
 * Returns 0 for null, undefined, NaN, or non-numeric values
 * @param {*} val - Value to convert
 * @returns {number} Integer value
 */
function int(val) {
    // Handle null, undefined, empty string
    if (val === null || val === undefined || val === '') {
        return 0;
    }

    // Try to parse the value
    const parsed = parseInt(val, 10);

    // Check for NaN and return 0 if parsing failed
    return isNaN(parsed) ? 0 : parsed;
}

/**
 * Converts a value to a string
 * Returns empty string for null or undefined
 * @param {*} val - Value to convert
 * @returns {string} String representation
 */
function str(val) {
    // Handle null and undefined specially
    if (val === null || val === undefined) {
        return '';
    }

    // Convert to string
    return String(val);
}

/**
 * Converts numeric strings to numbers, returns all other values unchanged
 * Used when you need to ensure numeric types but don't want to force
 * conversion of non-numeric values (which would become 0)
 * @param {*} val - Value to convert
 * @returns {*} Number if input was numeric string, otherwise unchanged
 */
function value_unless_numeric_string_then_numeric_value(val) {
    // If it's already a number, return it
    if (typeof val === 'number') {
        return val;
    }

    // If it's a string and numeric, convert it
    if (is_string(val) && is_numeric(val)) {
        // Use parseFloat to handle both integers and floats
        return parseFloat(val);
    }

    // Return everything else unchanged (null, objects, non-numeric strings, etc.)
    return val;
}

// ============================================================================
// STRING MANIPULATION FUNCTIONS
// ============================================================================

/**
 * Escapes HTML special characters (uses Lodash escape)
 * @param {string} str - String to escape
 * @returns {string} HTML-escaped string
 */
function html(str) {
    return _.escape(str);
}

/**
 * Sanitizes HTML from WYSIWYG editors to prevent XSS attacks
 *
 * Uses DOMPurify to filter potentially malicious HTML while preserving
 * safe formatting tags. Suitable for user-generated rich text content.
 *
 * @param {string} html_string - HTML string to sanitize
 * @returns {string} Sanitized HTML safe for display
 */
function safe_html(html_string) {
    return DOMPurify.sanitize(html_string, {
        ALLOWED_TAGS: ['p', 'br', 'strong', 'b', 'em', 'i', 'u', 's', 'strike', 'a', 'ul', 'ol', 'li', 'blockquote', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'pre', 'code', 'img', 'table', 'thead', 'tbody', 'tr', 'th', 'td', 'div', 'span'],
        ALLOWED_ATTR: ['href', 'title', 'target', 'src', 'alt', 'width', 'height', 'class'],
        ALLOW_DATA_ATTR: false,
    });
}

/**
 * Converts newlines to HTML line breaks
 * @param {string} str - String to convert
 * @returns {string} String with newlines replaced by <br />
 */
function nl2br(str) {
    if (typeof str === undef || str === null) {
        return '';
    }
    return (str + '').replace(/([^>\r\n]?)(\r\n|\n\r|\r|\n)/g, '$1<br />$2');
}

/**
 * Escapes HTML and converts newlines to <br />
 * @param {string} str - String to process
 * @returns {string} HTML-escaped string with line breaks
 */
function htmlbr(str) {
    return nl2br(html(str));
}

/**
 * URL-encodes a string
 * @param {string} str - String to encode
 * @returns {string} URL-encoded string
 */
function urlencode(str) {
    return encodeURIComponent(str);
}

/**
 * URL-decodes a string
 * @param {string} str - String to decode
 * @returns {string} URL-decoded string
 */
function urldecode(str) {
    return decodeURIComponent(str);
}

/**
 * JSON-encodes a value
 * @param {*} value - Value to encode
 * @returns {string} JSON string
 */
function json_encode(value) {
    return JSON.stringify(value);
}

/**
 * JSON-decodes a string
 * @param {string} str - JSON string to decode
 * @returns {*} Decoded value
 */
function json_decode(str) {
    return JSON.parse(str);
}

/**
 * Console debug output with channel filtering
 * Alias for Debugger.console_debug
 * @param {string} channel - Debug channel name
 * @param {...*} values - Values to log
 */
function console_debug(channel, ...values) {
    Debugger.console_debug(channel, ...values);
}

/**
 * Replaces all occurrences of a substring in a string
 * @param {string} string - String to search in
 * @param {string} search - Substring to find
 * @param {string} replace - Replacement substring
 * @returns {string} String with all occurrences replaced
 */
function replace_all(string, search, replace) {
    if (!is_string(string)) {
        string = string + '';
    }
    return string.split(search).join(replace);
}

/**
 * Converts bytes to a human-readable string. The JS mirror of PHP's bytes_to_human(),
 * with the same signature, the same unit table, and the same "---" for a non-number -
 * so a size formatted server-side and the same size formatted client-side read
 * identically instead of almost identically.
 *
 * @param {number} bytes - The number of bytes
 * @param {number} precision - Decimal places (default: 2)
 * @returns {string} Formatted string (e.g., "1.5 MB", "342 B")
 */
function bytes_to_human(bytes, precision = 2) {
    if (!is_numeric(bytes)) {
        return '---';
    }

    const units = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];
    let value = float(bytes);
    let i = 0;

    for (; value > 1024 && i < units.length - 1; i++) {
        value /= 1024;
    }

    // PHP's round() drops trailing zeros in string context; Number does the same.
    return Number(value.toFixed(precision)) + ' ' + units[i];
}

/**
 * Capitalizes the first letter of each word
 * @param {string} input - String to capitalize
 * @returns {string} String with first letter of each word capitalized
 */
function ucwords(input) {
    return input
        .split(' ')
        .map((word) => word.charAt(0).toUpperCase() + word.slice(1))
        .join(' ');
}

/**
 * Make a string safe for use as a variable/function name
 * @param {string} string - Input string
 * @param {number} [max_length=64] - Maximum length
 * @returns {string} Safe string
 */
function safe_string(string, max_length = 64) {
    // Replace non-alphanumeric with underscores
    string = String(string).replace(/[^a-zA-Z0-9_]+/g, '_');

    // Ensure first character is not a number
    if (string === '' || /^[0-9]/.test(string)) {
        string = '_' + string;
    }

    // Trim to max length
    return string.substring(0, max_length);
}

/**
 * Convert snake_case to camelCase
 * @param {string} string - Snake case string
 * @param {boolean} [capitalize_first=false] - Whether to capitalize first letter (PascalCase)
 * @returns {string} Camel case string
 */
function snake_to_camel(string, capitalize_first = false) {
    let result = String(string).replace(/_([a-z])/g, (match, letter) => letter.toUpperCase());

    if (capitalize_first && result.length > 0) {
        result = result.charAt(0).toUpperCase() + result.slice(1);
    } else if (!capitalize_first && result.length > 0) {
        result = result.charAt(0).toLowerCase() + result.slice(1);
    }

    return result;
}

/**
 * Convert camelCase to snake_case
 * @param {string} string - Camel case string
 * @returns {string} Snake case string
 */
function camel_to_snake(string) {
    return String(string)
        .replace(/^[A-Z]/, (letter) => letter.toLowerCase())
        .replace(/[A-Z]/g, (letter) => '_' + letter.toLowerCase());
}

/**
 * Common TLDs for domain detection in linkify functions
 * @type {string}
 */
const LINKIFY_TLDS = 'com|org|net|edu|gov|io|co|me|info|biz|us|uk|ca|au|de|fr|es|it|nl|ru|jp|cn|in|br|mx|app|dev|xyz|online|site|tech|store|blog|shop';

/**
 * Convert plain text to HTML with URLs converted to hyperlinks
 *
 * First escapes the text to HTML, then converts URLs (with protocols) and
 * domain-like text (with known TLDs) into clickable hyperlinks.
 *
 * @param {string|null} content - Plain text content
 * @param {boolean} [new_window=true] - Whether to add target="_blank" to links
 * @returns {string} HTML with clickable links
 */
function linkify_text(content, new_window = true) {
    if (content == null || content === '') {
        return '';
    }

    // First escape HTML
    const escaped = html(String(content));

    return _linkify_content(escaped, new_window);
}

/**
 * Convert URLs in HTML to hyperlinks, preserving existing links
 *
 * Converts URLs (with protocols) and domain-like text (with known TLDs)
 * into clickable hyperlinks, but only for text not already inside <a> tags.
 *
 * @param {string|null} content - HTML content
 * @param {boolean} [new_window=true] - Whether to add target="_blank" to links
 * @returns {string} HTML with clickable links
 */
function linkify_html(content, new_window = true) {
    if (content == null || content === '') {
        return '';
    }

    // Split content into segments: inside <a> tags and outside
    const pattern = /(<a\s[^>]*>.*?<\/a>)/gi;
    const segments = String(content).split(pattern);

    let result = '';
    for (const segment of segments) {
        // Check if this segment is an <a> tag
        if (/^<a\s/i.test(segment)) {
            // Already a link, keep as-is
            result += segment;
        } else {
            // Not inside a link, linkify it
            result += _linkify_content(segment, new_window);
        }
    }

    return result;
}

/**
 * Internal helper to convert URLs/domains to links in content
 *
 * @param {string} content - Content to process
 * @param {boolean} new_window - Whether to add target="_blank"
 * @returns {string} Content with URLs converted to links
 * @private
 */
function _linkify_content(content, new_window) {
    const target = new_window ? ' target="_blank" rel="noopener noreferrer"' : '';

    // Pattern for URLs with protocol
    const url_pattern = /(https?:\/\/[^\s<>\[\]()]+)/gi;

    // Pattern for domain-like text
    const domain_pattern = new RegExp(
        '\\b((?:[a-zA-Z0-9](?:[a-zA-Z0-9-]*[a-zA-Z0-9])?\\.)+(' + LINKIFY_TLDS + ')(?:\\/[^\\s<>\\[\\]()]*)?)\\b',
        'gi'
    );

    // First, replace URLs with protocol
    content = content.replace(url_pattern, (match) => {
        // Clean trailing punctuation that's likely not part of URL
        const url = match.replace(/[.,;:!?)'\"]+$/, '');
        const trailing = match.slice(url.length);
        return '<a href="' + url + '"' + target + '>' + url + '</a>' + trailing;
    });

    // Then, replace domain-like text only in segments NOT inside <a> tags
    // (the URL replacement above may have created <a> tags)
    const link_pattern = /(<a\s[^>]*>.*?<\/a>)/gi;
    const segments = content.split(link_pattern);

    content = segments.map(segment => {
        // Skip segments that are already links
        if (/^<a\s/i.test(segment)) {
            return segment;
        }
        // Apply domain pattern to non-link segments
        return segment.replace(domain_pattern, (match) => {
            // Clean trailing punctuation
            const domain = match.replace(/[.,;:!?)'\"]+$/, '');
            const trailing = match.slice(domain.length);
            return '<a href="https://' + domain + '"' + target + '>' + domain + '</a>' + trailing;
        });
    }).join('');

    return content;
}

// ============================================================================
// OBJECT AND ARRAY UTILITIES
// ============================================================================

/**
 * Counts the number of properties in an object or elements in an array
 * @param {Object|Array} o - Object or array to count
 * @returns {number} Number of own properties/elements
 */
function count(o) {
    let c = 0;
    for (const k in o) {
        if (o.hasOwnProperty(k)) {
            ++c;
        }
    }
    return c;
}

/**
 * Creates a shallow clone of an object, array, or function
 * @param {*} obj - Value to clone
 * @returns {*} Cloned value
 */
function clone(obj) {
    if (typeof Function.prototype.__clone == undef) {
        Function.prototype.__clone = function () {
            //https://stackoverflow.com/questions/1833588/javascript-clone-a-function
            const that = this;
            let temp = function cloned() {
                return that.apply(this, arguments);
            };
            for (let key in this) {
                if (this.hasOwnProperty(key)) {
                    temp[key] = this[key];
                }
            }
            return temp;
        };
    }

    if (typeof obj == 'function') {
        return obj.__clone();
    } else if (obj.constructor && obj.constructor == Array) {
        return obj.slice(0);
    } else {
        // https://stackoverflow.com/questions/728360/how-do-i-correctly-clone-a-javascript-object/30042948#30042948
        return Object.assign({}, obj);
    }
}

/**
 * Returns the first non-null/undefined value from arguments
 * @param {...*} arguments - Values to check
 * @returns {*} First non-null/undefined value, or null if none found
 */
function coalesce() {
    let args = Array.from(arguments);
    let return_val = null;
    args.forEach(function (arg) {
        if (return_val === null && typeof arg != undef && arg !== null) {
            return_val = arg;
        }
    });
    return return_val;
}

/**
 * Converts CSV string to array, trimming each element
 * @param {string} str_csv - CSV string to convert
 * @returns {Array<string>} Array of trimmed values
 * @todo Handle quoted/escaped characters
 */
function csv_to_array_trim(str_csv) {
    const parts = str_csv.split(',');
    const ret = [];
    foreach(parts, (part) => {
        ret.push(part.trim());
    });
    return ret;
}

// ============================================================================
// URL UTILITIES
// ============================================================================

/**
 * Convert a full URL to short URL by removing protocol
 *
 * Strips http:// or https:// from the beginning of the URL if present.
 * Leaves the URL alone if it doesn't start with either protocol.
 * Removes trailing slash if there is no path.
 *
 * @param {string|null} url - URL to convert
 * @returns {string|null} Short URL without protocol
 */
function full_url_to_short_url(url) {
    if (url === null || url === undefined || url === '') {
        return url;
    }

    // Convert to string if needed
    url = String(url);

    // Remove http:// or https:// from the beginning (case-insensitive)
    if (url.toLowerCase().indexOf('http://') === 0) {
        url = url.substring(7);
    } else if (url.toLowerCase().indexOf('https://') === 0) {
        url = url.substring(8);
    }

    // Remove trailing slash if there is no path (just domain)
    // Check if URL is just domain with trailing slash (no path after slash)
    if (url.endsWith('/') && (url.match(/\//g) || []).length === 1) {
        url = url.replace(/\/$/, '');
    }

    return url;
}

/**
 * Convert a short URL to full URL by adding protocol
 *
 * Adds http:// to the beginning of the URL if it lacks a protocol.
 * Leaves URLs with existing http:// or https:// unchanged.
 * Adds trailing slash if there is no path.
 *
 * @param {string|null} url - URL to convert
 * @returns {string|null} Full URL with protocol
 */
function short_url_to_full_url(url) {
    if (url === null || url === undefined || url === '') {
        return url;
    }

    // Convert to string if needed
    url = String(url);

    let full_url;

    // Check if URL already has a protocol (case-insensitive)
    if (url.toLowerCase().indexOf('http://') === 0 || url.toLowerCase().indexOf('https://') === 0) {
        full_url = url;
    } else {
        // Add http:// protocol
        full_url = 'http://' + url;
    }

    // Add trailing slash if there is no path (just domain)
    // Check if URL has no slash after the domain
    const without_protocol = full_url.replace(/^https?:\/\//i, '');
    if (without_protocol.indexOf('/') === -1) {
        full_url += '/';
    }

    return full_url;
}
