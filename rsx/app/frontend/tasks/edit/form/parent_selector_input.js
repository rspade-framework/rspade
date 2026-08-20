/**
 * Parent_Selector_Input
 *
 * Composite task-parent picker. See parent_selector_input.jqhtml for the contract.
 * Extends Form_Input_Abstract directly (its own val() = {type, id}); it owns a type
 * <select> + a nested Ajax_Entity_Select_Input (private - Rsx_Form's shallowFind
 * stops at this outer input and never descends into the child).
 */
class Parent_Selector_Input extends Form_Input_Abstract {
    // -- Value ---------------------------------------------------------------

    _get_value() {
        const type = this._type();
        if (!type) return { type: null, id: null };
        const entity = this.sid('entity');
        const raw = entity ? entity.val() : '';
        return { type, id: raw ? int(raw) : null };
    }

    _set_value(value) {
        const val = value || {};
        const type = val.type || '';
        const id = (val.id === 0 || val.id) ? val.id : null;

        this.$sid('type').val(type || 'None');

        const entity = this.sid('entity');
        entity.set_type(type || 'Project_Model');
        entity.set_exclude(type === 'Task_Model' ? this._exclude_id() : null);
        entity.val(type && id ? id : '');

        this._toggle_entity(!!type);
        this._sync_hidden(type, type && id ? id : '');
    }

    // -- Lifecycle -----------------------------------------------------------

    on_ready() {
        this._mark_ready();

        const that = this;

        // Type change: re-point the entity search, clear the selection, re-emit.
        this.$sid('type').on('change', function () {
            const type = that._type();
            const entity = that.sid('entity');
            entity.set_type(type || 'Project_Model');
            entity.set_exclude(type === 'Task_Model' ? that._exclude_id() : null);
            entity.val('');
            that._toggle_entity(!!type);
            that._sync_hidden(type, '');
            that._emit();
        });

        // Entity change: sync the hidden id and re-emit.
        this.sid('entity').on('input', function (comp, id) {
            that._sync_hidden(that._type(), id || '');
            that._emit();
        });
    }

    // -- Helpers -------------------------------------------------------------

    _type() {
        const v = this.$sid('type').val();
        return (!v || v === 'None') ? '' : v;
    }

    _exclude_id() {
        return this.args.exclude_id ? int(this.args.exclude_id) : null;
    }

    _toggle_entity(show) {
        this.$sid('entity_wrap').toggle(!!show);
    }

    _sync_hidden(type, id) {
        this.$sid('h_type').val(type || '');
        this.$sid('h_id').val(id || '');
    }

    _emit() {
        const v = this._get_value();
        this.trigger('input', v);
        this.trigger('val', v);
    }
}
