/**
 * Ajax_Entity_Select_Input
 *
 * Remote-search single-type entity picker. See ajax_entity_select_input.jqhtml.
 * Extends Select_Input for the val()/buffering machinery but replaces on_ready
 * with a TomSelect configured for remote loading via search_parents.
 */
class Ajax_Entity_Select_Input extends Select_Input {
    _get_value() {
        return this.tom_select ? this.tom_select.getValue() : '';
    }

    /**
     * Set the selected entity id. The base only calls this once ready (tom_select
     * exists); a pre-ready val() is buffered and replayed here. When the id is not
     * already an option, resolve its label so the preselection shows a name.
     */
    _set_value(value) {
        if (!this.tom_select) return;

        const id = (value === null || value === undefined || value === '') ? '' : str(value);
        if (id === '') {
            this.tom_select.clear(true);
            return;
        }

        if (this.tom_select.options[id]) {
            this.tom_select.setValue(id, true);
            return;
        }

        const that = this;
        Frontend_Tasks_Controller.search_parents({ type: this.args.parent_type, id: int(id) })
            .then((res) => {
                const rec = (res.results || [])[0];
                if (rec) that.tom_select.addOption(rec);
                that.tom_select.setValue(id, true);
            });
    }

    on_ready() {
        const that = this;

        this.tom_select = new TomSelect(this.$sid('input').get(0), {
            valueField: 'id',
            labelField: 'label',
            searchField: 'label',
            placeholder: this.args.placeholder || 'Search...',
            allowEmptyOption: true,
            create: false,
            maxOptions: 50,
            preload: 'focus',
            plugins: ['dropdown_input'],
            dropdownParent: 'body',
            load: function (query, callback) {
                const params = { type: that.args.parent_type, filter: query };
                if (that.args.exclude_id) params.exclude_id = that.args.exclude_id;
                Frontend_Tasks_Controller.search_parents(params)
                    .then((res) => callback(res.results || []))
                    .catch(() => callback());
            },
            onInitialize: function () {
                this.control.classList.add('form-select');
            },
            onChange: function (value) {
                that.trigger('input', value);
                that.trigger('val', value);
            },
        });

        this._mark_ready();
    }

    /**
     * Switch the search type (Parent_Selector_Input calls this when its type select
     * changes). Clears the current selection + option cache so the next search
     * queries the new type.
     */
    set_type(type) {
        this.args.parent_type = type;
        if (this.tom_select) {
            this.tom_select.clear(true);
            this.tom_select.clearOptions();
        }
    }

    /** Restrict Task-type results to exclude a task's subtree (cycle guard). */
    set_exclude(id) {
        this.args.exclude_id = id || null;
    }

    /** Lock/unlock the control (used when a derived project renders read-only). */
    set_disabled(disabled) {
        if (!this.tom_select) return;
        if (disabled) {
            this.tom_select.disable();
        } else {
            this.tom_select.enable();
        }
    }

    on_stop() {
        if (this.tom_select) this.tom_select.destroy();
    }
}
