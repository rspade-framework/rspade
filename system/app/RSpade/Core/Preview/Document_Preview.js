/**
 * Document_Preview
 *
 * See Document_Preview.jqhtml for the full contract. Loads the server's viewer resolution in
 * on_load, dispatches to the built-in viewers via the template, instantiates app-registered
 * viewers dynamically in on_ready, and re-triggers the active viewer's events outward.
 */
class Document_Preview extends Component {
    static BUILTIN_VIEWERS = ['Pdf_Viewer', 'Image_Viewer', 'Icon_Viewer'];

    on_create() {
        this.data.info = null;
        if (this.args.page === undefined || this.args.page === null) {
            this.args.page = 1;
        }
    }

    async on_load() {
        // Read this.args, write this.data only. Server resolves the viewer + urls.
        this.data.info = await File_Preview_Controller.get_preview_info({
            attachment_id: this.args.attachment_id,
        });
    }

    on_ready() {
        const that = this;
        if (!this.data.info) return;

        const viewer_name = this.data.info.viewer;

        // App-registered viewer (config('rsx.preview.viewers') override): the template rendered an
        // empty host div with $sid="viewer"; instantiate the named component into it. Built-in
        // viewers are already rendered by the template.
        if (Document_Preview.BUILTIN_VIEWERS.indexOf(viewer_name) === -1) {
            console_debug('preview', 'Document_Preview: instantiating app-registered viewer', viewer_name);
            this.$sid('viewer').component(viewer_name, {
                url: this.data.info.urls.inline,
                extension: this.data.info.extension,
                file_name: this.data.info.file_name,
                page: this.args.page || 1,
            });
        }

        const viewer = this.sid('viewer');
        if (!viewer) {
            console_debug('preview', 'Document_Preview: no viewer component resolved for', viewer_name);
            return;
        }

        // Re-emit the active viewer's events outward so the page (pagination UI, status) can listen
        // on Document_Preview itself. Events fired before this registration still deliver. The
        // event is named 'preview_loaded' (NOT 'loaded') because 'loaded' is a reserved jqhtml
        // component lifecycle event (fired internally with no payload) - reusing it collides.
        viewer.on('preview_loaded', (comp, data) => that.trigger('preview_loaded', data));
        viewer.on('page_changed', (comp, data) => that.trigger('page_changed', data));
    }

    // -- Public API (delegates to the active viewer) -------------------------

    set_page(n) {
        const viewer = this.sid('viewer');
        if (!viewer || !is_function(viewer.set_page)) {
            console_debug('preview', 'Document_Preview.set_page: viewer not ready');
            return;
        }
        viewer.set_page(n);
    }

    get_page() {
        const viewer = this.sid('viewer');
        if (!viewer || !is_function(viewer.get_page)) {
            return this.args.page || 1;
        }
        return viewer.get_page();
    }

    get_pages() {
        const viewer = this.sid('viewer');
        if (!viewer || !is_function(viewer.get_pages)) {
            return 0;
        }
        return viewer.get_pages();
    }
}
