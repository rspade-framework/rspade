/**
 * Callout
 *
 * Inline alert banner. Stamps Bootstrap's alert variant class (danger /
 * warning), which carries every colour the component shows; the template
 * validates the variant loudly. See callout.jqhtml for the contract.
 */
class Callout extends Component {
    on_create() {
        this.$.addClass(`alert-${this.args.variant}`);
    }
}
