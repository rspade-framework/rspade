/**
 * Portal_Document_Detail_Modal - a reusable dialog for one request-thread document on the
 * client (portal) side. The portal mirror of the staff Document_Detail_Modal MINUS the
 * staff review actions: it shows a centered thumbnail + metadata (name, review-state
 * badge, reject reason if rejected) and a Download button. The client can never
 * Accept/Reject - that is staff-only.
 */
class Portal_Document_Detail_Modal extends Modal_Abstract {
    /**
     * @param {Object} document The thread document row (id, name, attachment_id,
     *   download_url, review_status, review_label, badge, reject_reason).
     * @returns {Promise<void>}
     */
    static async show(document) {
        const body = $('<div>').component('Portal_Document_Detail_Modal_Body', { document }).component();
        await body.ready();

        await Modal.show({
            title: 'Document',
            body: body.$,
            max_width: 560,
            buttons: [
                { label: 'Close', value: false, class: 'btn-secondary' },
                { label: 'Download', class: 'btn-primary', default: true, callback: () => {
                    window.location.href = document.download_url;
                    return false; // keep open
                } },
            ],
        });
    }
}
