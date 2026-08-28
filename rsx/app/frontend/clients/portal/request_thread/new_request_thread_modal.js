/**
 * New_Request_Thread_Modal - the staff "New Request" dialog. Collects a title + an
 * opening message and creates a Portal_Request_Thread (status NEW_REQUEST, with the
 * message as the firm's opening ask).
 *
 * The dialog is chrome: the endpoint lives on the form's own <Rsx_Form> tag and the
 * client the request belongs to rides in the form as a hidden field, so nothing here
 * repeats either.
 *
 * Returns the new thread's staff view URL on success, false on cancel.
 */
class New_Request_Thread_Modal extends Modal_Abstract {
    /**
     * @param {number} client_id The client the request thread is for.
     * @returns {Promise<string|false>} The staff thread-view URL, or false on cancel.
     */
    static async show(client_id) {
        const result = await Modal.form({
            title: 'New Request',
            component: 'New_Request_Thread_Modal_Form',
            component_args: { client_id: int(client_id) },
            submit_label: 'Send Request',
        });

        return result ? result.thread_url : false;
    }
}
