/**
 * Party_DataGrid - type quick filter, and row delete clicks (confirm -> delete -> reload).
 */
class Party_DataGrid extends DataGrid_Abstract {
    static allowed_filters = ['type_id'];

    static record_noun_plural = 'parties';

    on_ready() {
        super.on_ready();

        // Delegated handlers, NAMESPACED AND IDEMPOTENT: this.$ survives every
        // render() while on_ready() re-fires on each one, so one .off('.pdg') here
        // clears this component's prior binds before they are re-attached. A one-shot
        // instance flag would be wrong in both directions - flags die with the
        // instance, handlers live on the element.
        this.$.off('.pdg');

        this._bind_quick_filter('type_filter', 'type_id');

        this.$.on('click.pdg', '[data-action="delete"]', async (e) => {
            e.preventDefault();
            e.stopPropagation();

            const id = $(e.currentTarget).data('id');

            const confirmed = await Modal.confirm(
                'Delete Party',
                'Delete this party?\n\nThis also removes its type-specific detail.',
                'Delete'
            );

            if (!confirmed) {
                return;
            }

            try {
                await Frontend_Party_Controller.delete({ id });
                this.reload();
            } catch (error) {
                Modal.alert('Error', error.message || 'Failed to delete party');
            }
        });
    }

    /**
     * Point one header <select> at one whitelisted filter key.
     *
     * The grid's state is authoritative in both directions of the boot: default_filters and
     * the URL-hash restore have both already run in on_create(), so the widget is set FROM
     * the state - never the other way round, and never from a `selected` attribute in markup.
     *
     * @param {string} sid - $sid of the <select> in the card header
     * @param {string} key - filter key, must be in allowed_filters
     */
    _bind_quick_filter(sid, key) {
        let that = this;

        const $select = that.$sid(sid);

        $select.val(str(that.get_custom_filter(key) ?? ''));

        $select.on('change', function () {
            const $element = $(this);
            that.set_custom_filter(key, $element.val() || null);
        });
    }
}
