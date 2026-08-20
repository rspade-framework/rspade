/*
 * Date and time utility functions for the RSpade framework.
 * These functions handle date/time conversions and Unix timestamps.
 */

// ============================================================================
// DATE/TIME UTILITIES
// ============================================================================

/**
 * Gets the current Unix timestamp (seconds since epoch)
 * @returns {number} Current Unix timestamp in seconds
 * @todo Calculate based on server time at page render
 * @todo Move to a date library
 */
function unix_time() {
    return Math.round(new Date().getTime() / 1000);
}

/**
 * Converts a date string to Unix timestamp
 * @param {string} str_date - Date string (Y-m-d H:i:s format)
 * @returns {number} Unix timestamp in seconds
 */
function ymdhis_to_unix(str_date) {
    const date = new Date(str_date);
    return date.getTime() / 1000;
}