/**
 * Dev_Orm_Action - JavaScript ORM testing page
 * Tests Model.fetch() and relationship fetching functionality
 */
@route('/dev/orm')
@layout('Dev_Spa_Layout')
@spa('Dev_Spa_Controller::index')
@auth('closed')
class Dev_Orm_Action extends Spa_Action {
    on_create() {
        this.data.loading = true;
        this.data.error = null;
        this.data.results = {
            client: null,
            contact: null,
            project: null,
        };
        this.data.fetch_times = {};
    }

    async on_load() {
        try {
            // DELIBERATELY SERIAL, against the Promise.all() default: this dev page's
            // whole product is the PER-MODEL round-trip time below. Overlapping the
            // three fetches would make every reported number the batch's wall time
            // rather than that model's, which is the one thing this page measures.
            const start_client = performance.now();
            this.data.results.client = await Client_Model.fetch(1);
            this.data.fetch_times.client = (performance.now() - start_client).toFixed(2);

            const start_contact = performance.now();
            this.data.results.contact = await Contact_Model.fetch(1);
            this.data.fetch_times.contact = (performance.now() - start_contact).toFixed(2);

            const start_project = performance.now();
            this.data.results.project = await Project_Model.fetch(1);
            this.data.fetch_times.project = (performance.now() - start_project).toFixed(2);
        } catch (e) {
            this.data.error = e.message || str(e);
        }
        this.data.loading = false;
    }

    on_render() {
        const that = this;

        // Wire up fetch buttons for manual testing. Own markup, so on_render(); namespaced
        // and idempotent because on_render() re-fires on every render. A manual fetch is
        // painted into its result panel directly - this.data holds only what on_load()
        // fetched, and is frozen everywhere else.
        that.$sid('fetch_client').off('click.dev_orm').on('click.dev_orm', async () => {
            const id = int(that.$sid('client_id').val());
            if (!id) return;
            try {
                const result = await Client_Model.fetch(id);
                that._update_result('client', result);
            } catch (e) {
                that._update_result('client', { error: e.message });
            }
        });

        that.$sid('fetch_contact').off('click.dev_orm').on('click.dev_orm', async () => {
            const id = int(that.$sid('contact_id').val());
            if (!id) return;
            try {
                const result = await Contact_Model.fetch(id);
                that._update_result('contact', result);
            } catch (e) {
                that._update_result('contact', { error: e.message });
            }
        });

        that.$sid('fetch_project').off('click.dev_orm').on('click.dev_orm', async () => {
            const id = int(that.$sid('project_id').val());
            if (!id) return;
            try {
                const result = await Project_Model.fetch(id);
                that._update_result('project', result);
            } catch (e) {
                that._update_result('project', { error: e.message });
            }
        });
    }

    _update_result(type, result) {
        const that = this;
        const $container = that.$sid(`${type}_tree`);
        $container.component('JS_Tree_Debug_Component', {
            data: result,
            expand_depth: 2,
            show_class_names: true,
        });
    }
}
