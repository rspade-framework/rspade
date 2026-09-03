/**
 * Clients_View_Sidebar - the Clients_View entity sidebar's behaviour.
 *
 * The markup and its handlers live together: the region owns every button it
 * renders, so the page shell never reaches into this DOM by $sid(). Actions that
 * MUTATE the client fire 'client_changed' and let the owner decide how to repaint
 * (it reloads), which keeps the one fetch of the record in one place.
 *
 * The Action_Menu items and the record buttons live inside child components
 * (Action_Menu / Detail_Sidebar), so they are bound in on_ready() - on_render()
 * fires before children are ready.
 */
class Clients_View_Sidebar extends Component {
    on_ready() {
        // Namespaced and idempotent: this.$ survives every render() while on_ready()
        // re-fires on each one, so one .off('.cvs') clears this component's prior
        // binds before they are re-attached. No one-shot instance flag: flags die
        // with the instance, handlers live on the element.
        this.$.off('.cvs');

        const client_id = this.args.client.id;

        this.$sid('enable-portal').on('click.cvs', async () => {
            await Frontend_Clients_Controller.toggle_portal({id: client_id, enabled: true});
            this.trigger('client_changed');
        });

        this.$sid('disable-portal').on('click.cvs', async () => {
            const confirmed = await Modal.confirm('Disable Portal', 'Are you sure you want to disable the portal for this client?\n\nExisting portal members will lose access.', 'Disable', 'Cancel');
            if (!confirmed) return;
            await Frontend_Clients_Controller.toggle_portal({id: client_id, enabled: false});
            this.trigger('client_changed');
        });

        this.$sid('delete-client').on('click.cvs', async () => {
            const confirmed = await Modal.confirm('Delete Client', 'Are you sure you want to delete this client?\n\nThis can be undone by restoring the client afterward.', 'Delete', 'Cancel');
            if (!confirmed) return;
            await Frontend_Clients_Controller.delete({id: client_id});
            Spa.dispatch(Rsx.Route('Clients_Index_Action'));
        });

        this.$sid('restore-client').on('click.cvs', async () => {
            await Frontend_Clients_Controller.restore({id: client_id});
            this.trigger('client_changed');
        });
    }
}
