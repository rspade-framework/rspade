/**
 * Dev_Spa_Test_Exception_Action - Test page that throws exception on ready
 */
@route('/dev/spa_test_exception')
@layout('Dev_Spa_Layout')
@spa('Dev_Spa_Controller::index')
@auth('closed')
class Dev_Spa_Test_Exception_Action extends Spa_Action {
    on_ready() {
        // Throw exception to test error handling
        throw new Error('Test exception from Dev_Spa_Test_Exception_Action on_ready()');
    }
}
