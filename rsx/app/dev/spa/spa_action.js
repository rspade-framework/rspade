/**
 * Dev_Spa_Action - SPA testing page
 * Tests SPA navigation, exception handling, and enable/disable functionality
 */
@route('/dev/spa')
@route('/dev/spa/:id')
@layout('Dev_Spa_Layout')
@spa('Dev_Spa_Controller::index')
@auth('closed')
class Dev_Spa_Action extends Spa_Action {
    on_create() {
        console.log('[Dev_Spa_Action] Created with args:', this.args);
    }

    on_render() {
        // Wire up button handlers. Own markup, so on_render(); namespaced and idempotent
        // because on_render() re-fires on every render.
        this.$sid('disable_spa').off('click.dev_spa').on('click.dev_spa', () => {
            Spa.disable();
            Flash_Alert.info('SPA navigation disabled');
        });

        this.$sid('enable_spa').off('click.dev_spa').on('click.dev_spa', () => {
            Spa.enable();
            Flash_Alert.success('SPA navigation enabled');
        });

        this.$sid('trigger_error').off('click.dev_spa').on('click.dev_spa', () => {
            // Trigger an unhandled exception with Error object
            const error = new Error('Test exception triggered from Dev_Spa_Action');
            Rsx.trigger('unhandled_exception', { exception: error, meta: {} });
        });

        this.$sid('trigger_string_error').off('click.dev_spa').on('click.dev_spa', () => {
            // Trigger an unhandled exception with string
            Rsx.trigger('unhandled_exception', { exception: 'Test string exception from Dev_Spa_Action', meta: {} });
        });
    }
}
