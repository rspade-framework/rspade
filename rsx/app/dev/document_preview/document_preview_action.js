/**
 * Dev_Document_Preview_Action - demo page for the Document_Preview component.
 *
 * Picks an attachment, renders it through Document_Preview, drives pagination via the component's
 * set_page()/'page_changed'/'preview_loaded' events, and shows the SAME attachment's extracted text
 * through Document_Preview's sibling component, Document_Text_Preview. The Fit toggle beside the
 * pagination controls demonstrates the $fit arg against a bounded-height host (800px x 70vh):
 * "width" overflows the host vertically and scrolls, "contain" fits the whole page inside it.
 *
 * The two buttons beside the text make the async extraction observable: Reset Extraction pushes the
 * blob back to un-indexed (no worker), Extract Now runs the pass inline. Neither reloads the page -
 * the notice swaps to the text over the realtime frame the pass emits.
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
        this.state = { current_id: null, page: 1, pages: 0, fit: 'width' };
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

        // The $fit toggle re-mounts the preview: $fit is read in on_create, so changing it is a
        // new component, exactly as changing the attachment is.
        this.$sid('fit').off('change.dp').on('change.dp', function () {
            that.state.fit = str($(this).val());
            if (that.state.current_id) {
                that._load_attachment(that.state.current_id);
            }
        });

        this.$sid('prev').off('click.dp').on('click.dp', () => that._nav(-1));
        this.$sid('next').off('click.dp').on('click.dp', () => that._nav(1));

        this.$sid('reset_extraction').off('click.dp').on('click.dp', async () => {
            if (!that.state.current_id) return;
            await Dev_Document_Preview_Controller.reset_extraction({ attachment_id: that.state.current_id });
        });
        this.$sid('run_extraction').off('click.dp').on('click.dp', async () => {
            if (!that.state.current_id) return;
            await Dev_Document_Preview_Controller.run_extraction({ attachment_id: that.state.current_id });
        });
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
        this.$sid('text_host').html('');
        this.$sid('reset_extraction').prop('disabled', true);
        this.$sid('run_extraction').prop('disabled', true);
    }

    _load_attachment(id) {
        const that = this;
        this.state.current_id = id;
        this.state.page = 1;
        this.state.pages = 0;

        // Fresh mount each time - .component() destroys any prior Document_Preview (and its viewer).
        this.$sid('preview_host').html('<div style="width:100%;height:100%;"></div>');
        const $mount = this.$sid('preview_host').children().first();
        $mount.component('Document_Preview', { attachment_id: id, page: 1, fit: this.state.fit });

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

        // Fresh mount each time - .component() destroys any prior Document_Text_Preview.
        this.$sid('text_host').html('<div style="width:100%;height:100%;"></div>');
        this.$sid('text_host').children().first().component('Document_Text_Preview', { attachment_id: id });
        this.$sid('reset_extraction').prop('disabled', false);
        this.$sid('run_extraction').prop('disabled', false);

        this._update_indicator();
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
