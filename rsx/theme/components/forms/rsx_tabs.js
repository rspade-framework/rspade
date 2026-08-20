/**
 * Rsx_Tabs
 *
 * Tab container component with form-aware error handling integration.
 * See rsx_tabs.jqhtml for full documentation.
 *
 * JavaScript Responsibilities:
 * - Builds tab navigation dynamically from registered Rsx_Tab children
 * - Manages tab activation and switching behavior
 * - Persists active tab to URL hash for bookmarking
 * - Integrates with form validation to show error badges on tabs
 * - Auto-switches to first tab with errors on validation failure
 * - Provides API for parent forms to report validation errors
 */
class Rsx_Tabs extends Component {
    on_create() {
        this.tabs = []; // Registered Rsx_Tab components
        this.active_tab_id = null;
        this.form = null;
    }

    on_ready() {
        // Find parent form if it exists
        this.form = this.closest('.Rsx_Form');

        // Build tab navigation from registered tabs
        this._build_nav();

        // Restore active tab from URL hash or activate first tab
        const saved_tab = Rsx.url_hash_get('tab');
        if (saved_tab) {
            this.activate_tab(saved_tab);
        } else if (this.tabs.length > 0) {
            this.activate_tab(this.tabs[0].args.id);
        }

        // Persist active tab to URL hash
        this.$sid('nav').on('click', 'a[data-bs-toggle="tab"]', (e) => {
            const tab_id = $(e.currentTarget).data('tab-id');
            Rsx.url_hash_set_single('tab', tab_id);
        });
    }

    register_tab(tab_component) {
        this.tabs.push(tab_component);
    }

    _build_nav() {
        const $nav = this.$sid('nav');
        $nav.empty();

        for (let i = 0; i < this.tabs.length; i++) {
            const tab = this.tabs[i];
            const is_active = i === 0 ? 'active' : '';

            const $li = $(`
                <li class="nav-item" role="presentation">
                    <a class="nav-link ${is_active}"
                       data-bs-toggle="tab"
                       href="#${tab.args.id}"
                       data-tab-id="${tab.args.id}"
                       aria-selected="${i === 0 ? 'true' : 'false'}"
                       role="tab">
                        ${tab.args.icon ? `<i class="${tab.args.icon}"></i> ` : ''}
                        ${tab.args.label}
                        <span class="badge bg-danger ms-2" style="display: none;" data-error-badge="${tab.args.id}">0</span>
                    </a>
                </li>
            `);

            $nav.append($li);
        }
    }

    activate_tab(tab_id) {
        // Find the tab
        const tab = this.tabs.find((t) => t.args.id === tab_id);
        if (!tab) return;

        // Remove active show from all tab panes
        for (let t of this.tabs) {
            t.$.removeClass('active show');
        }

        // Add active show to the selected tab pane
        tab.$.addClass('active show');

        // Update Bootstrap tab navigation
        this.$sid('nav').find('a[data-bs-toggle="tab"]').removeClass('active').attr('aria-selected', 'false');
        this.$sid('nav')
            .find('a[data-tab-id="' + tab_id + '"]')
            .addClass('active')
            .attr('aria-selected', 'true');

        this.active_tab_id = tab_id;
    }

    handle_validation_errors(errors) {
        // Count errors per tab
        const tab_errors = {};

        for (let tab of this.tabs) {
            const error_count = tab.count_errors(errors);
            tab_errors[tab.args.id] = error_count;

            // Update badge
            const $badge = this.$sid('nav').find(`[data-error-badge="${tab.args.id}"]`);
            if (error_count > 0) {
                $badge.text(error_count).show();
            } else {
                $badge.hide();
            }
        }

        // Find first tab with errors
        const first_errored_tab = this.tabs.find((t) => tab_errors[t.args.id] > 0);

        // Switch to first errored tab if not currently on an errored tab
        if (first_errored_tab && tab_errors[this.active_tab_id] === 0) {
            this.activate_tab(first_errored_tab.args.id);
        }
    }

    clear_error_badges() {
        this.$sid('nav').find('[data-error-badge]').hide();
    }
}
