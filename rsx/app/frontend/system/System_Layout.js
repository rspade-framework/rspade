/**
 * System_Layout - Sublayout for system administration pages
 *
 * Root admin area for system-level configuration and monitoring.
 * Separate from Settings (user/site-level) — this is infrastructure.
 *
 * Usage:
 *   @layout('Frontend_Spa_Layout')
 *   @layout('System_Layout')
 *   class System_Status_Action extends Spa_Action { }
 */
class System_Layout extends Spa_Layout {
    static NAV_CONFIG = {
        System_Status_Action: 'status',
        System_Tasks_Action: 'tasks',
        System_Email_Config_Action: 'email_config',
        System_Email_Queue_Action: 'email_queue',
        System_Email_View_Action: 'email_queue',
        System_Email_Recipients_Action: 'email_recipients',
    };

    on_create() {
        this.state = {
            active_page: null,
        };
    }

    async on_action(url, action_name, args) {
        this.state.active_page = System_Layout.NAV_CONFIG[action_name] || null;
        this._update_active_nav();

        // Scaffolded reconciliation (semantic view-page refactor, Batch H).
        // A system action that composes with Page_Scaffold declares
        // `scaffolded = true`. Stamp the content pane so the nested scaffold owns
        // its own outer padding (the `.system-layout` grid still frames the pane);
        // the persistent pane is reset per navigation, so an unconverted system
        // page restores the default. Mirrors Settings_Layout (Batch G).
        this.$content().toggleClass('system-content--scaffolded', !!this.action.scaffolded);
    }

    _update_active_nav() {
        this.$.find('.system-sidebar__item').removeClass('active');
        if (this.state.active_page) {
            this.$.find(`[data-page="${this.state.active_page}"]`).addClass('active');
        }
    }
}
