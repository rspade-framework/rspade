/**
 * Api_Docs_Page - root of the external REST API documentation / tester UI.
 *
 * Loads the resolved catalog for one version (Api_Docs_Controller.get_catalog), renders a
 * resource-grouped sidebar + endpoint cards, and hosts the shared Api_Key_Bar. The version
 * <select> navigates to /apidocs/vN (a full re-load of the action with the new version arg).
 *
 * args: version (int|null) - null selects the newest version.
 */
class Api_Docs_Page extends Component {
    on_create() {
        this.data.loading = true;
        this.data.error = null;
        this.data.catalog = null;
    }

    async on_load() {
        const v = this.args.version || null;
        try {
            const cat = await Api_Docs_Controller.get_catalog({ version: v });
            // Assign stable per-endpoint anchor ids (this.data is writable here).
            for (const rk of Object.keys(cat.resolved || {})) {
                for (const ep of cat.resolved[rk].endpoints) {
                    ep._anchor = 'ep-' + (ep.class + '-' + ep.method)
                        .toLowerCase()
                        .replace(/[^a-z0-9]+/g, '-')
                        .replace(/(^-+|-+$)/g, '');
                }
            }
            this.data.catalog = cat;
        } catch (e) {
            this.data.error = e.message ? e.message : 'Failed to load API catalog';
        }
        this.data.loading = false;
    }

    on_ready() {
        const that = this;

        const $sel = this.$sid('version_select');
        if ($sel && $sel.exists()) {
            $sel.off('change.apidocs').on('change.apidocs', function () {
                const v = int($(this).val());
                // Literal SPA path: this framework-core component cannot name the app-side
                // action class, so the /apidocs/vN route is constructed directly here.
                Spa.dispatch('/apidocs/v' + v);
            });
        }

        const $side = this.$sid('sidebar');
        if ($side && $side.exists()) {
            $side.off('click.apidocs').on('click.apidocs', 'a[data-anchor]', function (e) {
                e.preventDefault();
                const anchor = $(this).attr('data-anchor');
                const el = document.getElementById(anchor);
                if (el) {
                    el.scrollIntoView({ behavior: 'smooth', block: 'start' });
                }
            });
        }
    }
}
