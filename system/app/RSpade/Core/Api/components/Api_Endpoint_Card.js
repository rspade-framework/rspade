/**
 * Api_Endpoint_Card - one endpoint: verb pills + pattern, description, params table,
 * collapsible example response, code samples, and inline tester.
 *
 * args: endpoint (object), anchor (string) - the scroll-target id used by the sidebar.
 */
class Api_Endpoint_Card extends Component {
    on_ready() {
        // <pre> is a raw-text element in jqhtml; set the example text here, then highlight.
        const $ex = this.$sid('response_example');
        if ($ex && $ex.exists()) {
            $ex[0].textContent = this.args.endpoint.response_example || '';
            if (typeof hljs !== 'undefined') {
                hljs.highlightElement($ex[0]);
            }
        }
    }
}
