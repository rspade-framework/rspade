/**
 * Button_Utils - Busy-state helper for buttons and other clickable elements
 *
 * Puts an element into a "submitting" state: locks its current rendered size,
 * swaps its inner content for a CSS-animated loader, and blocks interaction via
 * the `btn--submitting` class (pointer-events: none). Restores the original
 * content and size when cleared.
 *
 * Deliberately does NOT set the `disabled` attribute. Disabling a button
 * changes form-submission semantics and native styling; interaction is blocked
 * purely through pointer-events on the `btn--submitting` class instead.
 *
 * The loader is styled in Button_Utils.scss and is themeable via CSS custom
 * properties (--btn-submitting-loader-color/-size/-thickness/-speed) so themes
 * can restyle it without touching core.
 *
 * Primary consumer: $.fn.click_async() (see Rsx_Jq_Helpers.js), which wraps a
 * click handler in an automatic set/clear cycle. It can also be driven directly.
 *
 * Usage:
 *   Button_Utils.set_submitting($btn);
 *   Button_Utils.clear_submitting($btn);
 *   if (Button_Utils.is_submitting($btn)) { ... }
 */
class Button_Utils {
    // State marker class - also carries the pointer-events block (see .scss)
    static SUBMITTING_CLASS = 'btn--submitting';

    // jQuery .data() keys used to stash original state during a busy cycle.
    // Stored in jQuery's internal cache (not written to the DOM), so an object
    // value is safe and there are no serialization concerns.
    static _DATA_HTML = 'button_utils_original_html';
    static _DATA_STYLE = 'button_utils_original_style';

    /**
     * Returns true if the (first matched) element is currently submitting.
     */
    static is_submitting($el) {
        return $el.first().hasClass(Button_Utils.SUBMITTING_CLASS);
    }

    /**
     * Enter the busy state: lock the rendered size, stash inner HTML, show the
     * loader. Idempotent - a no-op on an element already submitting.
     */
    static set_submitting($el) {
        $el.each((index, el) => {
            const $btn = $(el);
            if ($btn.hasClass(Button_Utils.SUBMITTING_CLASS)) {
                return; // already submitting - idempotent no-op
            }

            // Lock the current rendered box so swapping content cannot reflow it.
            // getBoundingClientRect() reports the border-box size; pinning
            // box-sizing to border-box makes the inline width/height reproduce
            // that box exactly regardless of the element's own box-sizing.
            const rect = el.getBoundingClientRect();

            // Stash the original inline size props so clear can restore them
            // exactly - an element may already carry its own inline width/height.
            $btn.data(Button_Utils._DATA_STYLE, {
                width: el.style.width,
                height: el.style.height,
                box_sizing: el.style.boxSizing,
            });

            // Stash the original content
            $btn.data(Button_Utils._DATA_HTML, $btn.html());

            el.style.width = rect.width + 'px';
            el.style.height = rect.height + 'px';
            el.style.boxSizing = 'border-box';

            $btn.addClass(Button_Utils.SUBMITTING_CLASS);
            $btn.html('<span class="Button_Utils__spinner" aria-hidden="true"></span>');
        });
        return $el;
    }

    /**
     * Leave the busy state: restore inner HTML, remove the class, unlock the
     * size. No-op when the element is not submitting.
     */
    static clear_submitting($el) {
        $el.each((index, el) => {
            const $btn = $(el);
            if (!$btn.hasClass(Button_Utils.SUBMITTING_CLASS)) {
                return; // not submitting - nothing to restore
            }

            const original_html = $btn.data(Button_Utils._DATA_HTML);
            const original_style = $btn.data(Button_Utils._DATA_STYLE) || {};

            $btn.removeClass(Button_Utils.SUBMITTING_CLASS);

            if (typeof original_html === 'string') {
                $btn.html(original_html);
            }

            // Restore original inline size props (empty string removes the prop)
            el.style.width = original_style.width || '';
            el.style.height = original_style.height || '';
            el.style.boxSizing = original_style.box_sizing || '';

            $btn.removeData(Button_Utils._DATA_HTML);
            $btn.removeData(Button_Utils._DATA_STYLE);
        });
        return $el;
    }
}
