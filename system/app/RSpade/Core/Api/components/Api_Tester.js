/**
 * Api_Tester - inline "Try it" panel that fires a real request at the live endpoint.
 *
 * Path-token params render as individual typed inputs (they build the URL); GET query params
 * render as typed inputs; POST endpoints get a raw JSON body textarea (prefilled from param
 * examples). The Send button reads the active key from Rsx_Storage (written by Api_Key_Bar),
 * then uses native fetch() with an Authorization: Bearer header - $.ajax is blocked; fetch is
 * not. The response pane shows a colored status badge, elapsed time, and pretty-printed JSON
 * (highlight.js). No key set -> an inline prompt to set one via the key bar.
 *
 * args: endpoint (object).
 */
class Api_Tester extends Component {
    static STORAGE_KEY = 'apidocs_tester_key';

    on_create() {
        this._key_listener = () => this._refresh_key_hint();
    }

    on_ready() {
        const that = this;

        // Prefill the POST body textarea from the param examples (can't interpolate into a
        // <textarea> in markup - set the value here).
        const ep = this.args.endpoint;
        const verb = (ep.methods && ep.methods[0]) || 'GET';
        const $body = this.$sid('body');
        if (verb === 'POST' && $body && $body.exists()) {
            const other = (ep.api_params || []).filter(p => ep.pattern.indexOf(':' + p.name) === -1);
            const obj = {};
            for (const p of other) {
                obj[p.name] = (p.example !== null && p.example !== undefined)
                    ? p.example
                    : (p.type === 'int' ? 0 : p.type === 'float' ? 0.0 : p.type === 'bool' ? false : '');
            }
            $body.val(JSON.stringify(obj, null, 2));
        }

        this.$sid('send').off('click.apit').click_async(async function () {
            await that._send();
        });

        document.addEventListener('apidocs:key_changed', this._key_listener);
        this._refresh_key_hint();
    }

    on_stop() {
        document.removeEventListener('apidocs:key_changed', this._key_listener);
    }

    _refresh_key_hint() {
        const $hint = this.$sid('key_hint');
        if (!$hint || !$hint.exists()) {
            return;
        }
        const key = Rsx_Storage.session_get(Api_Tester.STORAGE_KEY);
        if (key) {
            $hint.removeClass('Api_Tester__key-hint--warn').text('Using the key from the bar above.');
        } else {
            $hint.addClass('Api_Tester__key-hint--warn').text('No API key set - set or mint one in the bar above.');
        }
    }

    async _send() {
        const ep = this.args.endpoint;
        const verb = (ep.methods && ep.methods[0]) || 'GET';

        const key = Rsx_Storage.session_get(Api_Tester.STORAGE_KEY);
        if (!key) {
            this._refresh_key_hint();
            this._render_message('No API key set. Use the key bar above to paste or mint a key.', 'warn');
            return;
        }

        // Collect input values, classifying path vs query by the pattern.
        const $form = this.$sid('form');
        const path_vals = {};
        const query_vals = {};
        $form.find('[data-param]').each(function () {
            const name = $(this).attr('data-param');
            const val = $(this).val();
            if (ep.pattern.indexOf(':' + name) !== -1) {
                path_vals[name] = val;
            } else {
                query_vals[name] = val;
            }
        });

        // Build the path, substituting path params.
        let path = ep.pattern;
        for (const name of Object.keys(path_vals)) {
            const v = path_vals[name];
            if (v === '' || v === null || v === undefined) {
                this._render_message('Path parameter "' + name + '" is required.', 'warn');
                return;
            }
            path = path.replace(':' + name, encodeURIComponent(v));
        }

        let url = window.location.origin + path;

        // GET: append non-empty query params.
        if (verb === 'GET') {
            const parts = [];
            for (const name of Object.keys(query_vals)) {
                const v = query_vals[name];
                if (v !== '' && v !== null && v !== undefined) {
                    parts.push(encodeURIComponent(name) + '=' + encodeURIComponent(v));
                }
            }
            if (parts.length) {
                url += '?' + parts.join('&');
            }
        }

        // POST: validate the JSON body.
        const headers = { 'Authorization': 'Bearer ' + key };
        let body;
        if (verb === 'POST') {
            const raw = (this.$sid('body').val() || '').trim();
            if (raw !== '') {
                try {
                    JSON.parse(raw);
                } catch (e) {
                    this._render_message('Request body is not valid JSON: ' + e.message, 'error');
                    return;
                }
                headers['Content-Type'] = 'application/json';
                body = raw;
            }
        }

        const started = performance.now();
        let res, text;
        try {
            res = await fetch(url, { method: verb, headers, body });
            text = await res.text();
        } catch (e) {
            this._render_message('Network error: ' + (e.message ? e.message : str(e)), 'error');
            return;
        }
        const ms = Math.round(performance.now() - started);

        let pretty = text;
        try {
            pretty = JSON.stringify(JSON.parse(text), null, 2);
        } catch (e) {
            // Non-JSON body: show as-is.
        }

        this._render_response(res.status, res.statusText, ms, pretty);
    }

    _render_message(message, level) {
        const cls = 'Api_Tester__response-note Api_Tester__response-note--' + (level || 'warn');
        this.$sid('response').html('<div class="' + cls + '">' + html(message) + '</div>');
    }

    _render_response(status, status_text, ms, pretty) {
        const bucket = Math.floor(status / 100);
        const badge_cls = 'Api_Tester__status--' + bucket + 'xx';

        const parts = [];
        parts.push('<div class="Api_Tester__response-head">');
        parts.push('<span class="Api_Tester__status ' + badge_cls + '">' + status + ' ' + html(status_text || '') + '</span>');
        parts.push('<span class="Api_Tester__timing">' + ms + ' ms</span>');
        parts.push('</div>');
        parts.push('<pre class="Api_Tester__response-pre"><code class="language-json"></code></pre>');

        const $resp = this.$sid('response');
        $resp.html(parts.join(''));

        const code_el = $resp.find('code')[0];
        code_el.textContent = pretty;
        if (typeof hljs !== 'undefined') {
            hljs.highlightElement(code_el);
        }
    }
}
