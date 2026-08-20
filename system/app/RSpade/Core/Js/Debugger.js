/**
 * Debugger class for console_debug and browser error logging
 * Handles batched submission to server when configured
 */
class Debugger {
    // Batching state for console_debug messages
    static _console_batch = [];
    static _console_timer = null;
    static _console_batch_count = 0;

    // Batching state for error messages
    static _error_batch = [];
    static _error_timer = null;
    static _error_count = 0;
    static _error_batch_count = 0;

    // Constants
    static DEBOUNCE_MS = 2000;
    static MAX_ERRORS_PER_PAGE = 20;
    static MAX_ERROR_BATCHES = 5;

    // Store start time for benchmarking
    static _start_time = null;

    /**
     * Initialize framework error handling
     * Called during framework initialization
     */
    static _on_framework_core_init() {
        // Check if browser error logging is enabled
        if (window.rsxapp && window.rsxapp.log_browser_errors) {
            // Listen for unhandled exceptions from Rsx event system
            Rsx.on('unhandled_exception', function (payload) {
                // Extract exception from payload
                const exception = payload.exception;

                // Normalize exception to error data object
                // Contract: exception can be Error object or string
                let errorData = {};

                if (exception instanceof Error) {
                    // Extract properties from Error object
                    errorData.message = exception.message;
                    errorData.stack = exception.stack;
                    errorData.filename = exception.filename;
                    errorData.lineno = exception.lineno;
                    errorData.colno = exception.colno;
                    errorData.type = 'exception';
                } else if (typeof exception === 'string') {
                    // Plain string message
                    errorData.message = exception;
                    errorData.type = 'manual';
                } else if (exception && typeof exception === 'object') {
                    // Object with message property (structured error)
                    errorData = exception;
                    if (!errorData.type) {
                        errorData.type = 'manual';
                    }
                } else {
                    // Default case for unknown types
                    errorData.message = String(exception);
                    errorData.type = 'unknown';
                }

                Debugger._handle_browser_error(errorData);
            });
        }

        // Register ui refresh handler
        Rsx.on('refresh', Debugger.on_refresh);
    }

    // In dev mode, some ui elements can be automatically applied to assist with development
    static on_refresh() {
        if (!Rsx.is_prod()) {
            // Add an underline 2 px blue to all a tags with href === "#" using jquery
            // Todo: maybe this should be a configurable debug option?
            // $('a[href="#"]').css({
            //     'border-bottom': '2px solid blue',
            //     'text-decoration': 'none'
            // });
        }
    }

    /**
     * JavaScript implementation of console_debug
     * Mirrors PHP functionality with batching for Laravel log
     */
    static console_debug(channel, ...values) {
        // Check if console_debug is enabled
        if (!window.rsxapp || !window.rsxapp.console_debug || !window.rsxapp.console_debug.enabled) {
            return;
        }

        const config = window.rsxapp.console_debug;

        // Normalize channel name
        channel = String(channel)
            .toUpperCase()
            .replace(/[\[\]]/g, '');

        // Apply filtering
        if (config.filter_mode === 'specific') {
            const specific = config.specific_channel;
            if (specific) {
                // Split comma-separated values and normalize
                const channels = specific.split(',').map((c) => c.trim().toUpperCase());
                if (!channels.includes(channel)) {
                    return;
                }
            }
        } else if (config.filter_mode === 'whitelist') {
            const whitelist = (config.filter_channels || []).map((c) => c.toUpperCase());
            if (!whitelist.includes(channel)) {
                return;
            }
        } else if (config.filter_mode === 'blacklist') {
            const blacklist = (config.filter_channels || []).map((c) => c.toUpperCase());
            if (blacklist.includes(channel)) {
                return;
            }
        }

        // Prepare the message
        let message = {
            channel: channel,
            values: values,
            timestamp: new Date().toISOString(),
        };

        // Add location if configured
        if (config.include_location || config.include_backtrace) {
            const error = new Error();
            const stack = error.stack || '';
            const stackLines = stack.split('\n');

            if (config.include_location && stackLines.length > 2) {
                // Skip Error line and this function
                const callerLine = stackLines[2] || '';
                const match = callerLine.match(/at\s+.*?\s+\((.*?):(\d+):(\d+)\)/) || callerLine.match(/at\s+(.*?):(\d+):(\d+)/);
                if (match) {
                    message.location = `${match[1]}:${match[2]}`;
                }
            }

            if (config.include_backtrace) {
                // Include first 5 stack frames, skipping this function
                message.backtrace = stackLines
                    .slice(2, 7)
                    .map((line) => line.trim())
                    .filter((line) => line);
            }
        }

        // Output to browser console if enabled
        if (config.outputs && config.outputs.browser) {
            const prefix = config.include_benchmark ? `[${Debugger._get_time_prefix()}] ` : '';
            const channelPrefix = `[${channel}]`;

            // Use appropriate console method based on channel
            let consoleMethod = 'log';
            if (channel.includes('ERROR')) consoleMethod = 'error';
            else if (channel.includes('WARN')) consoleMethod = 'warn';
            else if (channel.includes('INFO')) consoleMethod = 'info';

            console[consoleMethod](prefix + channelPrefix, ...values);
        }

        // Batch for Laravel log if enabled
        if (config.outputs && config.outputs.laravel_log) {
            Debugger._batch_console_message(message);
        }
    }

    /**
     * Log an error to the server
     * Used manually or by Ajax error handling
     */
    static log_error(error) {
        // Check if browser error logging is enabled
        if (!window.rsxapp || !window.rsxapp.log_browser_errors) {
            return;
        }

        // Normalize error format
        let errorData = {};
        if (typeof error === 'string') {
            errorData.message = error;
            errorData.type = 'manual';
        } else if (error instanceof Error) {
            errorData.message = error.message;
            errorData.stack = error.stack;
            errorData.type = 'exception';
        } else if (error && typeof error === 'object') {
            errorData = error;
            if (!errorData.type) {
                errorData.type = 'manual';
            }
        }

        Debugger._handle_browser_error(errorData);
    }

    /**
     * Internal: Handle browser errors with batching
     */
    static _handle_browser_error(errorData) {
        // Check limits
        if (Debugger._error_count >= Debugger.MAX_ERRORS_PER_PAGE) {
            return;
        }
        if (Debugger._error_batch_count >= Debugger.MAX_ERROR_BATCHES) {
            return;
        }

        Debugger._error_count++;

        // Add metadata
        errorData.url = window.location.href;
        errorData.userAgent = navigator.userAgent;
        errorData.timestamp = new Date().toISOString();

        // Add to batch
        Debugger._error_batch.push(errorData);

        // Clear existing timer
        if (Debugger._error_timer) {
            clearTimeout(Debugger._error_timer);
        }

        // Set debounce timer
        Debugger._error_timer = setTimeout(() => {
            Debugger._flush_error_batch();
        }, Debugger.DEBOUNCE_MS);
    }

    /**
     * Internal: Batch console_debug messages for Laravel log
     */
    static _batch_console_message(message) {
        Debugger._console_batch.push(message);

        // Clear existing timer
        if (Debugger._console_timer) {
            clearTimeout(Debugger._console_timer);
        }

        // Set debounce timer
        Debugger._console_timer = setTimeout(() => {
            Debugger._flush_console_batch();
        }, Debugger.DEBOUNCE_MS);
    }

    /**
     * Internal: Flush console_debug batch to server
     */
    static async _flush_console_batch() {
        if (Debugger._console_batch.length === 0) {
            return;
        }

        const messages = Debugger._console_batch;
        Debugger._console_batch = [];
        Debugger._console_timer = null;

        try {
            return Ajax.call(Rsx.Route('Debugger_Controller::log_console_messages'), { messages: messages });
        } catch (error) {
            // Silently fail - don't create error loop
            console.error('Failed to send console_debug messages to server:', error);
        }
    }

    /**
     * Internal: Flush error batch to server
     */
    static async _flush_error_batch() {
        if (Debugger._error_batch.length === 0) {
            return;
        }

        const errors = Debugger._error_batch;
        Debugger._error_batch = [];
        Debugger._error_timer = null;
        Debugger._error_batch_count++;

        try {
            return Ajax.call(Rsx.Route('Debugger_Controller::log_browser_errors'), { errors: errors });
        } catch (error) {
            // Silently fail - don't create error loop
            console.error('Failed to send browser errors to server:', error);
        }
    }

    /**
     * Internal: Get time prefix for benchmarking
     */
    static _get_time_prefix() {
        const now = Date.now();
        if (!Debugger._start_time) {
            Debugger._start_time = now;
        }
        const elapsed = now - Debugger._start_time;
        return (elapsed / 1000).toFixed(3) + 's';
    }
}
