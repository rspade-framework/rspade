/**
 * Tab_Bar
 *
 * Neutral tab strip. Owns active state + optional URL-hash persistence and
 * fires "tab_change" for a sibling Tab_Panels to follow. See tab_bar.jqhtml.
 */
class Tab_Bar extends Component {
    on_create() {
        this.active_key = null;
        if (this.args.divided) {
            this.$.addClass('Tab_Bar--divided');
        }
    }

    on_render() {
        // Delegated click, idempotent (on_render can re-fire).
        this.$.off('click.tabbar').on('click.tabbar', '.Tab_Bar__tab', (e) => {
            this.activate($(e.currentTarget).attr('data-tab-key'));
        });

        // Activate at RENDER time, not on_ready: activation only touches this
        // component's own template (button classes) + fires the replayed
        // tab_change event, so it is safe here — and doing it synchronously in
        // the render pass means the selected panel is revealed before paint
        // instead of after every child in the tab region finishes loading.
        // Resolution order: URL hash -> the key already active on this instance
        // (a self re-render must not snap back to the first tab) -> first tab.
        let initial = null;

        if (this.args.hash) {
            const from_hash = Rsx.url_hash_get(this.args.hash);
            if (from_hash && this._has_key(from_hash)) {
                initial = from_hash;
            }
        }

        if (!initial && this.active_key !== null && this._has_key(this.active_key)) {
            initial = this.active_key;
        }

        if (!initial && this.args.tabs && this.args.tabs.length) {
            initial = this.args.tabs[0].key;
        }

        if (initial !== null) {
            this.activate(initial);
        }
    }

    _has_key(key) {
        return (this.args.tabs || []).some((t) => str(t.key) === str(key));
    }

    activate(key) {
        if (!this._has_key(key)) return;

        this.active_key = key;

        this.$.find('.Tab_Bar__tab').each((i, el) => {
            const $tab = $(el);
            const is_active = str($tab.attr('data-tab-key')) === str(key);
            $tab.toggleClass('active', is_active).attr('aria-selected', is_active ? 'true' : 'false');
        });

        if (this.args.hash) {
            Rsx.url_hash_set_single(this.args.hash, key);
        }

        this.trigger('tab_change', { key });
    }

    get_active_key() {
        return this.active_key;
    }
}
