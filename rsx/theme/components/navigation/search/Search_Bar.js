class Search_Bar extends Component {
    on_ready() {
        // Bind search event
        const $input = this.$sid('input');
        $input.on('input', (e) => {
            if (this.args.on_search) {
                this.args.on_search(e.target.value);
            }
        });
    }
}
