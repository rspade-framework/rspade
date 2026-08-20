/**
 * Dev_Flash - Flash alert system testing page
 */
class Dev_Flash {
    static on_app_ready() {
        if (!$('.Dev_Flash').exists()) return;

        // Client-side flash alerts (direct JavaScript)
        $('#test-client-success').click(() => {
            console.log('click');
            Flash_Alert.success('This is a client-side success message!');
        });

        $('#test-client-error').click(() => {
            Flash_Alert.error('This is a client-side error message!');
        });

        $('#test-client-info').click(() => {
            Flash_Alert.info('This is a client-side info message!');
        });

        $('#test-client-warning').click(() => {
            Flash_Alert.warning('This is a client-side warning message!');
        });

        // Ajax flash alerts (server-side via Ajax)
        $('#test-ajax-success').click(async () => {
            await Dev_Flash_Controller.test_ajax_success();
        });

        $('#test-ajax-error').click(async () => {
            await Dev_Flash_Controller.test_ajax_error();
        });

        $('#test-ajax-info').click(async () => {
            await Dev_Flash_Controller.test_ajax_info();
        });

        $('#test-ajax-warning').click(async () => {
            await Dev_Flash_Controller.test_ajax_warning();
        });

        // Queue testing
        $('#test-queue-client').click(() => {
            Flash_Alert.success('First message (success)');
            Flash_Alert.error('Second message (error)');
            Flash_Alert.info('Third message (info)');
            Flash_Alert.warning('Fourth message (warning)');
        });

        $('#test-queue-ajax').click(async () => {
            await Dev_Flash_Controller.test_ajax_queue();
        });

        // Persistence testing (Ajax + Redirect)
        $('#test-ajax-redirect').click(async () => {
            const response = await Dev_Flash_Controller.test_ajax_redirect();
            if (response.redirect) {
                window.location.href = response.redirect;
            }
        });
    }
}
