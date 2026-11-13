/**
 * Record_Attachments
 *
 * See record_attachments.jqhtml for the contract. This file owns the two ways a file
 * arrives (the Upload button's file picker and a drop on the section), the upload itself,
 * and opening the preview dialog for a row.
 */
class Record_Attachments extends Component {
    on_create() {
        // Files on their way up, shown as pending rows: {name, percent} (percent null until
        // the first progress report).
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
     * A file that fails to upload is reported by name and the rest still attach. A file the
     * record already holds is asked about by the endpoint, one question per such file
     * (Rsx_Form.call_answering() presents each through the registered question handler):
     * Upload Again attaches it, Skip This File leaves it out, and Cancel Upload stops the
     * call, so nothing in the batch is attached. A file left out is never claimed; the
     * framework sweeps unclaimed uploads.
     */
    async _upload(files) {
        if (!this.args.can_upload || files.length === 0) return;

        const controller = Manifest.get_class_by_name(this.args.controller);
        if (!controller || typeof controller[this.args.method] !== 'function') {
            throw new Error('Record_Attachments could not resolve the endpoint ' + this.args.controller + '::' + this.args.method);
        }

        const pending = files.map((file) => ({ name: file.name, percent: null }));
        this.state.uploading = this.state.uploading.concat(pending);
        this.render();

        const keys = [];
        const failures = [];

        await Promise.all(files.map(async (file, index) => {
            const form_data = new FormData();
            form_data.append('file', file);
            const entry = pending[index];

            try {
                const result = await Ajax.upload(form_data, {
                    on_progress: (loaded, total) => this._show_progress(entry, loaded, total),
                });
                keys.push(result.attachment.key);
            } catch (e) {
                failures.push(file.name + ': ' + (e.message || 'upload failed'));
            }
        }));

        let attached = false;

        if (keys.length > 0) {
            try {
                const result = await Rsx_Form.call_answering(controller[this.args.method], { id: this.args.record_id, keys });
                attached = !Rsx_Form.is_cancelled(result);
            } catch (e) {
                failures.push(e.message || 'The files could not be attached.');
            }
        }
        // The uploads and the attach above finish even when the component was stopped
        // meanwhile; only what follows belongs to it.
        if (this.is_stopped()) return;

        this.state.uploading = this.state.uploading.filter((entry) => !pending.includes(entry));

        if (failures.length > 0) {
            await this.alive(Modal.alert('Upload Failed', failures.join('\n')));
        }

        // The pending rows are cleared here: when the claim attached nothing new (every file
        // skipped, every upload failed, or the user cancelled) the host's refetch returns the
        // rows it already had and would not re-render this section. After a claim the host
        // refetches and re-renders with the new rows.
        this.render();
        if (attached) {
            this.trigger('changed');
        }
    }

    /**
     * One upload's progress, written into its pending row in place: a re-render per progress
     * report would rebuild the whole section many times a second. The entry keeps the figure,
     * so a render meanwhile draws it too.
     */
    _show_progress(entry, loaded, total) {
        if (total <= 0) return;
        entry.percent = Math.floor((100 * loaded) / total);
        const at = this.state.uploading.indexOf(entry);
        if (at === -1 || this.is_stopped()) return;
        this.$.find('.Record_Attachments__item--uploading').eq(at).find('.Record_Attachments__size').text(Record_Attachments.uploading_label(entry));
    }

    /** The size column of a pending row: "Uploading..." until the first report, then the percentage. */
    static uploading_label(entry) {
        return entry.percent === null ? 'Uploading...' : 'Uploading ' + entry.percent + '%';
    }
}
