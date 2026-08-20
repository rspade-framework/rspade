/**
 * New_Request_Thread_Modal - the staff "New Request" dialog. Collects a title + an
 * opening message and creates a Portal_Request_Thread (status NEW_REQUEST, with the
 * message as the firm's opening ask) via Frontend_Clients_Controller.request_thread_create.
 *
 * Returns the redirect URL to the new staff thread view on success, false on cancel.
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
            on_submit: async (form) => {
                try {
                    const values = form.vals();
                    const response = await Frontend_Clients_Controller.request_thread_create({
                        client_id,
                        title: values.title,
                        body: values.body,
                    });
                    return response.redirect;
                } catch (error) {
                    await form.render_error(error);
                    return false; // Keep modal open
                }
            },
        });

        return result || false;
    }
}
