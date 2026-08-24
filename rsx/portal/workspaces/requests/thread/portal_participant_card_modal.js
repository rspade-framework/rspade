/**
 * Portal_Participant_Card_Modal - a small contact-card dialog for one request-thread
 * participant (client member or posting staff) on the portal side. Shows a large
 * avatar/initials circle, name, email and phone (phone line hidden when absent). No
 * actions beyond Close. The data is the participant object the sidebar already holds -
 * no extra fetch.
 */
class Portal_Participant_Card_Modal extends Modal_Abstract {
    /**
     * @param {Object} participant {type, id, name, email, phone, avatar_attachment_id, initials}.
     * @returns {Promise<void>}
     */
    static async show(participant) {
        const body = $('<div>').component('Portal_Participant_Card_Modal_Body', { participant }).component();
        await body.ready();

        await Modal.show({
            title: 'Contact',
            body: body.$,
            max_width: 420,
            buttons: [
                { label: 'Close', value: false, class: 'btn-secondary', default: true },
            ],
        });
    }
}
