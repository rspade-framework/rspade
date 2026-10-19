class Search_Bar extends Component {
    on_render() {
        // Bind search event. Own markup, so on_render(); namespaced and idempotent because
        // on_render() re-fires on every render.
        const $input = this.$sid('input');
        $input.off('input.search_bar').on('input.search_bar', (e) => {
            if (this.args.on_search) {
                this.args.on_search(e.target.value);
            }
        });
    }
}
