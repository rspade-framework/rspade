/**
 * Kpi_Cell
 *
 * One compact KPI cell. Stamps $alert / $clickable modifiers and, when
 * clickable, exposes the target tab key so the owning action can delegate a
 * click to its Tab_Bar. See kpi_cell.jqhtml.
 */
class Kpi_Cell extends Component {
    on_create() {
        if (this.args.alert) {
            this.$.addClass('Kpi_Cell--alert');
        }
        if (this.args.tooltip) {
            this.$.attr('title', this.args.tooltip);
        }
        if (this.args.clickable) {
            this.$.addClass('Kpi_Cell--clickable');
            this.$.attr('role', 'button').attr('tabindex', '0');
            if (this.args.tab) {
                this.$.attr('data-kpi-tab', this.args.tab);
            }
        }
    }
}
