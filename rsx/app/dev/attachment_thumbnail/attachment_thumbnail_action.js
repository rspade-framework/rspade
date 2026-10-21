/**
 * Dev_Attachment_Thumbnail_Action - demo page for the <Attachment_Thumbnail> component.
 *
 * Pick an attachment; it is mounted twice, as a thumbnail and as a full Document_Preview. Reset
 * pushes the blob back to PENDING (no worker) so both mounts fall to their placeholder states;
 * Render runs the render and waits for it, and both mounts swap over realtime with no reload.
 *
 * Under each mount is a readout of its last 'preview_loaded' event - the one way a host learns
 * what a preview widget put on screen. Both widgets report every state, so the readouts follow
 * the placeholder ('preparing') and the swap ('loaded') without this page touching their DOM.
 */
@route('/dev/attachment_thumbnail')
@layout('Dev_Spa_Layout')
@spa('Dev_Spa_Controller::index')
@auth('closed')
class Dev_Attachment_Thumbnail_Action extends Spa_Action {
    on_create() {
        this.data.loading = true;
        this.data.error = null;
        this.data.attachments = [];
        this.state = { current_id: null };
    }

    async on_load() {
        try {
            const res = await Dev_Attachment_Thumbnail_Controller.list_attachments();
            this.data.attachments = res.attachments || [];
        } catch (e) {
            this.data.error = e.message || str(e);
        }
        this.data.loading = false;
    }

    on_render() {
        // Own markup only, so bound in on_render() and the cached paint responds at once.
        // Namespaced and idempotent: on_render() re-fires on every render.
        const that = this;
        if (this.data.error) return;

        this.$sid('select').off('change.at').on('change.at', function () {
            const id = int($(this).val());
            if (!id) {
                that._clear();
                return;
            }
            that._mount(id);
        });

        this.$sid('reset').off('click.at').on('click.at', () => that._reset());
        this.$sid('render').off('click.at').on('click.at', () => that._render());
    }

    // -- Mounting ------------------------------------------------------------

    _clear() {
        this.state.current_id = null;
        this.$sid('host').empty();
        this.$sid('preview_host').empty();
        this.$sid('thumbnail_event').text('');
        this.$sid('preview_event').text('');
        this.$sid('status').text('No attachment selected');
        this.$sid('reset').prop('disabled', true);
        this.$sid('render').prop('disabled', true);
    }

    _mount(id) {
        this.state.current_id = id;

        this.$sid('host').html('<div></div>');
        const $thumbnail = this.$sid('host').children().first();
        $thumbnail.component('Attachment_Thumbnail', { attachment_id: id, width: 160 });

        this.$sid('preview_host').html('<div style="width:100%;height:100%;"></div>');
        const $preview = this.$sid('preview_host').children().first();
        $preview.component('Document_Preview', { attachment_id: id, page: 1 });

        // Each mount is a new component, so its subscription is new with it. The readouts are
        // cleared here and filled by the event alone: it arrives for every state, and a report
        // made before this line ran is delivered anyway.
        const that = this;
        this.$sid('thumbnail_event').text('');
        this.$sid('preview_event').text('');
        $thumbnail.component().on('preview_loaded', (component, data) => {
            that.$sid('thumbnail_event').text(
                data.state + ' - variant ' + (data.variant || 'none') + ', '
                + data.natural_width + 'x' + data.natural_height + ', render_status ' + data.render_status
            );
        });
        $preview.component().on('preview_loaded', (component, data) => {
            that.$sid('preview_event').text(
                data.state + ' - ' + data.pages + ' page(s), render_status ' + data.render_status
            );
        });

        this.$sid('status').text('Mounted attachment #' + id);
        this.$sid('reset').prop('disabled', false);
        this.$sid('render').prop('disabled', false);
    }

    // -- Pipeline controls ---------------------------------------------------

    async _reset() {
        const that = this;
        const id = this.state.current_id;
        if (!id) return;

        this.$sid('status').text('Resetting to PENDING...');
        try {
            const res = await Dev_Attachment_Thumbnail_Controller.reset_render({ attachment_id: id });
            that.$sid('status').text('render_status_id = ' + res.render_status_id + ' (queued, no worker spawned)');
        } catch (e) {
            that.$sid('status').text('Reset failed: ' + (e.message || str(e)));
        }
    }

    async _render() {
        const that = this;
        const id = this.state.current_id;
        if (!id) return;

        this.$sid('status').text('Rendering...');
        try {
            const res = await Dev_Attachment_Thumbnail_Controller.run_render({ attachment_id: id });
            that.$sid('status').text('render_status_id = ' + res.render_status_id + ' (rendered_at ' + (res.rendered_at || 'null') + ')');
        } catch (e) {
            that.$sid('status').text('Render failed: ' + (e.message || str(e)));
        }
    }
}
