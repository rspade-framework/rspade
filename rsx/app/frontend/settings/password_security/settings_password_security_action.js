/**
 * Settings Password Security Action
 *
 * Password change, 2FA, and session management.
 * No three-state loading - using static mock data.
 */
@route('/frontend/settings/password_security')
@layout('Frontend_Spa_Layout')
@layout('Settings_Layout')
@spa('Frontend_Spa_Controller::index')
@title('Password & Security')
@auth('is_logged_in')
class Settings_Password_Security_Action extends Spa_Action {
    scaffolded = true;

    on_create() {
        // Static mock data for active sessions
        this.data.active_sessions = [
            {
                device: 'Chrome on Windows',
                ip: '192.168.1.100',
                location: 'New York, NY',
                last_active: 'Just now',
                is_current: true,
            },
            {
                device: 'Safari on iPhone',
                ip: '192.168.1.105',
                location: 'New York, NY',
                last_active: '2 hours ago',
                is_current: false,
            },
        ];
    }

    // Breadcrumb methods
    async breadcrumb_label_active() {
        return 'Manage your password and active sessions';
    }
}
