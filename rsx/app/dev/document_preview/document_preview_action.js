/**
 * Dev_Document_Preview_Action - demo page for the Document_Preview component.
 *
 * Picks an attachment, renders it through Document_Preview, drives pagination via the component's
 * set_page()/'page_changed'/'preview_loaded' events, and shows the extracted text (from the search
 * extraction pipeline) in a read-only textarea below.
 */
@route('/dev/document_preview')
@layout('Dev_Spa_Layout')
@spa('Dev_Spa_Controller::index')
@auth('closed')
class Dev_Document_Preview_Action extends Spa_Action {
    on_create() {
        this.data.loading = true;
        this.data.error = null;
        this.data.attachments = [];
        this.state = { current_id: null, page: 1, pages: 0 };
    }

    async on_load() {
        try {
            const res = await Dev_Document_Preview_Controller.list_attachments();
            this.data.attachments = res.attachments || [];
        } catch (e) {
            this.data.error = e.message || str(e);
        }
        this.data.loading = false;
    }

    on_ready() {
        const that = this;
        if (this.data.error) return;

        this.$sid('select').off('change.dp').on('change.dp', function () {
            const id = int($(this).val());
            if (!id) {
                that._clear_preview();
                return;
            }
            that._load_attachment(id);
        });

        this.$sid('prev').off('click.dp').on('click.dp', () => that._nav(-1));
        this.$sid('next').off('click.dp').on('click.dp', () => that._nav(1));
    }

    // -- Preview lifecycle ---------------------------------------------------

    _clear_preview() {
        this.state.current_id = null;
        this.state.page = 1;
        this.state.pages = 0;
        this.$sid('preview_host').html(
            '<div class="d-flex align-items-center justify-content-center h-100 text-muted">Select an attachment to preview.</div>'
        );
        this.$sid('indicator').text('No document loaded');
        this.$sid('prev').prop('disabled', true);
        this.$sid('next').prop('disabled', true);
        this.$sid('text').val('');
        this.$sid('status').text('Extraction status: --');
    }

    _load_attachment(id) {
        const that = this;
        this.state.current_id = id;
        this.state.page = 1;
        this.state.pages = 0;

        // Fresh mount each time - .component() destroys any prior Document_Preview (and its viewer).
        this.$sid('preview_host').html('<div style="width:100%;height:100%;"></div>');
        const $mount = this.$sid('preview_host').children().first();
        $mount.component('Document_Preview', { attachment_id: id, page: 1 });

        const preview = $mount.component();

        preview.on('preview_loaded', (comp, data) => {
            that.state.pages = data.pages || 0;
            that.state.page = comp.get_page();
            that._update_indicator();
        });
        preview.on('page_changed', (comp, data) => {
            that.state.page = data.page;
            that._update_indicator();
        });

        this._update_indicator();
        this._load_text(id);
    }

    async _load_text(id) {
        const that = this;
        this.$sid('status').text('Extraction status: loading...');
        try {
            const res = await Dev_Document_Preview_Controller.get_extracted_text({ attachment_id: id });
            if (that.state.current_id !== id) return; // selection changed mid-flight
            that.$sid('text').val(res.text || '');
            that.$sid('status').text('Extraction status: ' + res.status);
        } catch (e) {
            that.$sid('status').text('Extraction status: error (' + (e.message || str(e)) + ')');
        }
    }

    _nav(delta) {
        const preview = this._preview();
        if (!preview) return;
        preview.set_page(this.state.page + delta);
    }

    _preview() {
        const $mount = this.$sid('preview_host').children().first();
        if (!$mount.exists()) return null;
        return $mount.component() || null;
    }

    _update_indicator() {
        const total = this.state.pages ? this.state.pages : '?';
        this.$sid('indicator').text('Page ' + this.state.page + ' of ' + total);
        this.$sid('prev').prop('disabled', this.state.page <= 1);
        this.$sid('next').prop('disabled', !this.state.pages || this.state.page >= this.state.pages);
    }
}
