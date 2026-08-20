/**
 * People_List - a calm vertical list of people (avatar + name).
 *
 * The shared people-list display primitive (see people_list.jqhtml). Rows are
 * clickable when $clickable and/or carry a remove affordance when $removable; both
 * hand the exact person object (and its index) back to the owning action via a custom
 * event, so the action never re-derives which row was clicked.
 */
class People_List extends Component {
    on_render() {
        // Idempotent delegated binds (on_render may re-fire, rows are rebuilt each render).
        this.$.off('click.peoplelist').on('click.peoplelist', '.People_List__row', (e) => {
            // A remove button click is handled by its own binding, never as a row click.
            if ($(e.target).closest('.People_List__remove').length) return;
            if (!this.args.clickable) return;
            const index = int($(e.currentTarget).data('person-index'));
            const person = (this.args.people || [])[index];
            if (person) this.trigger('person_click', { person, index });
        });

        this.$.off('click.peoplelistrm').on('click.peoplelistrm', '.People_List__remove', (e) => {
            e.stopPropagation();
            const index = int($(e.currentTarget).data('person-remove'));
            const person = (this.args.people || [])[index];
            if (person) this.trigger('person_remove', { person, index });
        });
    }
}
