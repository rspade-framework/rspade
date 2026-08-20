/**
 * Tab_Panel
 *
 * One panel bound to a tab key. Self-registers with its Tab_Panels parent and
 * starts hidden. See tab_panel.jqhtml.
 */
class Tab_Panel extends Component {
    on_create() {
        this.key = this.args.key;

        this.panels = this.closest('.Tab_Panels');
        if (this.panels) {
            this.panels.register_panel(this);
        } else {
            shouldnt_happen('Tab_Panel must be inside a Tab_Panels container');
        }

        // Hidden until the bound Tab_Bar activates this panel's key.
        this.$.hide();
    }

    show() {
        this.$.show();
    }

    hide() {
        this.$.hide();
    }
}
