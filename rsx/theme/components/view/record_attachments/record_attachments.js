/**
 * Record_Attachments
 *
 * See record_attachments.jqhtml for the contract. This file owns the two ways a file
 * arrives (the Upload button's file picker and a drop on the section), the upload itself,
 * and opening the preview dialog for a row.
 */
class Record_Attachments extends Component {
    on_create() {
        // File names currently on their way up, shown as pending rows.
        this.state.uploading = [];

        // Rsx_Droppable delivers dropped files here while the section carries rsx-droppable.
        this.on('file-drop', (component, data) => {
            this._upload(Array.from(data.files));
        });
    }

    on_render() {
        this.$.toggleClass('rsx-droppable', !!this.args.can_upload);

        // Own markup only, so bound here: the first paint responds at once, and the
        // render() an upload makes gets its buttons rebound. Namespaced and idempotent:
        // on_render() re-fires on every render.
        const that = this;

        this.$.off('.record_attachments');

        this.$.on('click.record_attachments', '.Record_Attachments__item[data-attachment-id]', function () {
            const id = int($(this).attr('data-attachment-id'));
            const row = (that.args.attachments || []).find((r) => int(r.file_attachment_id) === id);
            if (row) Attachment_Preview_Modal.show(row);
        });

        if (!this.args.can_upload) return;

        this.$sid('upload_button').off('click.record_attachments').on('click.record_attachments', () => {
            this.$sid('file_input').click();
        });

        this.$sid('file_input').off('change.record_attachments').on('change.record_attachments', (e) => {
            const files = Array.from(e.currentTarget.files);
            e.currentTarget.value = '';
            this._upload(files);
        });
    }

    /**
     * Send the files up, then attach all of them to the record in one call.
     *
     * A file that fails to upload is reported by name and the rest still attach.
     */
    async _upload(files) {
        if (!this.args.can_upload || files.length === 0) return;

        const controller = Manifest.get_class_by_name(this.args.controller);
        if (!controller || typeof controller[this.args.method] !== 'function') {
            throw new Error('Record_Attachments could not resolve the endpoint ' + this.args.controller + '::' + this.args.method);
        }

        this.state.uploading = this.state.uploading.concat(files.map((file) => file.name));
        this.render();

        const keys = [];
        const failures = [];

        await Promise.all(files.map(async (file) => {
            const form_data = new FormData();
            form_data.append('file', file);

            try {
                const result = await Ajax.upload(form_data);
                keys.push(result.attachment.key);
            } catch (e) {
                failures.push(file.name + ': ' + (e.message || 'upload failed'));
            }
        }));

        if (keys.length > 0) {
            try {
                await controller[this.args.method]({ id: this.args.record_id, keys });
            } catch (e) {
                failures.push(e.message || 'The files could not be attached.');
            }
        }
        // The uploads and the attach above finish even when the component was stopped
        // meanwhile; only what follows belongs to it.
        if (this.is_stopped()) return;

        const names = files.map((file) => file.name);
        this.state.uploading = this.state.uploading.filter((name) => {
            const at = names.indexOf(name);
            if (at === -1) return true;
            names.splice(at, 1);
            return false;
        });

        if (failures.length > 0) {
            await this.alive(Modal.alert('Upload Failed', failures.join('\n')));
        }

        // The host refetches and re-renders this section with the new rows; when nothing
        // attached, the pending rows are simply cleared.
        if (keys.length > 0) {
            this.trigger('changed');
        } else {
            this.render();
        }
    }
}
