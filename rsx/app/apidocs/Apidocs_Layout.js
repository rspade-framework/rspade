/**
 * Apidocs_Layout - standalone midnight-dark Spa_Layout for the API documentation tool.
 *
 * Minimal chrome: the whole page is the framework-core Api_Docs_Page. The dark <body>-level
 * background is set here so there is no white flash / overscroll gap around the tool.
 */
class Apidocs_Layout extends Spa_Layout {
    on_create() {
        document.documentElement.style.background = '#0D1117';
        if (document.body) {
            document.body.style.background = '#0D1117';
        }
    }

    async on_action(url, action_name, args) {
        document.title = 'RSX - API Docs';
    }
}
