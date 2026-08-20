/**
 * Rsx_Droppable - Global File Drop Handler
 *
 * Intercepts all file drag/drop operations and routes them to components
 * marked with the `rsx-droppable` class. Components receive a `file-drop`
 * event with the dropped files.
 *
 * CSS Classes:
 * - `rsx-droppable` - Marks element as a valid drop target
 * - `rsx-drop-active` - Added to all droppables during file drag
 * - `rsx-drop-target` - Added to the specific element that will receive drop
 *
 * Behavior:
 * - Single visible droppable: auto-becomes target
 * - Multiple visible droppables: must hover over specific element
 * - No-drop cursor when not over valid target
 * - Widgets handle their own file type filtering
 *
 * @internal Framework use only - not part of public API
 */
class Rsx_Droppable {
    static _on_framework_core_init() {
        if (Rsx.is_ssr()) return;
        Rsx_Droppable._init();
    }

    static _init() {
        let drag_counter = 0;
        let current_target = null;

        // Get all visible droppable elements
        const get_visible_droppables = () => {
            return $('.rsx-droppable').filter(function() {
                const $el = $(this);
                // Must be visible and not hidden by CSS
                return $el.is(':visible') && $el.css('visibility') !== 'hidden';
            });
        };

        // Check if event contains files
        const has_files = (e) => {
            if (e.originalEvent && e.originalEvent.dataTransfer) {
                const types = e.originalEvent.dataTransfer.types;
                return types && (types.includes('Files') || types.indexOf('Files') >= 0);
            }
            return false;
        };

        // Update which element is the current target
        const update_target = ($new_target) => {
            if (current_target) {
                $(current_target).removeClass('rsx-drop-target');
            }
            current_target = $new_target ? $new_target[0] : null;
            if (current_target) {
                $(current_target).addClass('rsx-drop-target');
            }
        };

        // Clear all drag state
        const clear_drag_state = () => {
            drag_counter = 0;
            $('.rsx-drop-active').removeClass('rsx-drop-active');
            update_target(null);
        };

        // dragenter - file enters the window
        $(document).on('dragenter', function(e) {
            if (!has_files(e)) return;

            drag_counter++;

            if (drag_counter === 1) {
                // First entry - activate all droppables
                const $droppables = get_visible_droppables();
                $droppables.addClass('rsx-drop-active');

                // If only one droppable, auto-target it
                if ($droppables.length === 1) {
                    update_target($droppables.first());
                }
            }
        });

        // dragleave - file leaves an element
        $(document).on('dragleave', function(e) {
            if (!has_files(e)) return;

            drag_counter--;

            if (drag_counter <= 0) {
                clear_drag_state();
            }
        });

        // dragover - file is over an element (fires continuously)
        $(document).on('dragover', function(e) {
            if (!has_files(e)) return;

            e.preventDefault(); // Required to allow drop

            const $droppables = get_visible_droppables();

            if ($droppables.length === 0) {
                // No drop targets - show no-drop cursor
                e.originalEvent.dataTransfer.dropEffect = 'none';
                return;
            }

            if ($droppables.length === 1) {
                // Single target - already set, allow copy
                e.originalEvent.dataTransfer.dropEffect = 'copy';
                return;
            }

            // Multiple targets - find if we're over one
            const $hovered = $(e.target).closest('.rsx-droppable');

            if ($hovered.length && $hovered.hasClass('rsx-drop-active')) {
                // Over a valid droppable
                update_target($hovered);
                e.originalEvent.dataTransfer.dropEffect = 'copy';
            } else {
                // Not over any droppable - show no-drop cursor
                update_target(null);
                e.originalEvent.dataTransfer.dropEffect = 'none';
            }
        });

        // drop - file is dropped
        $(document).on('drop', function(e) {
            if (!has_files(e)) return;

            e.preventDefault();
            e.stopPropagation();

            const files = e.originalEvent.dataTransfer.files;

            if (current_target && files.length > 0) {
                // Trigger file-drop event on the component
                const $target = $(current_target);
                const component = $target.component();

                if (component) {
                    component.trigger('file-drop', {
                        files: files,
                        dataTransfer: e.originalEvent.dataTransfer,
                        originalEvent: e.originalEvent
                    });
                } else {
                    // No component - trigger jQuery event on element directly
                    $target.trigger('file-drop', {
                        files: files,
                        dataTransfer: e.originalEvent.dataTransfer,
                        originalEvent: e.originalEvent
                    });
                }
            }

            clear_drag_state();
        });

        // Handle drag end (e.g., user presses Escape)
        $(document).on('dragend', function(e) {
            clear_drag_state();
        });
    }
}
