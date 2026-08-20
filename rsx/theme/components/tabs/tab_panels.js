/**
 * Tab_Panels
 *
 * Panel container. Collects self-registering Tab_Panel children and follows a
 * sibling Tab_Bar's "tab_change" event to show one panel at a time.
 * See tab_panels.jqhtml.
 */
class Tab_Panels extends Component {
    on_create() {
        this.panels = []; // registered Tab_Panel components
        this.tab_bar = null;
        this.active_key = null;
    }

    register_panel(panel) {
        // A self re-render recreates the panels; drop stopped instances so the
        // registry never accumulates dead components.
        this.panels = this.panels.filter((p) => !p._stopped);
        this.panels.push(panel);

        // Registration happens in the panel's on_create (render phase). If the
        // active tab is already known, apply this panel's visibility right here,
        // pre-paint — registration order vs. bar binding order then never matters.
        if (this.active_key !== null) {
            if (str(panel.key) === str(this.active_key)) {
                panel.show();
            } else {
                panel.hide();
            }
        }
    }

    on_render() {
        // Bind at RENDER time, not on_ready, so the active panel is revealed
        // synchronously in the render pass (before paint) instead of after every
        // child in the tab region finishes loading. Safe cross-component work for
        // on_render: the sibling Tab_Bar has no on_load, so its whole synchronous
        // create->render->on_render chain (including the initial activate) has
        // already completed by the time this later sibling renders, and its
        // tab_change event replays on bind. Bind once per instance (on_render
        // can re-fire; component events have no namespace dedupe).
        if (this.tab_bar) return;

        // Prefer a direct sibling; otherwise a Tab_Bar within the shared parent.
        let $bar = this.$.siblings('.Tab_Bar').first();
        if (!$bar.exists()) {
            $bar = this.$.parent().find('.Tab_Bar').first();
        }

        const bar = $bar.exists() ? $bar.component() : null;
        if (!bar) {
            shouldnt_happen('Tab_Panels found no sibling Tab_Bar to bind to');
            return;
        }

        this.tab_bar = bar;
        // Event replay: the bar's initial activate already fired, so this
        // callback runs immediately with the current key.
        bar.on('tab_change', (component, data) => this.show_panel(data.key));
    }

    show_panel(key) {
        this.active_key = key;
        for (const panel of this.panels) {
            if (str(panel.key) === str(key)) {
                panel.show();
            } else {
                panel.hide();
            }
        }
    }
}
