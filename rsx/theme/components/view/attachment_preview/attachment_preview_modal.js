/**
 * Attachment_Preview_Modal - one attachment, previewed beside its details.
 *
 *     Attachment_Preview_Modal.show(row);
 *
 * `row` is one Record_Attachment_List::row() (PHP): file_attachment_id, file_name,
 * file_type_label, size, created_at, created_by_name, download_url. The dialog shows the
 * document or image on the left and, on the right, what it is, who uploaded it and a
 * Download button. It changes nothing and resolves when closed.
 */
class Attachment_Preview_Modal extends Modal_Abstract {
    /**
     * @param {Object} attachment One Record_Attachment_List row.
     * @returns {Promise<void>}
     */
    static async show(attachment) {
        const body = $('<div>').component('Attachment_Preview_Modal_Body', { attachment }).component();
        await body.ready();

        await Modal.show({
            title: attachment.file_name,
            body: body.$,
            max_width: 1280,
            wide: true, // the body sizes itself inside the dialog's limits; see its SCSS
            buttons: [
                { label: 'Close', value: false, class: 'btn-secondary', default: true },
            ],
        });
    }
}
