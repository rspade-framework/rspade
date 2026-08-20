// @FILE-SUBCLASS-01-EXCEPTION

/**
 * Client-side Ajax class for making API calls to RSX controllers
 *
 * Automatically batches multiple calls into single HTTP requests to reduce network overhead.
 * Batches up to 20 calls or flushes after setTimeout(0) debounce.
 */
class Ajax {
    // Error code constants (must match server-side Ajax::ERROR_* constants)
    static ERROR_VALIDATION = 'validation';
    static ERROR_NOT_FOUND = 'not_found';
    static ERROR_UNAUTHORIZED = 'unauthorized';
    static ERROR_AUTH_REQUIRED = 'auth_required';
    static ERROR_FATAL = 'fatal';
    static ERROR_GENERIC = 'generic';
    static ERROR_SERVER = 'server_error';           // Client-generated (HTTP 500)
    static ERROR_NETWORK = 'network_error';         // Client-generated (connection failed)
    static ERROR_PHP_EXCEPTION = 'php_exception';   // Client-generated (PHP exception with file/line/backtrace)

    /**
     * Initialize Ajax system
     * Called automatically when class is loaded
     */
    static _on_framework_core_init() {
        // Queue of pending calls waiting to be batched
        Ajax._pending_calls = {};

        // Timer for batching flush
        Ajax._flush_timeout = null;

        // Call counter for generating unique call IDs
        Ajax._call_counter = 0;

        // Maximum batch size before forcing immediate flush
        Ajax.MAX_BATCH_SIZE = 20;

        // Debounce time in milliseconds
        Ajax.DEBOUNCE_MS = 0;

        // Track promises from Ajax calls to detect uncaught rejections
        Ajax._tracked_promises = new WeakSet();

        // Set up global unhandled rejection handler for Ajax errors
        window.addEventListener('unhandledrejection', async (event) => {
            // Only handle rejections from Ajax promises
            if (Ajax._tracked_promises.has(event.promise)) {
                event.preventDefault(); // Prevent browser's default "Uncaught (in promise)" error

                const error = event.reason;
                console.error('Uncaught Ajax error:', error);

                // Suppress errors during navigation grace period
                // After page navigation, pending requests from the old page may error out
                // These errors are not relevant to the new page
                if (typeof Spa !== 'undefined' && Spa.is_within_navigation_grace_period()) {
                    console.log('[Ajax] Suppressing error modal during navigation grace period');
                    return;
                }

                // Show error modal for uncaught Ajax errors
                // In debug mode, use fatal_error() for detailed file/line info
                if (typeof Modal !== 'undefined') {
                    if (window.rsxapp?.debug && Modal.fatal_error) {
                        await Modal.fatal_error(error, 'Uncaught Ajax Error');
                    } else if (Modal.error) {
                        await Modal.error(error, 'Uncaught Ajax Error');
                    }
                }
            }
        });
    }

    /**
     * Make an AJAX call to an RSX controller action
     *
     * Calls are batched when window.rsxapp.ajax_batching is true (debug/production modes).
     * In development mode, batching is disabled for easier debugging.
     *
     * NOT async by design: an async function returns an adopting promise, which is a
     * DIFFERENT object than the one registered in Ajax._tracked_promises - the
     * unhandledrejection identity gate would never match the promise the caller holds
     * and the curated Ajax error modal would never fire. The object returned here MUST
     * be the exact object that was tracked.
     *
     * @param {string|object|function} url - The Ajax URL (e.g., '/_ajax/Controller_Name/action_name') or an object/function with a .path property
     * @param {object} params - Parameters to send to the action
     * @returns {Promise} - Resolves with the return value, rejects with error
     */
    static call(url, params = {}) {
        let promise;

        try {
            // If url is an object or function with a .path property, use that as the URL
            if (url && typeof url === 'object' && url.path) {
                url = url.path;
            } else if (url && typeof url === 'function' && url.path) {
                url = url.path;
            }

            // Validate url is a non-empty string
            if (typeof url !== 'string' || url.length === 0) {
                throw new Error('Ajax.call() requires a non-empty string URL or an object/function with a .path property');
            }

            // Extract controller and action from URL
            const { controller, action } = Ajax.ajax_url_to_controller_action(url);

            console.log('Ajax:', controller, action, params);

            // Batch calls in debug/production mode, direct calls in development mode
            if (window.rsxapp && window.rsxapp.ajax_batching) {
                promise = Ajax._call_batch(controller, action, params);
            } else {
                promise = Ajax._call_direct(controller, action, params);
            }
        } catch (error) {
            // Argument/URL errors reach the caller as a rejection, never a synchronous
            // throw - every caller holds a promise, in every case.
            promise = Promise.reject(error);
        }

        // Track this promise for unhandled rejection detection
        Ajax._tracked_promises.add(promise);

        return promise;
    }

    /**
     * Upload a multipart FormData to the framework upload endpoint.
     *
     * The one client-side upload transport. It owns the two things every caller was
     * getting wrong on its own:
     *
     *   - the CHANNEL: Rsx_Portal.internal_url() rebases /_upload onto the base this
     *     page is served under, so a portal upload is dispatched as a portal request.
     *   - the CSRF TOKEN: multipart uploads must use native fetch (jQuery cannot send
     *     FormData through the $.ajax chokepoint that normally attaches the header),
     *     so the header is attached here instead. A session-bearing POST without it
     *     is rejected by Rsx_Csrf.
     *
     * It also refuses an over-size file BEFORE sending it. The server is the enforcement
     * boundary and always will be - this check exists so the user is told in the first
     * second rather than after minutes of pushing a doomed file up a slow connection.
     * The ceiling is window.rsxapp.files.max_file_size, injected from the
     * rsx.files.max_file_size config on every page.
     *
     * Resolves with the parsed endpoint response ({success, attachment}); throws with
     * the endpoint's own error text on any failure.
     *
     * @param {FormData} form_data Must carry the 'file' part
     * @returns {Promise<object>} The parsed upload response
     */
    /**
     * The upload size ceiling as a string you can put in front of a user: "100 MB".
     *
     * Every "Max size 25MB" in a template is a number that will be wrong the day the
     * limit changes, and nobody will notice because nothing breaks - the label just
     * quietly lies. This reads window.rsxapp.files.max_file_size, so the notice and the
     * enforcement can never disagree.
     *
     * Rounding is chosen for a LABEL, not for accuracy: whole MB up to 1 GB, one decimal
     * from 1 GB to 10 GB (so 1.2 GB does not read as "1 GB"), whole GB above that. Below
     * 1 MB it falls back to whole KB rather than rounding to a meaningless "0 MB".
     *
     * PHP mirror with identical output: Ajax::max_file_size_human().
     *
     * @returns {string} e.g. "100 MB", "1.2 GB", "12 GB", or "Unlimited" when uncapped
     */
    static max_file_size_human() {
        return Ajax.bytes_to_size_label(window.rsxapp?.files?.max_file_size || 0);
    }

    /**
     * The label formatter behind max_file_size_human(), exposed for an app that caps
     * tighter than the framework and wants its own number rendered the same way.
     *
     * @param {number} bytes 0 (or anything non-positive) means no ceiling
     * @returns {string}
     */
    static bytes_to_size_label(bytes) {
        const value = int(bytes);

        if (value <= 0) {
            return 'Unlimited';
        }

        const KB = 1024;
        const MB = KB * 1024;
        const GB = MB * 1024;

        if (value < MB) {
            return Math.round(value / KB) + ' KB';
        }
        if (value < GB) {
            return Math.round(value / MB) + ' MB';
        }
        if (value < 10 * GB) {
            // One decimal, trailing .0 dropped: "1.2 GB", but "2 GB" not "2.0 GB".
            return Number((value / GB).toFixed(1)) + ' GB';
        }

        return Math.round(value / GB) + ' GB';
    }

    static async upload(form_data) {
        // 0 (or an absent block on a page rendered before this shipped) means no framework
        // ceiling - which must read as UNLIMITED, never as "reject everything".
        const max_file_size = window.rsxapp?.files?.max_file_size || 0;
        const file = form_data.get('file');

        if (max_file_size > 0 && file && is_numeric(file.size) && file.size > max_file_size) {
            throw new Error(
                'File is too large: ' + bytes_to_human(file.size)
                + ' exceeds the ' + bytes_to_human(max_file_size) + ' limit.'
            );
        }

        const headers = {};
        if (window.rsxapp && window.rsxapp.csrf) {
            headers['X-CSRF-Token'] = window.rsxapp.csrf;
        }

        const response = await fetch(Rsx_Portal.internal_url('/_upload'), {
            method: 'POST',
            body: form_data,
            headers: headers,
        });

        const result = await response.json();

        if (!response.ok || !result.success || !result.attachment) {
            throw new Error(result.error || 'Upload failed');
        }

        return result;
    }

    /**
     * Make a batched Ajax call
     * @private
     */
    static _call_batch(controller, action, params = {}) {
        console.log('Ajax Batch:', controller, action, params);

        return new Promise((resolve, reject) => {
            // Generate call key for deduplication
            const call_key = Ajax._generate_call_key(controller, action, params);

            // Check if this exact call is already pending
            if (Ajax._pending_calls[call_key]) {
                const existing_call = Ajax._pending_calls[call_key];

                // If call already completed (cached), return immediately
                if (existing_call.is_complete) {
                    if (existing_call.is_error) {
                        reject(existing_call.error);
                    } else {
                        resolve(existing_call.result);
                    }
                    return;
                }

                // Call is pending, add this promise to callbacks
                existing_call.callbacks.push({ resolve, reject });
                return;
            }

            // Create new pending call
            const call_id = Ajax._call_counter++;
            const pending_call = {
                call_id: call_id,
                call_key: call_key,
                controller: controller,
                action: action,
                params: params,
                callbacks: [{ resolve, reject }],
                is_complete: false,
                is_error: false,
                result: null,
                error: null,
            };

            // Add to pending queue
            Ajax._pending_calls[call_key] = pending_call;

            // Count pending calls
            const pending_count = Object.keys(Ajax._pending_calls).filter((key) => !Ajax._pending_calls[key].is_complete).length;

            // If we've hit the batch size limit, flush immediately
            if (pending_count >= Ajax.MAX_BATCH_SIZE) {
                clearTimeout(Ajax._flush_timeout);
                Ajax._flush_timeout = null;
                Ajax._flush_pending_calls();
            } else {
                // Schedule batch flush with debounce
                clearTimeout(Ajax._flush_timeout);
                Ajax._flush_timeout = setTimeout(() => {
                    Ajax._flush_pending_calls();
                }, Ajax.DEBOUNCE_MS);
            }
        });
    }

    /**
     * Make a direct (non-batched) Ajax call
     * @private
     */
    static async _call_direct(controller, action, params = {}) {
        // Construct URL from controller and action. Rsx_Portal.internal_url() rebases
        // it onto the channel this page is served under - the ONE place the direct
        // transport's URL is built, so generated controller stubs (which all hand
        // their '/_ajax/...' string to Ajax.call) never carry a channel of their own.
        const url = Rsx_Portal.internal_url(`/_ajax/${controller}/${action}`);

        // Log the AJAX call using console_debug
        if (typeof Debugger !== 'undefined' && Debugger.console_debug) {
            Debugger.console_debug('AJAX', `Calling ${controller}.${action} (unbatched)`, params);
        }

        return new Promise((resolve, reject) => {
            $.ajax({
                url: url,
                method: 'POST',
                contentType: 'application/json',
                data: JSON.stringify(params),
                dataType: 'json',
                __local_integration: true, // Bypass $.ajax override
                success: (response) => {
                    // Handle console_debug messages
                    if (response.console_debug && Array.isArray(response.console_debug)) {
                        response.console_debug.forEach((msg) => {
                            if (!Array.isArray(msg) || msg.length !== 2) {
                                throw new Error('Invalid console_debug message format - expected [channel, [arguments]]');
                            }
                            const [channel, args] = msg;
                            console.log(channel, ...args);
                        });
                    }

                    // Sync time with server (only on first AJAX or timezone change)
                    if (response._server_time || response._user_timezone) {
                        Rsx_Time.sync_from_ajax({
                            server_time: response._server_time,
                            user_timezone: response._user_timezone
                        });
                    }

                    // Handle flash_alerts from server
                    if (response.flash_alerts && Array.isArray(response.flash_alerts)) {
                        Server_Side_Flash.process(response.flash_alerts);
                    }

                    // Check if the response was successful
                    if (response._success === true) {
                        // @JS-AJAX-02-EXCEPTION - Unwrap server responses with _ajax_return_value
                        const processed_value = Rsx_Js_Model._instantiate_models_recursive(response._ajax_return_value);
                        resolve(processed_value);
                    } else {
                        // Handle error responses
                        // Server may use error_code or error_type
                        const error_code = response.error_code || response.error_type || Ajax.ERROR_GENERIC;
                        const reason = response.reason || 'An error occurred';
                        const metadata = response.metadata || {};

                        // Create error object
                        const error = new Error(reason);
                        error.code = error_code;
                        error.metadata = metadata;

                        // Handle fatal errors specially - detect PHP exceptions
                        if (error_code === Ajax.ERROR_FATAL) {
                            const fatal_error_data = response.error || {};

                            // Check if this is a PHP exception (has file, line, error, backtrace)
                            if (fatal_error_data.file && fatal_error_data.line && fatal_error_data.error) {
                                error.code = Ajax.ERROR_PHP_EXCEPTION;
                                error.message = fatal_error_data.error;
                                error.metadata = {
                                    file: fatal_error_data.file,
                                    line: fatal_error_data.line,
                                    error: fatal_error_data.error,
                                    backtrace: fatal_error_data.backtrace || []
                                };
                                console.error('PHP Exception:', fatal_error_data.error, 'at', fatal_error_data.file + ':' + fatal_error_data.line);
                            } else {
                                error.message = fatal_error_data.error || 'Fatal error occurred';
                                error.metadata = response.error;
                                console.error('Ajax error response from server:', response.error);
                            }

                            // Log to server
                            Debugger.log_error({
                                message: `Ajax Fatal Error: ${error.message}`,
                                type: 'ajax_fatal',
                                endpoint: url,
                                details: response.error,
                            });
                        }

                        // Log auth errors for debugging
                        if (error_code === Ajax.ERROR_AUTH_REQUIRED) {
                            console.error('User is no longer authenticated');
                        }
                        if (error_code === Ajax.ERROR_UNAUTHORIZED) {
                            console.error('User is unauthorized to perform this action');
                        }

                        reject(error);
                    }
                },
                error: (xhr, status, error) => {
                    const err = new Error();

                    // Determine error code based on status
                    if (xhr.status >= 500) {
                        // Server error (PHP crashed)
                        err.code = Ajax.ERROR_SERVER;
                        err.message = 'A server error occurred. Please try again.';
                    } else if (xhr.status === 0 || status === 'timeout' || status === 'error') {
                        // Network error (connection failed)
                        err.code = Ajax.ERROR_NETWORK;
                        err.message = 'Could not connect to server. Please check your connection.';
                    } else {
                        // Generic error
                        err.code = Ajax.ERROR_GENERIC;
                        err.message = Ajax._extract_error_message(xhr);
                    }

                    err.metadata = {
                        status: xhr.status,
                        statusText: status
                    };

                    // Log server errors to server
                    if (xhr.status >= 500) {
                        Debugger.log_error({
                            message: `Ajax Server Error ${xhr.status}: ${err.message}`,
                            type: 'ajax_server_error',
                            endpoint: url,
                            status: xhr.status,
                            statusText: status,
                        });
                    }

                    reject(err);
                },
            });
        });
    }

    /**
     * Flush all pending calls by sending batch request
     * @private
     */
    static async _flush_pending_calls() {
        // Collect all pending calls
        const calls_to_send = [];
        const call_map = {}; // Map call_id to pending_call object

        for (const call_key in Ajax._pending_calls) {
            const pending_call = Ajax._pending_calls[call_key];

            if (!pending_call.is_complete) {
                calls_to_send.push({
                    call_id: pending_call.call_id,
                    controller: pending_call.controller,
                    action: pending_call.action,
                    params: pending_call.params,
                });

                call_map[pending_call.call_id] = pending_call;
            }
        }

        // Nothing to send
        if (calls_to_send.length === 0) {
            return;
        }

        // Log batch for debugging
        if (typeof Debugger !== 'undefined' && Debugger.console_debug) {
            Debugger.console_debug(
                'AJAX_BATCH',
                `Sending batch of ${calls_to_send.length} calls`,
                calls_to_send.map((c) => `${c.controller}.${c.action}`)
            );
        }

        try {
            // Send batch request
            const response = await $.ajax({
                // Same channel rule as the direct transport (Rsx_Portal.internal_url).
                url: Rsx_Portal.internal_url('/_ajax/_batch'),
                method: 'POST',
                contentType: 'application/json',
                data: JSON.stringify({ batch_calls: calls_to_send }),
                dataType: 'json',
                __local_integration: true, // Bypass $.ajax override
            });

            // Sync time with server (only on first AJAX or timezone change)
            if (response._server_time || response._user_timezone) {
                Rsx_Time.sync_from_ajax({
                    server_time: response._server_time,
                    user_timezone: response._user_timezone
                });
            }

            // Process batch response
            // Response format: { C_0: {success, _ajax_return_value}, C_1: {...}, ..., _server_time, _user_timezone }
            for (const response_key in response) {
                if (!response_key.startsWith('C_')) {
                    continue;
                }

                const call_id = parseInt(response_key.substring(2), 10);
                const call_response = response[response_key];
                const pending_call = call_map[call_id];

                if (!pending_call) {
                    console.error('Received response for unknown call_id:', call_id);
                    continue;
                }

                // Handle console_debug messages if present
                if (call_response.console_debug && Array.isArray(call_response.console_debug)) {
                    call_response.console_debug.forEach((msg) => {
                        if (!Array.isArray(msg) || msg.length !== 2) {
                            throw new Error('Invalid console_debug message format - expected [channel, [arguments]]');
                        }
                        const [channel, args] = msg;
                        console.log(channel, ...args);
                    });
                }

                // Mark call as complete
                pending_call.is_complete = true;

                // Check if successful
                if (call_response._success === true) {
                    // @JS-AJAX-02-EXCEPTION - Batch system unwraps server responses with _ajax_return_value
                    const processed_value = Rsx_Js_Model._instantiate_models_recursive(call_response._ajax_return_value);
                    pending_call.result = processed_value;

                    // Resolve all callbacks
                    pending_call.callbacks.forEach(({ resolve }) => {
                        resolve(processed_value);
                    });
                } else {
                    // Handle error
                    // Server may use error_code or error_type
                    const error_code = call_response.error_code || call_response.error_type || Ajax.ERROR_GENERIC;
                    const error_message = call_response.reason || 'Unknown error occurred';
                    const metadata = call_response.metadata || {};

                    const error = new Error(error_message);
                    error.code = error_code;
                    error.metadata = metadata;

                    // Handle fatal errors specially - detect PHP exceptions
                    if (error_code === Ajax.ERROR_FATAL) {
                        const fatal_error_data = call_response.error || {};

                        // Check if this is a PHP exception (has file, line, error, backtrace)
                        if (fatal_error_data.file && fatal_error_data.line && fatal_error_data.error) {
                            error.code = Ajax.ERROR_PHP_EXCEPTION;
                            error.message = fatal_error_data.error;
                            error.metadata = {
                                file: fatal_error_data.file,
                                line: fatal_error_data.line,
                                error: fatal_error_data.error,
                                backtrace: fatal_error_data.backtrace || []
                            };
                            console.error('PHP Exception:', fatal_error_data.error, 'at', fatal_error_data.file + ':' + fatal_error_data.line);
                        } else {
                            error.message = fatal_error_data.error || 'Fatal error occurred';
                            error.metadata = call_response.error;
                            console.error('Ajax error response from server:', call_response.error);
                        }
                    }

                    pending_call.is_error = true;
                    pending_call.error = error;

                    // Reject all callbacks
                    pending_call.callbacks.forEach(({ reject }) => {
                        reject(error);
                    });
                }
            }
        } catch (xhr_error) {
            // Network or server error - reject all pending calls
            const error_message = Ajax._extract_error_message(xhr_error);
            const error = new Error(error_message);
            error.code = Ajax.ERROR_NETWORK;
            error.metadata = {};

            for (const call_id in call_map) {
                const pending_call = call_map[call_id];
                pending_call.is_complete = true;
                pending_call.is_error = true;
                pending_call.error = error;

                pending_call.callbacks.forEach(({ reject }) => {
                    reject(error);
                });
            }

            console.error('Batch Ajax request failed:', error_message);
        }

        // FORGET every call this flush handled. Ajax._pending_calls is an IN-FLIGHT dedup
        // map - an entry exists so a second identical call made before the response
        // arrives joins the first one's callbacks. Once the response has been distributed
        // the entry has no job left, and keeping it turned the map into a permanent memo:
        // the same call made a minute later hit the completed entry at the top of
        // _call_batch and resolved with the ORIGINAL result, forever. record.refresh() was
        // a no-op in batched (debug/production) mode for that reason, while development -
        // which uses the direct transport and no memo - refetched correctly. A repeated
        // call must be a fresh request in every mode.
        for (const call_id in call_map) {
            delete Ajax._pending_calls[call_map[call_id].call_key];
        }
    }

    /**
     * Generate a unique key for deduplicating calls
     * @private
     */
    static _generate_call_key(controller, action, params) {
        // Create a stable string representation of the call
        // Sort params keys for consistent hashing
        const sorted_params = {};
        Object.keys(params)
            .sort()
            .forEach((key) => {
                sorted_params[key] = params[key];
            });

        return `${controller}::${action}::${JSON.stringify(sorted_params)}`;
    }

    /**
     * Extract error message from jQuery XHR object
     * @private
     */
    static _extract_error_message(xhr) {
        if (xhr.responseJSON && xhr.responseJSON.message) {
            return xhr.responseJSON.message;
        } else if (xhr.responseText) {
            try {
                const response = JSON.parse(xhr.responseText);
                if (response.message) {
                    return response.message;
                }
            } catch (e) {
                // Not JSON
            }
        }

        return `${xhr.status}: ${xhr.statusText || 'Unknown error'}`;
    }

    /**
     * Parses an AJAX URL into controller and action
     * Supports both /_ajax/ and /_/ URL prefixes
     * @param {string|object|function} url - URL in format '/_ajax/Controller_Name/action_name' or '/_/Controller_Name/action_name', or an object/function with a .path property
     * @returns {Object} Object with {controller: string, action: string}
     * @throws {Error} If URL doesn't start with /_ajax or /_ or has invalid structure
     */
    static ajax_url_to_controller_action(url) {
        // If url is an object or function with a .path property, use that as the URL
        if (url && typeof url === 'object' && url.path) {
            url = url.path;
        } else if (url && typeof url === 'function' && url.path) {
            url = url.path;
        }

        // Validate url is a string
        if (typeof url !== 'string') {
            throw new Error(`URL must be a string or have a .path property, got: ${typeof url}`);
        }

        // Tolerate an ALREADY-rebased portal URL. The channel is applied at call time
        // (Rsx_Portal.internal_url in _call_direct), so a caller that rebased the path
        // itself must not end up double-prefixed or rejected here.
        const portal_prefix = Rsx_Portal.get_prefix();
        if (portal_prefix && url.startsWith(portal_prefix + '/_ajax')) {
            url = url.substring(portal_prefix.length);
        }

        if (!url.startsWith('/_ajax') && !url.startsWith('/_/')) {
            throw new Error(`URL must start with /_ajax or /_, got: ${url}`);
        }

        const parts = url.split('/').filter((part) => part !== '');

        if (parts.length < 2) {
            throw new Error(`Invalid AJAX URL structure: ${url}`);
        }

        if (parts.length > 3) {
            throw new Error(`AJAX URL has too many segments: ${url}`);
        }

        const controller = parts[1];
        const action = parts[2] || 'index';

        return { controller, action };
    }

    /**
     * Auto-initialize static properties when class is first loaded
     */
    static on_core_define() {
        Ajax._on_framework_core_init();
    }
}
