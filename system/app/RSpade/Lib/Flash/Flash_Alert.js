/**
 * Flash_Alert - Temporary alert notification system with queue persistence
 *
 * Displays dismissable alert messages that auto-fade after a timeout.
 * Messages are queued to prevent overwhelming the user with simultaneous alerts.
 * Queue state persists across page navigations using sessionStorage (per-tab).
 *
 * Usage:
 *   Flash_Alert.success('Changes saved!')
 *   Flash_Alert.error('Something went wrong')
 *   Flash_Alert.info('FYI: New feature available')
 *   Flash_Alert.warning('Are you sure about that?')
 *
 * Features:
 * - Queue system prevents alert spam (2.5s minimum between alerts)
 * - Auto-dismiss after timeout (success: 4s, others: 6s)
 * - Hover to pause: Hovering an alert pauses the fadeout timer, resumes on mouse leave
 * - Text selection: Alert text can be selected and copied
 * - Smooth fade in/out animations
 * - Bootstrap alert styling with icons
 * - Persistent queue across page navigations (sessionStorage, tab-specific)
 * - Stale message filtering (entire queue discarded if > 20 seconds old)
 * - SPA-like experience: messages maintain timing state across navigation
 *
 * Queue Architecture:
 * - Two-queue system: working queue (_queue) and persistence queue (_persistence_queue)
 * - Working queue: Messages removed when displayed (controls display timing/spacing)
 * - Persistence queue: Messages removed when fadeout starts (saved to sessionStorage)
 * - Prevents duplicate displays while maintaining cross-navigation state
 *
 * Persistence System:
 * - Queue state saved to sessionStorage when messages queued or state changes
 * - State restored on page load via Server_Side_Flash._on_framework_core_init()
 * - Entire queue discarded if last_updated timestamp > 20 seconds old
 * - Individual messages filtered: discard if past their fadeout_start_time
 * - Per-tab isolation (sessionStorage doesn't share across browser tabs)
 * - Handles Ajax response + redirect scenario (message survives navigation)
 *
 * Message Lifecycle & Timing:
 * - Queued → Display (fade-in 400ms) → Visible (4s success, 6s others) → Fadeout (1s) → Removed
 * - fade_in_complete flag: Set after fade-in animation completes
 * - fadeout_start_time: Calculated timestamp when fadeout should begin
 * - Both saved to sessionStorage to maintain timing consistency across navigation
 *
 * Navigation Behavior:
 * - beforeunload event: Hides alerts still fading in, keeps fully visible alerts (with scheduled fadeout)
 * - On restore: Messages with fade_in_complete=true show immediately (no fade-in, no queue delay)
 * - On restore: Messages with fade_in_complete=false added to queue (normal 2.5s spacing)
 * - Restored messages honor original fadeout_start_time (not restarted)
 * - Creates seamless SPA-like experience across full page navigations
 *
 * Common Scenarios:
 * 1. Ajax + Redirect: Flash message queued during Ajax, page redirects immediately
 *    → Message saved to sessionStorage → Restored and displayed on new page
 * 2. Mid-animation navigation: Alert fading in when user navigates away
 *    → Alert hidden during navigation → Restored on new page, continues fade-in
 * 3. Visible alert navigation: Alert fully visible when user navigates
 *    → Alert stays visible → Appears immediately on new page, honors original fadeout timing
 */
class Flash_Alert {
    // Queue and state tracking
    static _queue = []; // Working queue - messages removed when displayed
    static _persistence_queue = []; // Persistence queue - messages removed when fadeout starts
    static _is_in_progress = false;
    static _last_alert_time = 0;
    static _container = null;

    /**
     * Initialize the flash alert container
     * @private
     */
    static _init() {
        if (this._container) return;

        // Create floating alert container
        this._container = $('<div id="floating-alert-container" class="floating-alert-container"></div>');
        $('body').append(this._container);

        // Register page navigation handler (only once)
        window.addEventListener('beforeunload', () => {
            console.log('[Flash_Alert] Page navigation detected - cleaning up');
            this._cleanup_for_navigation();
        });
    }

    /**
     * Cleanup alerts on page navigation
     * Hides alerts still fading in, leaves fully faded-in alerts visible with scheduled fadeout
     * @private
     */
    static _cleanup_for_navigation() {
        console.log('[Flash_Alert] Cleaning up for navigation:', {
            working_queue_before: this._queue.length,
            persistence_queue: this._persistence_queue.length,
        });

        if (this._container) {
            const now = Date.now();

            // Process each visible alert
            this._container.find('.alert-wrapper').each((index, wrapper) => {
                const $wrapper = $(wrapper);
                const message_text = $wrapper.find('.alert').text().trim();

                // Find this message in persistence queue to check fade_in_complete
                const msg = this._persistence_queue.find(m => m.message === message_text);

                if (msg && msg.fade_in_complete) {  // @JS-DEFENSIVE-01-EXCEPTION - Array.find() returns undefined when no match is found
                    // Alert has fully faded in - leave it visible
                    console.log('[Flash_Alert] Leaving fully faded-in alert visible:', message_text);

                    // Schedule fadeout based on fadeout_start_time
                    if (msg.fadeout_start_time) {
                        const time_until_fadeout = Math.max(0, msg.fadeout_start_time - now);

                        setTimeout(() => {
                            console.log('[Flash_Alert] Navigation fadeout starting for:', message_text);
                            // Fade to transparent, then slide up
                            $wrapper.animate({ opacity: 0 }, 1000, () => {
                                $wrapper.slideUp(250, () => {
                                    $wrapper.remove();
                                });
                            });
                        }, time_until_fadeout);

                        console.log('[Flash_Alert] Scheduled fadeout for visible alert:', {
                            message: message_text,
                            time_until_fadeout,
                            fadeout_start_time: msg.fadeout_start_time
                        });
                    }
                } else {
                    // Alert still fading in - remove it
                    console.log('[Flash_Alert] Removing alert still fading in:', message_text);
                    $wrapper.remove();
                }
            });
        }

        // Clear working queue (stop processing new alerts)
        this._queue = [];

        // Stop processing
        this._is_in_progress = false;

        // DO NOT touch _persistence_queue or sessionStorage
        // They will be restored on the next page load

        console.log('[Flash_Alert] Cleanup complete - persistence queue preserved');
    }

    /**
     * Save persistence queue state to sessionStorage
     * @private
     */
    static _save_queue_state() {
        const state = {
            last_updated: Date.now(),
            messages: this._persistence_queue.map((item) => ({
                message: item.message,
                level: item.level,
                timeout: item.timeout,
                position: item.position,
                queued_at: item.queued_at,
                fade_in_complete: item.fade_in_complete || false,
                fadeout_start_time: item.fadeout_start_time || null,
            })),
        };
        console.log('[Flash_Alert] Saving persistence queue to sessionStorage:', {
            message_count: state.messages.length,
            last_updated: state.last_updated,
            messages: state.messages,
        });
        Rsx_Storage.session_set('rsx_flash_queue', state);
    }

    /**
     * Load queue state from sessionStorage
     * Discards entire queue if last_updated timestamp is > 20 seconds old
     * @private
     */
    static _restore_queue_state() {
        const state = Rsx_Storage.session_get('rsx_flash_queue');
        console.log('[Flash_Alert] Attempting to restore queue state from sessionStorage:', {
            has_stored_data: !!state,
            stored_data: state,
        });

        if (!state) {
            console.log('[Flash_Alert] No stored queue data found');
            return;
        }

        try {
            const now = Date.now();
            const MAX_AGE = 20000; // 20 seconds in milliseconds

            console.log('[Flash_Alert] Parsed stored state:', {
                message_count: state.messages.length,
                last_updated: state.last_updated,
                messages: state.messages,
                current_time: now,
            });

            // Check if the stored queue is > 20 seconds old
            // If so, throw out the entire queue
            const queue_age = now - state.last_updated;
            if (queue_age > MAX_AGE) {
                console.log('[Flash_Alert] Stored queue is too old, discarding entire queue:', {
                    queue_age_ms: queue_age,
                    max_age_ms: MAX_AGE,
                    last_updated: state.last_updated,
                });
                Rsx_Storage.session_remove('rsx_flash_queue');
                return;
            }

            // Filter messages: remove those past their fadeout time
            const valid_messages = state.messages.filter((msg) => {
                // If fadeout was scheduled and already passed, discard message
                if (msg.fadeout_start_time && now >= msg.fadeout_start_time) {
                    console.log('[Flash_Alert] Discarding message past fadeout time:', {
                        message: msg.message,
                        fadeout_start_time: msg.fadeout_start_time,
                        current_time: now,
                        time_past_fadeout: now - msg.fadeout_start_time,
                    });
                    return false;
                }
                return true;
            });

            console.log('[Flash_Alert] Valid messages to restore:', {
                count: valid_messages.length,
                messages: valid_messages,
            });

            // Separate messages into two groups: fade-in complete vs not
            const messages_to_show_immediately = [];
            const messages_to_queue = [];

            valid_messages.forEach((msg) => {
                const message_data = {
                    message: msg.message,
                    level: msg.level,
                    timeout: msg.timeout,
                    position: msg.position,
                    queued_at: msg.queued_at,
                    fade_in_complete: msg.fade_in_complete || false,
                    fadeout_start_time: msg.fadeout_start_time || null,
                };

                if (msg.fade_in_complete) {
                    // Already completed fade-in - show immediately
                    messages_to_show_immediately.push(message_data);
                } else {
                    // Still needs fade-in - add to queue
                    messages_to_queue.push(message_data);
                }

                // Add all to persistence queue
                this._persistence_queue.push(message_data);
            });

            console.log('[Flash_Alert] Messages after restoration:', {
                to_show_immediately: messages_to_show_immediately.length,
                to_queue: messages_to_queue.length,
                persistence_queue_length: this._persistence_queue.length,
            });

            // Re-save persistence queue to sessionStorage
            this._save_queue_state();

            // Ensure container is initialized before displaying
            this._init();

            // Display fade-in complete messages immediately (no delay, no fade-in)
            // These are displayed outside the normal queue, so don't affect _is_in_progress
            if (messages_to_show_immediately.length > 0) {
                console.log('[Flash_Alert] Displaying fade-in complete messages immediately');
                messages_to_show_immediately.forEach((msg) => {
                    this._display_alert(msg, true); // true = immediate display, don't block queue
                });
            }

            // Add remaining messages to working queue for normal processing
            messages_to_queue.forEach((msg) => {
                this._queue.push(msg);
            });

            // Start queue processing for messages that still need fade-in
            if (this._queue.length > 0) {
                console.log('[Flash_Alert] Starting queue processing for messages needing fade-in');
                if (!this._is_in_progress) {
                    this._process_queue();
                }
            }
        } catch (e) {
            console.error('[Flash_Alert] Failed to restore flash queue state:', e);
            sessionStorage.removeItem('rsx_flash_queue');
        }
    }

    /**
     * Remove a message from persistence queue when fadeout starts
     * @private
     */
    static _remove_from_persistence_queue(message, level) {
        const original_count = this._persistence_queue.length;
        this._persistence_queue = this._persistence_queue.filter((msg) => {
            return !(msg.level === level && msg.message === message);
        });

        console.log('[Flash_Alert] Removing message from persistence queue:', {
            message: message,
            level: level,
            original_count: original_count,
            remaining_count: this._persistence_queue.length,
        });

        // Save updated persistence queue to sessionStorage
        this._save_queue_state();
    }

    /**
     * Mark fade-in complete for a message in persistence queue
     * @private
     */
    static _mark_fade_in_complete(message, level) {
        const msg = this._persistence_queue.find((m) => m.level === level && m.message === message);
        if (msg) {
            msg.fade_in_complete = true;
            console.log('[Flash_Alert] Marked fade-in complete:', { message, level });
            this._save_queue_state();
        }
    }

    /**
     * Set fadeout start time for a message in persistence queue
     * @private
     */
    static _set_fadeout_start_time(message, level, fadeout_start_time) {
        const msg = this._persistence_queue.find((m) => m.level === level && m.message === message);
        if (msg) {
            msg.fadeout_start_time = fadeout_start_time;
            console.log('[Flash_Alert] Set fadeout start time:', { message, level, fadeout_start_time });
            this._save_queue_state();
        }
    }

    /**
     * Show a success alert
     * @param {string} message - The message to display
     * @param {number|null} timeout - Auto-dismiss timeout in ms (null = default 4000ms)
     * @param {string} position - Position: 'top' or 'bottom' (default: 'top')
     */
    static success(message, timeout = null, position = 'top') {
        this._show(message, 'success', timeout, position);
    }

    /**
     * Show an error alert
     * @param {string} message - The message to display
     * @param {number|null} timeout - Auto-dismiss timeout in ms (null = default 6000ms)
     * @param {string} position - Position: 'top' or 'bottom' (default: 'top')
     */
    static error(message, timeout = null, position = 'top') {
        this._show(message, 'danger', timeout, position);
    }

    /**
     * Show an info alert
     * @param {string} message - The message to display
     * @param {number|null} timeout - Auto-dismiss timeout in ms (null = default 6000ms)
     * @param {string} position - Position: 'top' or 'bottom' (default: 'top')
     */
    static info(message, timeout = null, position = 'top') {
        this._show(message, 'info', timeout, position);
    }

    /**
     * Show a warning alert
     * @param {string} message - The message to display
     * @param {number|null} timeout - Auto-dismiss timeout in ms (null = default 6000ms)
     * @param {string} position - Position: 'top' or 'bottom' (default: 'top')
     */
    static warning(message, timeout = null, position = 'top') {
        this._show(message, 'warning', timeout, position);
    }

    /**
     * Show an alert with custom level
     * @param {string} message - The message to display
     * @param {string} level - Alert level: 'success', 'danger', 'info', 'warning'
     * @param {number|null} timeout - Auto-dismiss timeout in ms (null = use default)
     * @param {string} position - Position: 'top' or 'bottom' (default: 'top')
     * @private
     */
    static _show(message, level = 'info', timeout = null, position = 'top') {
        this._init();

        // Add to BOTH queues with timestamp
        const message_data = {
            message,
            level,
            timeout,
            position,
            queued_at: Date.now(),
        };

        this._queue.push(message_data);
        this._persistence_queue.push(message_data);

        // Save persistence queue to sessionStorage
        this._save_queue_state();

        // Process queue if not already processing
        if (!this._is_in_progress) {
            this._process_queue();
        }
    }

    /**
     * Process the next alert in the queue
     * @private
     */
    static _process_queue() {
        const now = Date.now();

        console.log('[Flash_Alert] Processing queue:', {
            working_queue_length: this._queue.length,
            persistence_queue_length: this._persistence_queue.length,
            is_in_progress: this._is_in_progress,
            time_since_last: now - this._last_alert_time,
        });

        // Display next alert if enough time has passed (2.5s minimum between alerts)
        if (now - this._last_alert_time >= 2500 && this._queue.length > 0) {
            // Remove from working queue (shift) but stays in persistence queue until fadeout
            const alert_data = this._queue.shift();
            console.log('[Flash_Alert] Displaying alert from queue:', alert_data);
            console.log('[Flash_Alert] Working queue after shift:', this._queue.length);
            this._display_alert(alert_data);
            this._last_alert_time = now;
        }

        // Schedule next queue check if more alerts pending
        if (this._queue.length > 0) {
            console.log('[Flash_Alert] Scheduling next queue check in 2.5s');
            setTimeout(() => this._process_queue(), 2500);
        } else {
            console.log('[Flash_Alert] Working queue empty, stopping processing');
            this._is_in_progress = false;
        }
    }

    /**
     * Display a single alert
     * @param {Object} alert_data - Alert configuration
     * @param {boolean} is_immediate - If true, don't set _is_in_progress (for restored alerts displayed outside queue)
     * @private
     */
    static _display_alert({ message, level, timeout, position = 'top', fade_in_complete = false, fadeout_start_time = null }, is_immediate = false) {
        // Only set in_progress if this is a queued display (not an immediate restored alert)
        if (!is_immediate) {
            this._is_in_progress = true;
        }

        console.log('[Flash_Alert] Displaying alert:', {
            message,
            level,
            fade_in_complete,
            fadeout_start_time,
            current_time: Date.now(),
        });

        // Check if an alert with the same message is already displayed
        let duplicate_found = false;
        this._container.find('.alert-wrapper').each(function () {
            const existing_message = $(this).find('.alert').text().trim();
            if (existing_message === message) {
                duplicate_found = true;
                return false; // Break loop
            }
        });

        // Skip displaying if duplicate found
        if (duplicate_found) {
            console.log('[Flash_Alert] Duplicate found, skipping display');
            return;
        }

        // Create alert element (no close button - auto-fades, click anywhere to dismiss)
        const $alert = $(`<div class="alert alert-${level} fade show" role="alert">`);

        // Add icon based on level
        if (level === 'danger') {
            $alert.append('<i class="bi bi-exclamation-circle-fill me-2"></i>');
        } else if (level === 'success') {
            $alert.append('<i class="bi bi-check-circle-fill me-2"></i>');
        } else if (level === 'info') {
            $alert.append('<i class="bi bi-info-circle-fill me-2"></i>');
        } else if (level === 'warning') {
            $alert.append('<i class="bi bi-exclamation-triangle-fill me-2"></i>');
        }

        $alert.append(message);

        // Wrap in container for animation
        const $alert_container = $('<div class="alert-wrapper">').append($alert);

        // Add to floating container based on position
        if (position === 'top') {
            // Top position - append (newer alerts below older ones)
            this._container.css('justify-content', 'flex-start').append($alert_container);
        } else {
            // Bottom position - prepend (newer alerts above older ones)
            this._container.css('justify-content', 'flex-end').prepend($alert_container);
        }

        // Fade in (skip if already completed fade-in on previous page)
        if (fade_in_complete) {
            console.log('[Flash_Alert] Skipping fade-in, showing immediately (restored from previous page)');
            $alert_container.show();
        } else {
            $alert_container.hide().fadeIn(400, () => {
                console.log('[Flash_Alert] Fade-in complete');
                // Mark fade-in complete in persistence queue
                this._mark_fade_in_complete(message, level);
            });
        }

        // Close function - fade to transparent, then slide up
        const close_alert = (speed) => {
            $alert_container.animate({ opacity: 0 }, speed, () => {
                $alert_container.slideUp(250, () => {
                    $alert_container.remove();
                });
            });
        };

        // Calculate when fadeout should start
        const now = Date.now();
        let time_until_fadeout;

        if (fadeout_start_time) {
            // Honor existing fadeout schedule from restored state
            time_until_fadeout = Math.max(0, fadeout_start_time - now);
            console.log('[Flash_Alert] Using restored fadeout schedule:', {
                fadeout_start_time,
                now,
                time_until_fadeout,
            });
        } else {
            // New message - calculate fadeout time (4s for success, 6s for others)
            const display_duration = level === 'success' ? 4000 : 6000;
            time_until_fadeout = display_duration;
            const new_fadeout_start_time = now + display_duration;

            console.log('[Flash_Alert] Scheduling new fadeout:', {
                display_duration,
                fadeout_start_time: new_fadeout_start_time,
            });

            // Store fadeout start time in persistence queue
            this._set_fadeout_start_time(message, level, new_fadeout_start_time);
        }

        // Track fadeout state on the wrapper element for hover pause/resume
        $alert_container.data('fadeout_remaining', time_until_fadeout);
        $alert_container.data('fadeout_paused', false);

        // Schedule fadeout function (reusable for resume)
        const schedule_fadeout = (delay) => {
            const timeout_id = setTimeout(() => {
                // Don't fade if paused (safety check)
                if ($alert_container.data('fadeout_paused')) return;

                console.log('[Flash_Alert] Fadeout starting for:', message);
                // Remove from persistence queue when fadeout starts
                this._remove_from_persistence_queue(message, level);
                close_alert(1000);
            }, delay);
            $alert_container.data('fadeout_timeout_id', timeout_id);
            $alert_container.data('fadeout_scheduled_at', Date.now());
        };

        // Pause fadeout on hover
        $alert_container.on('mouseenter', () => {
            if ($alert_container.data('fadeout_paused')) return;

            const timeout_id = $alert_container.data('fadeout_timeout_id');
            const scheduled_at = $alert_container.data('fadeout_scheduled_at');
            const remaining = $alert_container.data('fadeout_remaining');

            if (timeout_id) {
                clearTimeout(timeout_id);
                // Calculate how much time was remaining when paused
                const elapsed = Date.now() - scheduled_at;
                const new_remaining = Math.max(0, remaining - elapsed);
                $alert_container.data('fadeout_remaining', new_remaining);
                $alert_container.data('fadeout_paused', true);
                console.log('[Flash_Alert] Paused fadeout on hover:', { message, remaining: new_remaining });
            }
        });

        // Resume fadeout on mouse leave
        $alert_container.on('mouseleave', () => {
            if (!$alert_container.data('fadeout_paused')) return;

            const remaining = $alert_container.data('fadeout_remaining');
            $alert_container.data('fadeout_paused', false);
            console.log('[Flash_Alert] Resuming fadeout:', { message, remaining });
            schedule_fadeout(remaining);
        });

        // Schedule initial fadeout
        if (time_until_fadeout >= 0) {
            schedule_fadeout(time_until_fadeout);
        }
    }
}
