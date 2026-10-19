/**
 * Realtime_Status_Badge - Header "connection lost" indicator for the realtime link.
 *
 * Shows only while Rsx_Realtime.watch_connection() reports 'disconnected' - the framework
 * says so only once the link has been down for its grace window (5s), so a blip never
 * flashes the badge. 'connected' and 'disabled' (realtime not configured) both hide it.
 * Purely a connection observer: it subscribes to no topic. Registering the monitor holds
 * the socket open while the badge exists; on a signed-in page the framework's session
 * anchor holds it anyway.
 *
 * The badge lives in the persistent SPA layout header, so it is created once. It still
 * stops its monitor in on_stop() for correctness.
 */
class Realtime_Status_Badge extends Component {
    // Fade duration mirrors $transition-duration-base (200ms) in realtime_status_badge.scss.
    static FADE_MS = 200;

    on_create() {
        this.state = { visible: false };
        this._hide_timer = null;
        this._rendered = false;

        this._monitor = Rsx_Realtime.watch_connection((state) => {
            if (state === 'disconnected') {
                this._show();
            } else {
                this._hide();
            }
        });
    }

    on_render() {
        this._rendered = true;
        // Re-apply the current steady visibility (no animation) so a layout re-render
        // never loses the shown/hidden state.
        this._apply_visibility(false);
    }

    on_stop() {
        this._monitor.stop();
        if (this._hide_timer) {
            clearTimeout(this._hide_timer);
            this._hide_timer = null;
        }
    }

    _show() {
        if (this.state.visible) return;
        this.state.visible = true;
        this._apply_visibility(true);
    }

    _hide() {
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
