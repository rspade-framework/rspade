/**
 * Realtime_Status_Badge - Header "connection lost" indicator for the realtime link.
 *
 * Observes Rsx_Realtime.on_state_change() and warns ONLY when the realtime backend
 * is genuinely unreachable. Displays nothing at all in the normal case. Purely a
 * connection-state observer: it does NOT subscribe to any topic.
 *
 * STATE SEMANTICS (must match Rsx_Realtime's connection model exactly):
 *   - The client connects LAZILY: state stays 'disconnected' until the first
 *     watch()/subscribe(), and returns to 'disconnected' (intentionally, no
 *     reconnect) when the last watch stops. So 'disconnected' means idle / lazy /
 *     realtime-disabled -- NOT unreachable. It must NEVER trigger a warning.
 *   - An UNINTENTIONAL drop always passes through 'reconnecting'
 *     (onclose -> _schedule_reconnect); a fresh attempt is 'connecting'.
 *   - Therefore the warning arms a grace timer ONLY on 'connecting'/'reconnecting',
 *     shows the badge if 'connected' has not arrived by the time the timer fires,
 *     and hides on 'connected' or a TERMINAL 'disconnected'.
 *
 * GRACE_MS (5s) is a deliberate constant, not config: a connection that has been
 * trying for 5s continuously without succeeding is a real outage worth surfacing.
 *
 * Transient-vs-terminal 'disconnected': during a reconnect loop the socket briefly
 * reports 'disconnected' and then SYNCHRONOUSLY 'reconnecting' in the same onclose
 * frame. Because on_state_change delivers those two transitions synchronously, by
 * the time a deferred (setTimeout 0) check runs, _last_state has already advanced to
 * 'reconnecting' for a transient drop, and stays 'disconnected' for a terminal one.
 * That is how we hide on a real idle disconnect without killing the grace timer (and
 * flickering the badge) mid-reconnect.
 *
 * The badge lives in the persistent SPA layout header, so it is created once. It
 * still unsubscribes and clears its timer in on_stop() for correctness.
 */
class Realtime_Status_Badge extends Component {
    // Continuous not-connected time before we surface the warning. Fade duration
    // mirrors $transition-duration-base (200ms) in realtime_status_badge.scss.
    static GRACE_MS = 5000;
    static FADE_MS = 200;

    on_create() {
        this.state = { visible: false };
        this._grace_timer = null;
        this._hide_timer = null;
        this._rendered = false;
        this._last_state = null;

        // Fires immediately with the current state, then on every transition. Store
        // the unsubscribe fn for on_stop.
        this._state_unsub = Rsx_Realtime.on_state_change((state) => this._on_state_change(state));
    }

    on_render() {
        this._rendered = true;
        // Re-apply the current steady visibility (no animation) so a layout re-render
        // never loses the shown/hidden state.
        this._apply_visibility(false);
    }

    on_stop() {
        if (this._state_unsub) {
            this._state_unsub();
            this._state_unsub = null;
        }
        this._clear_grace_timer();
        if (this._hide_timer) {
            clearTimeout(this._hide_timer);
            this._hide_timer = null;
        }
    }

    _on_state_change(state) {
        this._last_state = state;

        if (state === 'connecting' || state === 'reconnecting') {
            this._arm_grace_timer();
        } else if (state === 'connected') {
            this._hide();
        } else if (state === 'disconnected') {
            this._handle_disconnected();
        }
    }

    /**
     * 'disconnected' is ambiguous. A TERMINAL disconnect (idle / lazy / disabled)
     * must hide + cancel; a TRANSIENT one (part of a reconnect) must leave the grace
     * timer running. A reconnect emits 'reconnecting' synchronously right after this
     * 'disconnected', so a deferred check reads _last_state === 'reconnecting' for a
     * transient drop and 'disconnected' for a terminal one.
     */
    _handle_disconnected() {
        setTimeout(() => {
            if (this._last_state === 'disconnected') {
                this._hide();
            }
        }, 0);
    }

    _arm_grace_timer() {
        // Already showing, or already counting down -> nothing to do. Never reset a
        // running timer, so the grace period measures continuous outage time across
        // the whole reconnect backoff loop rather than restarting each attempt.
        if (this.state.visible) return;
        if (this._grace_timer) return;

        this._grace_timer = setTimeout(() => {
            this._grace_timer = null;
            this._show();
        }, Realtime_Status_Badge.GRACE_MS);
    }

    _clear_grace_timer() {
        if (this._grace_timer) {
            clearTimeout(this._grace_timer);
            this._grace_timer = null;
        }
    }

    _show() {
        if (this.state.visible) return;
        this.state.visible = true;
        this._apply_visibility(true);
    }

    _hide() {
        this._clear_grace_timer();
        if (!this.state.visible) return;
        this.state.visible = false;
        this._apply_visibility(true);
    }

    /**
     * Reflect this.state.visible onto the root element. `--shown` controls presence
     * (display), `--visible` controls the opacity fade. When animating a show we add
     * presence, force a reflow, then add the fade class so the transition runs; when
     * animating a hide we drop the fade class, then remove presence after the fade.
     */
    _apply_visibility(animate) {
        if (!this._rendered || !this.$ || !this.$.exists()) return;

        const $el = this.$;

        if (this._hide_timer) {
            clearTimeout(this._hide_timer);
            this._hide_timer = null;
        }

        if (this.state.visible) {
            $el.addClass('Realtime_Status_Badge--shown');
            if (animate) {
                // Force reflow so the opacity transition has a 0 -> 1 delta to animate.
                void $el[0].offsetWidth;
            }
            $el.addClass('Realtime_Status_Badge--visible');
        } else {
            $el.removeClass('Realtime_Status_Badge--visible');
            if (animate) {
                this._hide_timer = setTimeout(() => {
                    this._hide_timer = null;
                    if (!this.state.visible) {
                        $el.removeClass('Realtime_Status_Badge--shown');
                    }
                }, Realtime_Status_Badge.FADE_MS);
            } else {
                $el.removeClass('Realtime_Status_Badge--shown');
            }
        }
    }
}
