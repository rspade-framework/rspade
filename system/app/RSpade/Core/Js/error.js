/*
 * Error handling utility functions for the RSpade framework.
 * These functions handle error creation and debugging utilities.
 */

// ============================================================================
// ERROR HANDLING
// ============================================================================

/**
 * Creates an error object from a string
 * @param {string|Object} str - Error message or existing error object
 * @param {number} [error_code] - Optional error status code
 * @returns {Object} Error object with error and status properties
 */
function error(str, error_code) {
    if (typeof str.error != undef) {
        return str;
    } else {
        if (typeof error_code == undef) {
            return { error: str, status: null };
        } else {
            return { error: str, status: error_code };
        }
    }
}

/**
 * Sanity check failure handler for JavaScript
 *
 * This function should be called when a sanity check fails - i.e., when the code
 * encounters a condition that "shouldn't happen" if everything is working correctly.
 *
 * Unlike PHP, we can't stop JavaScript execution, but we can:
 * 1. Throw an error that will be caught by error handlers
 * 2. Log a clear error to the console
 * 3. Provide stack trace for debugging
 *
 * Use this instead of silently returning or continuing when encountering unexpected conditions.
 *
 * @param {string} message Optional specific message about what shouldn't have happened
 * @throws {Error} Always throws with location and context information
 */
function shouldnt_happen(message = null) {
    const error = new Error();
    const stack = error.stack || '';
    const stackLines = stack.split('\n');

    // Get the caller location (skip the Error line and this function)
    let callerInfo = 'unknown location';
    if (stackLines.length > 2) {
        const callerLine = stackLines[2] || stackLines[1] || '';
        // Extract file and line number from stack trace
        const match = callerLine.match(/at\s+.*?\s+\((.*?):(\d+):(\d+)\)/) || callerLine.match(/at\s+(.*?):(\d+):(\d+)/);
        if (match) {
            callerInfo = `${match[1]}:${match[2]}`;
        }
    }

    let errorMessage = `Fatal: shouldnt_happen() was called at ${callerInfo}\n`;
    errorMessage += 'This indicates a sanity check failed - the code is not behaving as expected.\n';

    if (message) {
        errorMessage += `Details: ${message}\n`;
    }

    errorMessage += 'Please thoroughly review the related code to determine why this error occurred.';

    // Log to console with full visibility
    console.error('='.repeat(80));
    console.error('SANITY CHECK FAILURE');
    console.error('='.repeat(80));
    console.error(errorMessage);
    console.error('Stack trace:', stack);
    console.error('='.repeat(80));

    // Throw error to stop execution flow
    const fatalError = new Error(errorMessage);
    fatalError.name = 'SanityCheckFailure';
    throw fatalError;
}