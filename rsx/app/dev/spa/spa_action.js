/**
 * Dev_Spa_Action - SPA testing page
 * Tests SPA navigation, exception handling, and enable/disable functionality
 */
@route('/dev/spa')
@route('/dev/spa/:id')
@layout('Dev_Spa_Layout')
@spa('Dev_Spa_Controller::index')
@auth('dev_tools')
class Dev_Spa_Action extends Spa_Action {
    on_create() {
        console.log('[Dev_Spa_Action] Created with args:', this.args);
    }

    on_ready() {
        // Wire up button handlers
        this.$sid('disable_spa').click(() => {
            Spa.disable();
            Flash_Alert.info('SPA navigation disabled');
        });

        this.$sid('enable_spa').click(() => {
            Spa.enable();
            Flash_Alert.success('SPA navigation enabled');
        });

        this.$sid('trigger_error').click(() => {
            // Trigger an unhandled exception with Error object
            const error = new Error('Test exception triggered from Dev_Spa_Action');
            Rsx.trigger('unhandled_exception', { exception: error, meta: {} });
        });

        this.$sid('trigger_string_error').click(() => {
            // Trigger an unhandled exception with string
            Rsx.trigger('unhandled_exception', { exception: 'Test string exception from Dev_Spa_Action', meta: {} });
        });
    }
}
