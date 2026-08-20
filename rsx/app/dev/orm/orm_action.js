/**
 * Dev_Orm_Action - JavaScript ORM testing page
 * Tests Model.fetch() and relationship fetching functionality
 */
@route('/dev/orm')
@layout('Dev_Spa_Layout')
@spa('Dev_Spa_Controller::index')
@auth('dev_tools')
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
            // Fetch one record from each model
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

    on_ready() {
        const that = this;

        // Wire up fetch buttons for manual testing
        that.$sid('fetch_client').click(async () => {
            const id = int(that.$sid('client_id').val());
            if (!id) return;
            try {
                const result = await Client_Model.fetch(id);
                that.data.results.client = result;
                that._update_result('client', result);
            } catch (e) {
                that._update_result('client', { error: e.message });
            }
        });

        that.$sid('fetch_contact').click(async () => {
            const id = int(that.$sid('contact_id').val());
            if (!id) return;
            try {
                const result = await Contact_Model.fetch(id);
                that.data.results.contact = result;
                that._update_result('contact', result);
            } catch (e) {
                that._update_result('contact', { error: e.message });
            }
        });

        that.$sid('fetch_project').click(async () => {
            const id = int(that.$sid('project_id').val());
            if (!id) return;
            try {
                const result = await Project_Model.fetch(id);
                that.data.results.project = result;
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
