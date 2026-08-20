/**
 * Image_Viewer - see Image_Viewer.jqhtml for the contract.
 *
 * Binds the load handler BEFORE setting src so an already-cached image still reports its natural
 * dimensions (the img.complete fast path covers the same-tick cache case).
 */
class Image_Viewer extends Component {
    on_ready() {
        const that = this;
        const $img = this.$sid('img');
        const img = $img[0];

        const emit_loaded = () => {
            const w = img.naturalWidth || 0;
            const h = img.naturalHeight || 0;
            that.trigger('preview_loaded', {
                pages: 1,
                width: w,
                height: h,
                ratio: w ? (h / w) : 0,
            });
        };

        if (img.complete && img.naturalWidth) {
            emit_loaded();
        } else {
            $img.off('load.image_viewer').on('load.image_viewer', emit_loaded);
            $img.off('error.image_viewer').on('error.image_viewer', () => {
                console_debug('preview', 'Image_Viewer: image failed to load', that.args.url);
                that.trigger('preview_loaded', { pages: 1, width: 0, height: 0, ratio: 0 });
            });
        }

        $img.attr('src', this.args.url);
    }

    set_page() { /* single page - no-op */ }

    get_page() {
        return 1;
    }

    get_pages() {
        return 1;
    }
}
