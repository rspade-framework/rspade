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
 *     reconnect) when the last watch stops. So an IDLE 'disconnected' means idle /
 *     lazy / realtime-disabled -- NOT unreachable, and it must never warn.
 *   - An UNINTENTIONAL drop passes through 'reconnecting' IMMEDIATELY (the app is
 *     told something is happening at once); a fresh attempt is 'connecting'.
 *   - 'disconnected' AFTER a trying state is the client's delayed offline
 *     announcement -- it is emitted only once a reconnect has failed to land inside
 *     Rsx_Realtime.OFFLINE_ANNOUNCE_GRACE_MS, so it means a CONFIRMED outage and the
 *     badge shows on it at once.
 *   - Therefore the warning arms a grace timer on 'connecting'/'reconnecting', shows
 *     the badge if 'connected' has not arrived by the time the timer fires or as soon
 *     as a confirmed offline announcement arrives, and hides on 'connected' or an
 *     IDLE 'disconnected'.
 *
 * GRACE_MS (5s) is a deliberate constant, not config: a connection that has been
 * trying for 5s continuously without succeeding is a real outage worth surfacing.
 *
 * Which 'disconnected' is which is read from the PREVIOUS state, not from a deferred
 * re-check: the client no longer flashes a transient 'disconnected' on the way into a
 * reconnect (that blip is exactly what the delayed announcement removed), so a
 * 'disconnected' preceded by 'connecting'/'reconnecting' is a real outage and one
 * preceded by anything else is the idle/terminal kind.
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
        const previous = this._last_state;
        this._last_state = state;

        if (state === 'connecting' || state === 'reconnecting') {
            this._arm_grace_timer();
        } else if (state === 'connected') {
            this._hide();
        } else if (state === 'disconnected') {
            this._handle_disconnected(previous);
        }
    }

    /**
     * 'disconnected' carries two meanings, told apart by what preceded it. Following a
     * trying state it is the client's CONFIRMED offline announcement (the grace window
     * expired with no reconnect) -- show at once, the outage is real. Otherwise it is the
     * idle / lazy / disabled kind -- hide and cancel any countdown.
     */
    _handle_disconnected(previous) {
        if (previous === 'connecting' || previous === 'reconnecting') {
            this._clear_grace_timer();
            this._show();
            return;
        }

        this._hide();
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
