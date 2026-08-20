/**
 * Api_Code_Samples - client-generated code samples (cURL / PHP / JS / Python / PowerShell)
 * for one endpoint, with a tab strip and per-snippet copy button.
 *
 * Snippets are generated from the catalog spec by api_code_samples() (never authored). Tabs
 * toggle visibility via DOM class swaps (no re-render, so highlighting is not repeated).
 *
 * args: endpoint (object) - one resolved catalog endpoint.
 */
class Api_Code_Samples extends Component {
    on_create() {
        this.state = {
            active: 0,
            samples: api_code_samples(this.args.endpoint),
        };
    }

    on_ready() {
        const that = this;

        // <pre> is a raw-text element in jqhtml (no nested tags in markup); fill each pane's
        // code text here, then syntax-highlight.
        const samples = this.state.samples;
        this.$sid('panes').find('pre[data-idx]').each(function () {
            const idx = int($(this).attr('data-idx'));
            const hl = $(this).attr('data-hl');
            this.textContent = samples[idx].code;
            $(this).addClass('language-' + hl);
            if (typeof hljs !== 'undefined') {
                hljs.highlightElement(this);
            }
        });

        this.$sid('tabs').off('click.acs').on('click.acs', 'button[data-idx]', function () {
            that._activate(int($(this).attr('data-idx')));
        });

        this.$sid('panes').off('click.acs').on('click.acs', 'button.Api_Code_Samples__copy', function () {
            that._copy(int($(this).attr('data-idx')), $(this));
        });
    }

    _activate(idx) {
        this.state.active = idx;

        const $tabs = this.$sid('tabs');
        $tabs.find('button[data-idx]').removeClass('Api_Code_Samples__tab--active');
        $tabs.find('button[data-idx="' + idx + '"]').addClass('Api_Code_Samples__tab--active');

        const $panes = this.$sid('panes');
        $panes.find('.Api_Code_Samples__pane').addClass('Api_Code_Samples__pane--hidden');
        $panes.find('.Api_Code_Samples__pane[data-idx="' + idx + '"]').removeClass('Api_Code_Samples__pane--hidden');
    }

    async _copy(idx, $btn) {
        const code = this.state.samples[idx].code;
        try {
            await navigator.clipboard.writeText(code);
            const $span = $btn.find('span');
            const prev = $span.text();
            $span.text('Copied');
            setTimeout(() => $span.text(prev), 1200);
        } catch (e) {
            // Clipboard unavailable (insecure context / permissions) - silent, non-critical.
        }
    }
}
