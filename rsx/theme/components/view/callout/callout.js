/**
 * Callout
 *
 * Inline alert banner. Stamps the $variant modifier (danger / warning); the
 * template validates the variant loudly. See callout.jqhtml for the contract.
 */
class Callout extends Component {
    on_create() {
        this.$.addClass(`Callout--${this.args.variant}`);
    }
}
