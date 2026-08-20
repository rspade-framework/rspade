/**
 * Settings_Layout - Sublayout for settings pages
 *
 * Provides the settings sidebar navigation that persists across all settings pages.
 * Used as a sublayout inside Frontend_Spa_Layout.
 *
 * Page titles and breadcrumbs are handled by the parent Frontend_Spa_Layout
 * using each action's page_title() and breadcrumb_*() methods.
 *
 * Usage:
 *   @layout('Frontend_Spa_Layout')
 *   @layout('Settings_Layout')
 *   class Settings_Profile_Display_Action extends Spa_Action { }
 */
class Settings_Layout extends Spa_Layout {
    // Map action names to sidebar nav identifiers
    static NAV_CONFIG = {
        Settings_Profile_Display_Action: 'profile_display',
        Settings_Profile_Edit_Action: 'profile_edit',
        Settings_User_Settings_Action: 'user_settings',
        Settings_Password_Security_Action: 'password_security',
        Settings_Api_Keys_Action: 'api_keys',
        Settings_User_Management_Index_Action: 'user_management',
        Settings_User_Management_View_Action: 'user_management',
        Settings_Group_Management_Index_Action: 'group_management',
        Settings_Group_Management_View_Action: 'group_management',
        Settings_Portal_Users_Index_Action: 'portal_users',
        Settings_Site_Settings_Action: 'site_settings',
    };

    on_create() {
        this.state = {
            active_page: null,
        };
    }

    async on_action(url, action_name, args) {
        // Look up nav identifier for this action
        this.state.active_page = Settings_Layout.NAV_CONFIG[action_name] || null;

        // Update sidebar active state
        this._update_active_nav();

        // Scaffolded reconciliation (semantic view-page refactor, Batch G).
        // A settings action that composes with Page_Scaffold declares
        // `scaffolded = true`. Stamp the content pane so the scaffold owns its
        // own outer padding (the `.settings-layout` grid still frames the pane);
        // the persistent pane is reset per navigation, so an unconverted
        // settings page restores the default. Mirrors Frontend_Spa_Layout.
        this.$content().toggleClass('settings-content--scaffolded', !!this.action.scaffolded);
    }

    _update_active_nav() {
        // Remove active class from all sidebar items
        this.$.find('.settings-sidebar__item').removeClass('active');

        // Add active class to current page
        if (this.state.active_page) {
            this.$.find(`[data-page="${this.state.active_page}"]`).addClass('active');
        }
    }
}
