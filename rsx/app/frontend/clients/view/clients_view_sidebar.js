/**
 * Clients_View_Sidebar - the Clients_View entity sidebar's behaviour.
 *
 * The markup and its handlers live together: the region owns every button it
 * renders, so the page shell never reaches into this DOM by $sid(). Actions that
 * MUTATE the client fire 'client_changed' and let the owner decide how to repaint
 * (it reloads), which keeps the one fetch of the record in one place.
 *
 * The buttons are this region's own markup - written in its template, even where
 * they render inside the template-only Detail_Sidebar / Action_Menu - so they are
 * bound in on_render(), which runs on the cached paint: the copy on screen responds
 * at once instead of waiting for the page's load to finish.
 */
class Clients_View_Sidebar extends Component {
    on_render() {
        // Namespaced and idempotent: on_render() re-fires on every render, so each
        // bind is .off('.cvs') before .on('.cvs'). No one-shot instance flag: flags
        // die with the instance, handlers live on the element.
        const client_id = this.args.client.id;

        this.$sid('enable-portal').off('click.cvs').on('click.cvs', async () => {
            await this.alive(Frontend_Clients_Controller.toggle_portal({id: client_id, enabled: true}));
            this.trigger('client_changed');
        });

        this.$sid('disable-portal').off('click.cvs').on('click.cvs', async () => {
            const confirmed = await Modal.confirm('Disable Portal', 'Are you sure you want to disable the portal for this client?\n\nExisting portal members will lose access.', 'Disable', 'Cancel');
            if (!confirmed) return;
            await this.alive(Frontend_Clients_Controller.toggle_portal({id: client_id, enabled: false}));
            this.trigger('client_changed');
        });

        this.$sid('delete-client').off('click.cvs').on('click.cvs', async () => {
            const confirmed = await Modal.confirm('Delete Client', 'Are you sure you want to delete this client?\n\nThis can be undone by restoring the client afterward.', 'Delete', 'Cancel');
            if (!confirmed) return;
            await this.alive(Frontend_Clients_Controller.delete({id: client_id}));
            Spa.dispatch(Rsx.Route('Clients_Index_Action'));
        });

        this.$sid('restore-client').off('click.cvs').on('click.cvs', async () => {
            await this.alive(Frontend_Clients_Controller.restore({id: client_id}));
            this.trigger('client_changed');
        });
    }
}
