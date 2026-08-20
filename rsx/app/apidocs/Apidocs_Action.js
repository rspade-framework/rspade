/**
 * Apidocs_Action - thin SPA action that mounts the framework-core Api_Docs_Page.
 *
 * Two explicit routes (the SPA matcher has no optional-param support): /apidocs selects the
 * newest version; /apidocs/:version pins one. The 'vN' segment is parsed to an int here and
 * handed to Api_Docs_Page, which owns all catalog loading and rendering.
 */
@route('/apidocs')
@route('/apidocs/:version')
@layout('Apidocs_Layout')
@spa('Apidocs_Spa_Controller::index')
@auth('is_logged_in')
class Apidocs_Action extends Spa_Action {
    on_create() {
        let version = null;
        if (this.args.version) {
            const m = str(this.args.version).match(/^v?(\d+)$/i);
            if (m) {
                version = int(m[1]);
            }
        }
        this.state = { version: version };
    }

    page_title() {
        return 'API Docs';
    }
}
