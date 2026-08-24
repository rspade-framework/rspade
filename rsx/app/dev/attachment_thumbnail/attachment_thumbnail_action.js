/**
 * Dev_Attachment_Thumbnail_Action - demo page for the <Attachment_Thumbnail> component.
 *
 * Pick an attachment; it is mounted twice, as a thumbnail and as a full Document_Preview. Reset
 * pushes the blob back to PENDING (no worker) so both mounts fall to their placeholder states;
 * Render runs the render inline, and both mounts swap over realtime with no reload.
 */
@route('/dev/attachment_thumbnail')
@layout('Dev_Spa_Layout')
@spa('Dev_Spa_Controller::index')
@auth('dev_tools')
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

    on_ready() {
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
        this.$sid('host').html('');
        this.$sid('preview_host').html('');
        this.$sid('status').text('No attachment selected');
        this.$sid('reset').prop('disabled', true);
        this.$sid('render').prop('disabled', true);
    }

    _mount(id) {
        this.state.current_id = id;

        this.$sid('host').html('<div></div>');
        this.$sid('host').children().first().component('Attachment_Thumbnail', { attachment_id: id, width: 160 });

        this.$sid('preview_host').html('<div style="width:100%;height:100%;"></div>');
        this.$sid('preview_host').children().first().component('Document_Preview', { attachment_id: id, page: 1 });

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

        this.$sid('status').text('Rendering inline...');
        try {
            const res = await Dev_Attachment_Thumbnail_Controller.run_render({ attachment_id: id });
            that.$sid('status').text('render_status_id = ' + res.render_status_id + ' (rendered_at ' + (res.rendered_at || 'null') + ')');
        } catch (e) {
            that.$sid('status').text('Render failed: ' + (e.message || str(e)));
        }
    }
}
