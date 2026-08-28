/**
 * Repeater_Simple
 *
 * A form input component for managing lists of simple values. Works with any
 * simple input component (dropdowns, text inputs, pickers) for adding items.
 *
 * IMPORTANT: This component is designed for SIMPLE form inputs only:
 * - Select dropdowns (Select_Input, Select_Ajax_Input)
 * - Text inputs (Text_Input)
 * - Simple pickers (contact pickers, user pickers)
 *
 * NOT suitable for complex inputs like:
 * - WYSIWYG editors
 * - File upload components
 * - Multi-field composite inputs
 * - Components with complex internal state
 *
 * For complex inputs, build a custom repeater or use a different pattern.
 *
 * Args:
 * - edit_input: Component class name for adding new items (required)
 * - display_input: Component class name for showing existing items (optional, defaults to edit_input)
 * - edit_input_args: Additional args to pass to edit_input instances
 * - display_input_args: Additional args to pass to display_input instances
 * - add_label: Label for add button (default: "Add")
 * - empty_message: Message when list is empty (default: "No items added")
 * - confirm_remove: Whether to confirm before removing (default: true)
 * - confirm_title: Title for remove confirmation (default: "Remove Item")
 * - confirm_message: Message for remove confirmation (default: "Are you sure?")
 * - duplicate_message: Message shown when attempting to add a duplicate (default: "This item has already been added.")
 *
 * val() Pattern:
 * - val() returns array of values: [3, 5, 15] or [{user_id: 1, role_id: 2}, ...]
 * - val(array) sets items, creating display_input for each value
 *
 * Edit Input Contract:
 * - A Form_Input_Abstract, so it announces user changes with _notify_input(), which
 *   raises 'input'. That is what this repeater listens for - 'input' means the USER
 *   picked something, where 'val' would also fire on the programmatic seed.
 * - val() getter (guaranteed by Form_Input_Abstract)
 *
 * Display Input Contract:
 * - Receives value via $value arg
 * - val() returns the original value unchanged
 * - Can fetch display info (name, badge) in on_load()
 */
class Repeater_Simple_Input extends Form_Input_Abstract {
    on_create() {
        super.on_create();

        this.args.confirm_remove = this.args.confirm_remove !== false;
        this.args.confirm_title = this.args.confirm_title || 'Remove Item';
        this.args.confirm_message = this.args.confirm_message || 'Are you sure you want to remove this item?';
        this.args.duplicate_message = this.args.duplicate_message || 'This item has already been added.';

        // Internal state
        this.state = {
            items: [],
            adding: false,
        };
    }

    _get_value() {
        const result = [];
        this.$sid('list')
            .find('.Repeater_Simple_Input__item-content')
            .each((i, el) => {
                const $child = $(el).children().first();
                const component = $child.component();
                if (component && typeof component.val === 'function') {
                    result.push(component.val());
                }
            });
        return result;
    }

    _set_value(values) {
        this.state.items = values || [];
        this.state.adding = false;
        this._render_list();
    }

    on_ready() {
        this._mark_ready();

        // Remove button handler (delegated)
        this.$sid('list').on('click', '.Repeater_Simple_Input__remove', async (e) => {
            e.preventDefault();
            const $btn = $(e.currentTarget);
            const index = int($btn.data('index'));

            if (this.args.confirm_remove) {
                const confirmed = await Modal.confirm(this.args.confirm_title, this.args.confirm_message);
                if (!confirmed) return;
            }

            this._remove_item(index);
        });

        // Add button handler
        this.$sid('add_btn').on('click', () => {
            this._show_pending();
        });

        // Cancel button handler
        this.$sid('cancel_btn').on('click', () => {
            this._hide_pending();
        });
    }

    /**
     * Show the pending edit row
     */
    async _show_pending() {
        this.state.adding = true;
        this.$sid('pending').show();
        this.$sid('empty').hide();

        // Create edit input component inside a wrapper div
        const edit_component_name = this.args.edit_input;
        const edit_args = this.args.edit_input_args || {};

        const $wrapper = $('<div>').appendTo(this.$sid('pending_content'));
        let $edit_el = $wrapper.component(edit_component_name, edit_args);
        let edit_component = $edit_el.component();

        // Wait for component to be ready, then listen for change
        await edit_component.ready();

        // If edit component supports exclusion, pass already-selected values
        if (typeof edit_component.exclude === 'function') {
            edit_component.exclude(this.state.items);
        }

        edit_component.on('input', (comp, value) => {
            if (value !== null && value !== undefined && value !== '') {
                this._add_item(value);
            }
        });
    }

    /**
     * Hide the pending edit row
     */
    _hide_pending() {
        this.state.adding = false;
        this.$sid('pending').hide();
        this.$sid('pending_content').empty();

        // Show empty message if no items
        if (this.state.items.length === 0) {
            this.$sid('empty').show();
        }
    }

    /**
     * Check if two values are equal (handles objects/arrays via JSON comparison)
     */
    _values_equal(a, b) {
        if (typeof a === 'string' || typeof a === 'number') {
            return a === b;
        }
        return json_encode(a) === json_encode(b);
    }

    /**
     * Check if value already exists in items
     */
    _is_duplicate(value) {
        return this.state.items.some(item => this._values_equal(item, value));
    }

    /**
     * Add an item
     */
    async _add_item(value) {
        if (this._is_duplicate(value)) {
            await Modal.alert(this.args.duplicate_message);
            return;
        }

        const index = this.state.items.length;
        this.state.items.push(value);
        this._hide_pending();
        this.$sid('empty').hide();
        this._append_item(value, index);
        this._notify_input(this.val());
    }

    /**
     * Remove an item by index
     */
    _remove_item(index) {
        // Remove from state
        this.state.items.splice(index, 1);

        // Remove the DOM element
        this.$sid('list').find(`.Repeater_Simple_Input__item[data-index="${index}"]`).remove();

        // Reindex remaining items
        this.$sid('list').find('.Repeater_Simple_Input__item').each((i, el) => {
            $(el).attr('data-index', i);
            $(el).find('.Repeater_Simple_Input__remove').attr('data-index', i);
        });

        // Show empty message if no items left
        if (this.state.items.length === 0) {
            this.$sid('empty').show();
        }

        this._notify_input(this.val());
    }

    /**
     * Append a single item to the list
     */
    _append_item(value, index) {
        const display_component_name = this.args.display_input || this.args.edit_input;
        const display_args = this.args.display_input_args || {};

        const $item = $(`
            <div class="Repeater_Simple__item" data-index="${index}">
                <div class="Repeater_Simple__item-content"></div>
                <button type="button" class="Repeater_Simple__remove" data-index="${index}" title="Remove">
                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M18 6 6 18"></path>
                        <path d="m6 6 12 12"></path>
                    </svg>
                </button>
            </div>
        `);

        this.$sid('list').append($item);

        // Create display input component with the value
        const $wrapper = $('<div>').appendTo($item.find('.Repeater_Simple_Input__item-content'));
        $wrapper.component(display_component_name, {
            ...display_args,
            value: value,
        });
    }

    /**
     * Render the full list of display inputs (used by val() setter)
     */
    _render_list() {
        const $list = this.$sid('list');
        $list.empty();

        if (this.state.items.length === 0) {
            this.$sid('empty').show();
            return;
        }

        this.$sid('empty').hide();

        this.state.items.forEach((value, index) => {
            this._append_item(value, index);
        });
    }
}
