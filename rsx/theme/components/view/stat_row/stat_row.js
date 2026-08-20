/**
 * Stat_Row
 *
 * One money / numeric stat line (label + monospaced right-aligned value).
 * Stamps the $strong_label (totals emphasis) and $alert (attention) modifiers.
 * See stat_row.jqhtml for the full contract.
 */
class Stat_Row extends Component {
    on_create() {
        if (this.args.strong_label) {
            this.$.addClass('Stat_Row--strong');
        }
        if (this.args.alert) {
            this.$.addClass('Stat_Row--alert');
        }
    }
}
