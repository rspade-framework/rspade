/**
 * Entity_Link
 *
 * A fetched entity reference (type icon + name, optionally linked). Generalizes
 * the former Client_Label / Client_Label_Link. See entity_link.jqhtml.
 */
class Entity_Link extends Component {
    // Per-model type icon.
    static ICONS = {
        Client_Model: 'bi bi-building',
        Contact_Model: 'bi bi-person',
        Project_Model: 'bi bi-folder',
        Task_Model: 'bi bi-check2-square',
        User_Model: 'bi bi-person-circle',
    };

    // Per-model view-route action. Models absent here render as plain text (no anchor) -
    // e.g. User_Model has no staff view page, so a user reference shows an icon + name only.
    static ROUTES = {
        Client_Model: 'Clients_View_Action',
        Contact_Model: 'Contacts_View_Action',
        Project_Model: 'Projects_View_Action',
        Task_Model: 'Tasks_View_Action',
    };

    static icon_for(model) {
        return Entity_Link.ICONS[model] || 'bi bi-box';
    }

    static route_for(model, id) {
        const action = Entity_Link.ROUTES[model];
        if (!action || !id) return null;
        return Rsx.Route(action, id);
    }

    // Resolve a display name across the app's named entities (see on_load).
    static _display_name(record) {
        if (record.name) return record.name;
        if (record.title) return record.title;
        const full = [record.first_name, record.last_name].filter(Boolean).join(' ').trim();
        if (full) return full;
        return record.get_full_name || record.email || null;
    }

    on_create() {
        this.data.record = null;
        this.data.loading = true;

        if (this.args.small) {
            this.$.addClass('Entity_Link--small');
        }
    }

    async on_load() {
        if (this.args.id && this.args.model) {
            const model_class = Manifest.get_class_by_name(this.args.model);
            if (model_class) {
                const record = await model_class.fetch_cached(this.args.id);
                // Store a PLAIN object, never the hydrated model instance: a
                // model instance in this.data defeats the framework's post-load
                // re-render heuristic (it serializes this.data to detect the
                // change), so the fetched name would never paint - notably
                // inside DataGrid rows.
                // Display-name resolution order: `name` (client/project) -> `title`
                // (task) -> full/first+last name -> email (user). Lets one component
                // reference any of the app's named entities.
                this.data.record = record ? { name: Entity_Link._display_name(record) } : null;
            }
        }
        this.data.loading = false;
    }

    /**
     * Get or set the displayed entity id.
     * @param {number} [id] - when provided, sets the id and reloads
     */
    val(id) {
        if (arguments.length === 0) {
            return this.args.id;
        }
        this.args.id = id;
        this.reload();
    }
}
