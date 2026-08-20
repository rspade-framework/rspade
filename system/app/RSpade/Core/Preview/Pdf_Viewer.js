/**
 * Pdf_Viewer - see Pdf_Viewer.jqhtml for the contract.
 *
 * pdf.js needs the live canvas, so document loading + first render happen in on_ready (not
 * on_load - the rendition url is already in this.args, there is nothing to fetch into this.data).
 *
 * pdf.js is lazy-loaded (never bundled) and memoized on the class so the ~450KB module + worker
 * load exactly once per page regardless of how many Pdf_Viewer instances render.
 */
class Pdf_Viewer extends Component {
    // Shared, class-wide memoized pdf.js module promise (see _load_pdfjs).
    static _pdfjs_promise = null;

    /**
     * Lazy-load the pdf.js module from the framework streaming route
     * (File_Preview_Controller::pdfjs / ::pdf_worker), memoized class-wide. The client appends
     * ?v=build_key so a new deployment busts the long-cache; GlobalWorkerOptions.workerSrc points
     * at the worker route (same versioned url).
     *
     * The dynamic import is routed through `new Function('u','return import(u)')` deliberately: the
     * bundle is esbuild --format=iife, and a bare `import(url)` in that output is rewritten into a
     * `require(...)`-based shim (require is undefined in the browser -> "require is not defined").
     * Hiding the import() token inside a Function body keeps the bundler's hands off it so the
     * browser performs a genuine native module import against the streaming route.
     */
    static _load_pdfjs() {
        if (Pdf_Viewer._pdfjs_promise) {
            return Pdf_Viewer._pdfjs_promise;
        }

        const version = (window.rsxapp && window.rsxapp.build_key) ? window.rsxapp.build_key : '';
        const module_url = Rsx.Route('File_Preview_Controller::pdfjs') + '?v=' + urlencode(version);
        const worker_url = Rsx.Route('File_Preview_Controller::pdf_worker') + '?v=' + urlencode(version);

        const dynamic_import = new Function('u', 'return import(u);');

        Pdf_Viewer._pdfjs_promise = dynamic_import(module_url).then((pdfjs) => {
            pdfjs.GlobalWorkerOptions.workerSrc = worker_url;
            return pdfjs;
        });

        return Pdf_Viewer._pdfjs_promise;
    }

    on_create() {
        this.state = {
            page: this.args.page ? int(this.args.page) : 1,
            pages: 0,
            pdf: null,
            loading_task: null,
        };
        this._stopped = false;
        this._last_dims = { width: 0, height: 0, ratio: 0 };
    }

    async on_ready() {
        const that = this;
        try {
            const pdfjs = await Pdf_Viewer._load_pdfjs();
            if (this._stopped) return;

            const loading_task = pdfjs.getDocument({ url: this.args.url });
            this.state.loading_task = loading_task;

            const pdf = await loading_task.promise;
            if (this._stopped) {
                try { pdf.destroy(); } catch (e) { /* already torn down */ }
                return;
            }

            this.state.pdf = pdf;
            this.state.pages = pdf.numPages;

            let page = this.state.page;
            if (page < 1) page = 1;
            if (page > this.state.pages) page = this.state.pages;
            this.state.page = page;

            await this._render_page(page);
            if (this._stopped) return;

            that.trigger('preview_loaded', {
                pages: this.state.pages,
                width: this._last_dims.width,
                height: this._last_dims.height,
                ratio: this._last_dims.ratio,
            });
        } catch (e) {
            this._show_error(e);
        }
    }

    on_stop() {
        this._stopped = true;
        if (this.state && this.state.pdf) {
            try { this.state.pdf.destroy(); } catch (e) { /* best effort */ }
            this.state.pdf = null;
        }
        if (this.state && this.state.loading_task && is_function(this.state.loading_task.destroy)) {
            try { this.state.loading_task.destroy(); } catch (e) { /* best effort */ }
            this.state.loading_task = null;
        }
    }

    // -- Public API ----------------------------------------------------------

    set_page(n) {
        n = int(n);
        if (!this.state.pages) return;
        if (n < 1) n = 1;
        if (n > this.state.pages) n = this.state.pages;
        if (n === this.state.page) return;

        this.state.page = n;
        this._render_page(n).then(() => {
            if (this._stopped) return;
            this.trigger('page_changed', { page: n });
        });
    }

    get_page() {
        return this.state.page;
    }

    get_pages() {
        return this.state.pages;
    }

    // -- Internal ------------------------------------------------------------

    /**
     * Render page N onto the canvas sized to the container width times devicePixelRatio (crisp on
     * retina), with the canvas displayed at CSS width 100%.
     */
    async _render_page(n) {
        const pdf = this.state.pdf;
        if (!pdf) return;

        const page = await pdf.getPage(n);
        if (this._stopped) return;

        const canvas = this.$sid('canvas')[0];
        const container_width = this.$.width() || 800;

        const unscaled = page.getViewport({ scale: 1 });
        const dpr = window.devicePixelRatio || 1;
        const css_scale = container_width / unscaled.width;
        const viewport = page.getViewport({ scale: css_scale * dpr });

        canvas.width = Math.floor(viewport.width);
        canvas.height = Math.floor(viewport.height);
        canvas.style.width = '100%';
        canvas.style.height = 'auto';

        const ctx = canvas.getContext('2d');
        await page.render({ canvasContext: ctx, viewport: viewport }).promise;

        this._last_dims = {
            width: unscaled.width,
            height: unscaled.height,
            ratio: unscaled.width ? (unscaled.height / unscaled.width) : 0,
        };
    }

    /**
     * Fail loud VISUALLY - a rendition-endpoint failure shows a message, never a blank pane.
     */
    _show_error(e) {
        console_debug('preview', 'Pdf_Viewer: failed to render document', e);
        if (this.$ && this.$.exists()) {
            this.$sid('canvas').hide();
            this.$sid('error').show();
        }
    }
}
