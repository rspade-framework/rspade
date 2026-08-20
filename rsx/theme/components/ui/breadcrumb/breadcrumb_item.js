class Breadcrumb_Item extends Component {
    on_create() {
        // Read href from HTML attribute if present
        const href = this.$.attr('href');
        if (href) {
            this.args.href = href;
        }

        // Read active from HTML attribute if present
        const active = this.$.attr('active');
        if (active !== undefined) {
            this.args.active = true;
            this.$.addClass('active');
            this.$.attr('aria-current', 'page');
            this.$.removeAttr('active'); // Remove the attribute after reading
        }
    }
}
