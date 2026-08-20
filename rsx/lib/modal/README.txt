MODAL ANIMATION CUSTOMIZATION
=============================

By default, RSpade modals appear and close instantly with no animation.
This provides immediate feedback and professional UX.

If you want to add animations (fade, slide-in, etc.), follow this guide.


OPENING ANIMATIONS
==================

The modal show logic is in rsx_modal.js in the _fade_in() method.

Example: Fade In Effect (250ms)
--------------------------------

Replace the _fade_in() method with:

    async _fade_in() {
        // Start hidden
        this.$.css('opacity', '0').css('display', 'flex');
        this.$id('backdrop').css('display', 'block');

        // Wait for next frame to ensure styles are applied
        await sleep(0);

        // Trigger fade-in via opacity transition
        this.$.css('opacity', '1');
        this.$id('modal').addClass('show').css('opacity', '1');
        this.$id('backdrop').addClass('show');

        // Wait for animation to complete
        return new Promise((resolve) => {
            setTimeout(() => resolve(), 250);
        });
    }

Then add CSS transition in rsx_modal.scss:

    .rsx-modal {
        transition: opacity 250ms ease;
    }

    .modal-backdrop {
        transition: opacity 250ms ease;
    }


Example: Slide Down From Top (300ms)
-------------------------------------

Replace the _fade_in() method with:

    async _fade_in() {
        // Start hidden above viewport
        this.$.css({
            'display': 'flex',
            'opacity': '0',
            'transform': 'translateY(-50px)'
        });
        this.$id('backdrop').css('display', 'block');

        // Wait for next frame
        await sleep(0);

        // Trigger slide and fade
        this.$.css({
            'opacity': '1',
            'transform': 'translateY(0)'
        });
        this.$id('modal').addClass('show');
        this.$id('backdrop').addClass('show');

        // Wait for animation to complete
        return new Promise((resolve) => {
            setTimeout(() => resolve(), 300);
        });
    }

Then add CSS transition in rsx_modal.scss:

    .rsx-modal {
        transition: opacity 300ms ease, transform 300ms ease;
    }


CLOSING ANIMATIONS
==================

The modal close logic is in rsx_modal.js in the close() method.

Currently closes instantly. To add fade-out:

Replace the close() method with:

    async close(result) {
        // Mark as not visible
        this.data.is_visible = false;

        // Remove event listeners
        $(document).off('keydown.rsx_modal_' + this._cid);
        $(window).off('resize.rsx_modal_' + this._cid);

        // Fade out
        this.$.css('opacity', '0');
        this.$id('backdrop').removeClass('show');

        // Wait for fade-out to complete
        await sleep(250);

        // Hide and remove from DOM
        this.$.hide().remove();
        this.$id('backdrop').hide().remove();

        // Resolve promise
        if (this.data.resolve_fn) {
            this.data.resolve_fn(result);
            this.data.resolve_fn = null;
        }
    }


BACKDROP ANIMATIONS
===================

Backdrop show/hide is in Modal.js in _show_backdrop() and _hide_backdrop().

To animate backdrop separately:

In Modal.js, update _show_backdrop():

    static async _show_backdrop() {
        if (!this._backdrop.hasClass('show')) {
            this._lock_body_scroll();

            this._backdrop.css('display', 'block');
            await sleep(0);
            this._backdrop.addClass('show');
            await sleep(250); // Wait for fade
        }
    }

And update _hide_backdrop():

    static async _hide_backdrop() {
        this._backdrop.removeClass('show');
        await sleep(250); // Wait for fade
        this._backdrop.css('display', 'none');
        this._unlock_body_scroll();
    }

The backdrop already has CSS transitions defined in rsx_modal.scss:

    .modal-backdrop.fade {
        opacity: 0;
        transition: opacity 250ms;

        &.show {
            opacity: 1;
        }
    }


FOCUS DELAY
===========

Input auto-focus happens in _focus_first_input().

Currently uses requestAnimationFrame for immediate focus.

If you add animations, increase the delay to match animation duration:

    _focus_first_input() {
        const $first_input = this.$id('body').find('input:not([type="hidden"]), textarea, select').first();
        if ($first_input.exists()) {
            setTimeout(() => {
                $first_input.focus();
                if ($first_input.is('input[type="text"], input[type="email"]') && $first_input.val()) {
                    $first_input.select();
                }
            }, 300); // Match animation duration
        }
    }


SEQUENTIAL MODAL DELAY
======================

When multiple modals are queued, there's a delay between them in Modal.js
in the _process_queue() method.

Currently: No delay (immediate transition)

To add delay between modals:

    // In _process_queue(), around line 224:
    if (!backdrop_visible) {
        await this._show_backdrop();
    } else {
        // Add delay between modals
        await sleep(500);
    }


NOTES
=====

- Always use async/await with sleep() for timing
- Match animation durations in JS and CSS
- Test with sequential modals (multiple alerts in a row)
- Remember to update _unlock_body_scroll() timeout in Modal.js
  to match your animation duration
