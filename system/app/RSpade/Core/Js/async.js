/*
 * Async utility functions for the RSpade framework.
 * These functions handle asynchronous operations, delays, debouncing, and mutexes.
 */

// ============================================================================
// ASYNC UTILITIES
// ============================================================================

/**
 * Pauses execution for specified milliseconds
 * @param {number} [milliseconds=0] - Delay in milliseconds (0 uses requestAnimationFrame)
 * @returns {Promise<void>} Promise that resolves after delay
 * @example await sleep(1000); // Wait 1 second
 */
function sleep(milliseconds = 0) {
    return new Promise((resolve) => {
        if (milliseconds == 0 && requestAnimationFrame) {
            requestAnimationFrame(resolve);
        } else {
            setTimeout(resolve, milliseconds);
        }
    });
}

/**
 * Creates a debounced function with exclusivity and promise fan-in
 *
 * BEHAVIORAL GUARANTEES:
 *
 * 1. IMMEDIATE START: The first call (or any call when idle and delay has passed) executes
 *    immediately without waiting.
 *
 * 2. AWAITS COMPLETION: The callback is awaited - async operations (network requests, etc.)
 *    complete before the execution is considered finished.
 *
 * 3. POST-EXECUTION DELAY: After callback completion, no further executions occur for at least
 *    `delay` milliseconds. The delay timer starts AFTER the callback resolves/rejects.
 *
 * 4. COALESCED QUEUING: If called during execution OR during the post-execution delay, requests
 *    are coalesced into ONE pending execution using the LAST provided arguments. When the delay
 *    expires, exactly one execution occurs with those final arguments.
 *
 * 5. GUARANTEED EXECUTION: Any call made during an active execution is guaranteed to trigger
 *    a subsequent execution (with that call's args or later args if more calls arrive).
 *
 * 6. PROMISE FAN-IN: All callers waiting for the same execution receive the same result/error.
 *
 * If 'delay' is set to 0, the function still enforces exclusivity (no parallel execution) but
 * queued executions run immediately after the current one completes.
 *
 * Usage as function:
 *   const debouncedFn = debounce(myFunction, 250);
 *
 * Usage as decorator:
 *   @debounce(250)
 *   myMethod() { ... }
 *
 * @param {function|number} callback_or_delay The callback function OR delay when used as decorator
 * @param {number} delay The delay in milliseconds after completion before next execution
 * @param {boolean} immediate Unused - first call always runs immediately regardless of this value
 * @returns {function} A debounced function that returns a Promise resolving to the callback result
 *
 * @decorator
 */
function debounce(callback_or_delay, delay, immediate = false) {
    // Decorator usage: @debounce(250) or @debounce(250, true)
    // First argument is a number (the delay), returns decorator function
    if (typeof callback_or_delay === 'number') {
        const decorator_delay = callback_or_delay;
        const decorator_immediate = delay || false;

        // TC39 decorator form: receives (value, context)
        return function (value, context) {
            if (context.kind === 'method') {
                return debounce_impl(value, decorator_delay, decorator_immediate);
            }
        };
    }

    // Function usage: debounce(fn, 250)
    // First argument is a function (the callback)
    const callback = callback_or_delay;
    return debounce_impl(callback, delay, immediate);
}

/**
 * Internal implementation of debounce logic
 * @private
 */
function debounce_impl(callback, delay, immediate = false) {
    let running = false;
    let queued = false;
    let last_end_time = 0; // timestamp of last completed run
    let timer = null;

    let next_args = [];
    let next_context = null;
    let resolve_queue = [];
    let reject_queue = [];

    const run_function = async () => {
        const these_resolves = resolve_queue;
        const these_rejects = reject_queue;
        const args = next_args;
        const context = next_context;

        resolve_queue = [];
        reject_queue = [];
        next_args = [];
        next_context = null;
        queued = false;
        running = true;

        try {
            const result = await callback.apply(context, args);
            for (const resolve of these_resolves) resolve(result);
        } catch (err) {
            for (const reject of these_rejects) reject(err);
        } finally {
            running = false;
            last_end_time = Date.now();
            if (queued) {
                clearTimeout(timer);
                timer = setTimeout(run_function, Math.max(delay, 0));
            } else {
                timer = null;
            }
        }
    };

    return function (...args) {
        next_args = args;
        next_context = this;

        return new Promise((resolve, reject) => {
            resolve_queue.push(resolve);
            reject_queue.push(reject);

            // Nothing running and nothing scheduled
            if (!running && !timer) {
                const first_call = last_end_time === 0;

                if (immediate && first_call) {
                    run_function();
                    return;
                }

                const since = first_call ? Infinity : Date.now() - last_end_time;
                if (since >= delay) {
                    run_function();
                } else {
                    const wait = Math.max(delay - since, 0);
                    clearTimeout(timer);
                    timer = setTimeout(run_function, wait);
                }
                return;
            }

            // If we're already running or a timer exists, just mark queued.
            // The finally{} of run_function handles scheduling after full delay.
            queued = true;
        });
    };
}

// ============================================================================
// READ-WRITE LOCK FUNCTIONS - Delegated to ReadWriteLock class
// ============================================================================

/**
 * Acquire an exclusive write lock by name.
 * Only one writer runs at a time; blocks readers until finished.
 * @param {string} name
 * @param {() => any|Promise<any>} cb
 * @returns {Promise<any>}
 */
function rwlock(name, cb) {
    return ReadWriteLock.acquire(name, cb);
}

/**
 * Acquire a shared read lock by name.
 * Multiple readers run in parallel, but readers are blocked by queued/active writers.
 * @param {string} name
 * @param {() => any|Promise<any>} cb
 * @returns {Promise<any>}
 */
function rwlock_read(name, cb) {
    return ReadWriteLock.acquire_read(name, cb);
}

/**
 * Forcefully clear all locks and queues for a given name.
 * @param {string} name
 */
function rwlock_force_unlock(name) {
    ReadWriteLock.force_unlock(name);
}

/**
 * Inspect lock state for debugging.
 * @param {string} name
 * @returns {{readers:number, writer_active:boolean, reader_q:number, writer_q:number}}
 */
function rwlock_pending(name) {
    return ReadWriteLock.pending(name);
}
