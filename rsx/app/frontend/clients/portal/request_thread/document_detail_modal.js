/**
 * Document_Detail_Modal (staff) - a reusable dialog for one request-thread document.
 * Shows a centered thumbnail + metadata (name, review-state badge, reject reason) and
 * the staff actions: Download, Accept, Reject(optional reason).
 *
 * Accept/Reject call Frontend_Clients_Controller.request_document_review. The modal
 * resolves true if a review decision was made (the thread view should reload), false
 * otherwise.
 */
class Document_Detail_Modal extends Modal_Abstract {
    /**
     * @param {Object} document The thread document row (id, name, attachment_id,
     *   download_url, review_status, review_label, badge, reject_reason, requires_review).
     * @returns {Promise<boolean>} true if Accept/Reject was applied.
     */
    static async show(document) {
        const body = $('<div>').component('Document_Detail_Modal_Body', { document }).component();
        await body.ready();

        // Accepted documents have no further action; rejected/pending can still be acted on.
        const is_reviewable = int(document.requires_review) === 1;

        const buttons = [
            { label: 'Close', value: false, class: 'btn-secondary' },
            { label: 'Download', class: 'btn-outline-primary', callback: () => {
                window.location.href = document.download_url;
                return false; // keep open
            } },
        ];

        if (is_reviewable) {
            buttons.push({
                label: 'Reject',
                class: 'btn-outline-danger',
                callback: async () => {
                    const reason = await Modal.prompt('Reject Document', 'Reason for rejection (optional):');
                    if (reason === null) return false; // cancelled the prompt - keep open
                    return { decision: 'reject', reason };
                },
            });
            buttons.push({
                label: 'Accept',
                value: { decision: 'accept' },
                class: 'btn-success',
                default: true,
            });
        }

        const decision = await Modal.show({
            title: 'Document',
            body: body.$,
            max_width: 560,
            buttons,
        });

        if (!decision || !decision.decision) return false;

        try {
            await Frontend_Clients_Controller.request_document_review({
                document_id: document.id,
                decision: decision.decision,
                reason: decision.reason || '',
            });
            return true;
        } catch (e) {
            await Modal.alert('Error', e.message || 'Failed to review the document');
            return false;
        }
    }
}
