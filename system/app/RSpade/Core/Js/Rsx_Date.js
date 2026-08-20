/**
 * Rsx_Date - Date-only handling for RSpade (no time, no timezone)
 *
 * Dates are calendar dates without time components. They are timezone-agnostic:
 * "December 24, 2025" is the same calendar date everywhere in the world.
 *
 * Format: Always "YYYY-MM-DD" (ISO 8601 date format)
 *
 * Core Principles:
 * - Dates have NO time component and NO timezone
 * - All dates stored and transferred as "YYYY-MM-DD"
 * - Date functions THROW if passed a datetime (has time component)
 * - Use Rsx_Time for moments in time that need timezone handling
 *
 * See: php artisan rsx:man time
 */
class Rsx_Date {

    /**
     * Regex pattern for valid date-only string
     * @type {RegExp}
     */
    static DATE_PATTERN = /^\d{4}-\d{2}-\d{2}$/;

    // =========================================================================
    // PARSING & VALIDATION
    // =========================================================================

    /**
     * Parse input to date string "YYYY-MM-DD"
     * THROWS if input is a datetime (has time component)
     *
     * @param {*} input
     * @returns {string|null} Returns "YYYY-MM-DD" or null
     * @throws {Error} If input is a datetime
     */
    static parse(input) {
        if (input == null || input === '') {
            return null;
        }

        // Already a valid date string
        if (typeof input === 'string' && this.DATE_PATTERN.test(input)) {
            // Validate it's a real date
            if (this._is_valid_date_string(input)) {
                return input;
            }
            return null;
        }

        // Reject Date objects - these are datetimes
        if (input instanceof Date) {
            throw new Error(
                "Rsx_Date.parse() received Date object. " +
                "Use Rsx_Time.parse() for datetimes with time components."
            );
        }

        // Reject timestamps - these are datetimes
        if (typeof input === 'number') {
            throw new Error(
                `Rsx_Date.parse() received numeric timestamp '${input}'. ` +
                "Use Rsx_Time.parse() for datetimes with time components."
            );
        }

        // Reject datetime strings (contain T or time component)
        if (typeof input === 'string') {
            if (input.includes('T') || /\d{2}:\d{2}/.test(input)) {
                throw new Error(
                    `Rsx_Date.parse() received datetime string '${input}'. ` +
                    "Use Rsx_Time.parse() for datetimes with time components."
                );
            }
        }

        return null;
    }

    /**
     * Check if input is a valid date-only value (not datetime)
     *
     * @param {*} input
     * @returns {boolean}
     */
    static is_date(input) {
        if (typeof input !== 'string') {
            return false;
        }

        if (!this.DATE_PATTERN.test(input)) {
            return false;
        }

        return this._is_valid_date_string(input);
    }

    /**
     * Validate that a YYYY-MM-DD string represents a real date
     * @private
     */
    static _is_valid_date_string(str) {
        const [year, month, day] = str.split('-').map(Number);
        const date = new Date(year, month - 1, day);
        return date.getFullYear() === year &&
               date.getMonth() === month - 1 &&
               date.getDate() === day;
    }

    // =========================================================================
    // CURRENT DATE
    // =========================================================================

    /**
     * Alias for today()
     *
     * @returns {string}
     */
    static now() {
        return this.today();
    }

    /**
     * Get today's date as "YYYY-MM-DD"
     * Uses user → site → default timezone from rsxapp
     *
     * @returns {string}
     */
    static today() {
        const now = Rsx_Time.now();
        const tz = Rsx_Time.get_user_timezone();

        // Use Intl.DateTimeFormat with en-CA locale for YYYY-MM-DD format
        const formatter = new Intl.DateTimeFormat('en-CA', {
            timeZone: tz,
            year: 'numeric',
            month: '2-digit',
            day: '2-digit'
        });

        return formatter.format(now);
    }

    // =========================================================================
    // FORMATTING
    // =========================================================================

    /**
     * Format date for display: "Dec 24, 2025"
     *
     * @param {*} date
     * @returns {string}
     */
    static format(date) {
        const parsed = this.parse(date);
        if (!parsed) {
            return '';
        }

        const [year, month, day] = parsed.split('-').map(Number);
        const d = new Date(year, month - 1, day);

        return new Intl.DateTimeFormat('en-US', {
            month: 'short',
            day: 'numeric',
            year: 'numeric'
        }).format(d);
    }

    /**
     * Ensure date is in ISO format "YYYY-MM-DD"
     * Alias for parse() that always returns string
     *
     * @param {*} date
     * @returns {string}
     */
    static format_iso(date) {
        return this.parse(date) || '';
    }

    // =========================================================================
    // COMPARISON
    // =========================================================================

    /**
     * Check if date is today (in user's timezone)
     *
     * @param {*} date
     * @returns {boolean}
     */
    static is_today(date) {
        const parsed = this.parse(date);
        if (!parsed) {
            return false;
        }
        return parsed === this.today();
    }

    /**
     * Check if date is in the past
     *
     * @param {*} date
     * @returns {boolean}
     */
    static is_past(date) {
        const parsed = this.parse(date);
        if (!parsed) {
            return false;
        }
        return parsed < this.today();
    }

    /**
     * Check if date is in the future
     *
     * @param {*} date
     * @returns {boolean}
     */
    static is_future(date) {
        const parsed = this.parse(date);
        if (!parsed) {
            return false;
        }
        return parsed > this.today();
    }

    /**
     * Calculate days between two dates
     * Returns positive if date2 > date1
     *
     * @param {*} date1
     * @param {*} date2
     * @returns {number}
     */
    static diff_days(date1, date2) {
        const d1 = this.parse(date1);
        const d2 = this.parse(date2);

        if (!d1 || !d2) {
            return 0;
        }

        const ms1 = new Date(d1).getTime();
        const ms2 = new Date(d2).getTime();

        return Math.round((ms2 - ms1) / (1000 * 60 * 60 * 24));
    }

    /**
     * Format as relative date ("Today", "Yesterday", "3 days ago", "in 5 days")
     *
     * @param {*} date
     * @returns {string}
     */
    static relative(date) {
        const parsed = this.parse(date);
        if (!parsed) {
            return '';
        }

        const days = this.diff_days(this.today(), parsed);

        if (days === 0) {
            return 'Today';
        } else if (days === 1) {
            return 'Tomorrow';
        } else if (days === -1) {
            return 'Yesterday';
        } else if (days > 1) {
            return `in ${days} days`;
        } else {
            return `${Math.abs(days)} days ago`;
        }
    }

    // =========================================================================
    // ARITHMETIC
    // =========================================================================

    /**
     * Add days to a date
     *
     * @param {*} date
     * @param {number} days Can be negative to subtract
     * @returns {string|null} "YYYY-MM-DD" or null if invalid input
     */
    static add_days(date, days) {
        const parsed = this.parse(date);
        if (!parsed) {
            return null;
        }

        const [year, month, day] = parsed.split('-').map(Number);
        const d = new Date(year, month - 1, day);
        d.setDate(d.getDate() + days);

        return d.getFullYear() + '-' +
               String(d.getMonth() + 1).padStart(2, '0') + '-' +
               String(d.getDate()).padStart(2, '0');
    }

    // =========================================================================
    // WEEK/MONTH BOUNDARIES
    // =========================================================================

    /**
     * Get the Monday of the week containing the date
     *
     * @param {*} date
     * @returns {string|null} "YYYY-MM-DD" or null if invalid input
     */
    static start_of_week(date) {
        const parsed = this.parse(date);
        if (!parsed) {
            return null;
        }

        const [year, month, day] = parsed.split('-').map(Number);
        const d = new Date(year, month - 1, day);
        const dow = d.getDay();
        // Convert to Monday=0 based, then subtract to get Monday
        const daysToSubtract = (dow === 0) ? 6 : dow - 1;
        d.setDate(d.getDate() - daysToSubtract);

        return d.getFullYear() + '-' +
               String(d.getMonth() + 1).padStart(2, '0') + '-' +
               String(d.getDate()).padStart(2, '0');
    }

    /**
     * Get the Sunday of the week containing the date
     *
     * @param {*} date
     * @returns {string|null} "YYYY-MM-DD" or null if invalid input
     */
    static end_of_week(date) {
        const parsed = this.parse(date);
        if (!parsed) {
            return null;
        }

        const [year, month, day] = parsed.split('-').map(Number);
        const d = new Date(year, month - 1, day);
        const dow = d.getDay();
        // Days to add to get to Sunday
        const daysToAdd = (dow === 0) ? 0 : 7 - dow;
        d.setDate(d.getDate() + daysToAdd);

        return d.getFullYear() + '-' +
               String(d.getMonth() + 1).padStart(2, '0') + '-' +
               String(d.getDate()).padStart(2, '0');
    }

    /**
     * Get the first day of the month containing the date
     *
     * @param {*} date
     * @returns {string|null} "YYYY-MM-DD" or null if invalid input
     */
    static start_of_month(date) {
        const parsed = this.parse(date);
        if (!parsed) {
            return null;
        }

        const [year, month] = parsed.split('-').map(Number);
        return year + '-' + String(month).padStart(2, '0') + '-01';
    }

    /**
     * Get the last day of the month containing the date
     *
     * @param {*} date
     * @returns {string|null} "YYYY-MM-DD" or null if invalid input
     */
    static end_of_month(date) {
        const parsed = this.parse(date);
        if (!parsed) {
            return null;
        }

        const [year, month] = parsed.split('-').map(Number);
        // Day 0 of next month = last day of current month
        const d = new Date(year, month, 0);

        return d.getFullYear() + '-' +
               String(d.getMonth() + 1).padStart(2, '0') + '-' +
               String(d.getDate()).padStart(2, '0');
    }

    /**
     * Check if date falls on a weekend (Saturday or Sunday)
     *
     * @param {*} date
     * @returns {boolean}
     */
    static is_weekend(date) {
        const dow = this.dow(date);
        if (dow === null) {
            return false;
        }
        return dow === 0 || dow === 6;
    }

    /**
     * Check if date falls on a weekday (Monday-Friday)
     *
     * @param {*} date
     * @returns {boolean}
     */
    static is_weekday(date) {
        const dow = this.dow(date);
        if (dow === null) {
            return false;
        }
        return dow >= 1 && dow <= 5;
    }

    // =========================================================================
    // COMPONENT EXTRACTORS
    // =========================================================================

    /**
     * Parse and convert to JS Date object (internal helper)
     * Returns null if invalid, throws on datetime input
     */
    static _to_js_date(date) {
        const parsed = this.parse(date);
        if (!parsed) return null;
        const [year, month, day] = parsed.split('-').map(Number);
        return new Date(year, month - 1, day);
    }

    /**
     * Get day of month (1-31)
     */
    static day(date) {
        const parsed = this.parse(date);
        return parsed ? parseInt(parsed.split('-')[2], 10) : null;
    }

    /**
     * Get day of week (0=Sunday, 6=Saturday)
     */
    static dow(date) {
        return this._to_js_date(date)?.getDay() ?? null;
    }

    /**
     * Get full day name ("Monday", "Tuesday", etc.)
     */
    static dow_human(date) {
        const d = this._to_js_date(date);
        return d ? new Intl.DateTimeFormat('en-US', { weekday: 'long' }).format(d) : '';
    }

    /**
     * Get short day name ("Mon", "Tue", etc.)
     */
    static dow_short(date) {
        const d = this._to_js_date(date);
        return d ? new Intl.DateTimeFormat('en-US', { weekday: 'short' }).format(d) : '';
    }

    /**
     * Get month (1-12)
     */
    static month(date) {
        const parsed = this.parse(date);
        return parsed ? parseInt(parsed.split('-')[1], 10) : null;
    }

    /**
     * Get full month name ("January", "February", etc.)
     */
    static month_human(date) {
        const d = this._to_js_date(date);
        return d ? new Intl.DateTimeFormat('en-US', { month: 'long' }).format(d) : '';
    }

    /**
     * Get short month name ("Jan", "Feb", etc.)
     */
    static month_human_short(date) {
        const d = this._to_js_date(date);
        return d ? new Intl.DateTimeFormat('en-US', { month: 'short' }).format(d) : '';
    }

    /**
     * Get year (e.g., 2025)
     */
    static year(date) {
        const parsed = this.parse(date);
        return parsed ? parseInt(parsed.split('-')[0], 10) : null;
    }
}
