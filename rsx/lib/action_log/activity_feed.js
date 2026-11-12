/**
 * Activity_Feed
 *
 * Maps an action-log type_id to the Feed_Row presentation (icon + accent variant)
 * for an entity Activity tab. The activity endpoints return the raw
 * {id, html, created_at, type_id}; the rendered summary html is authored by
 * Action_Log_Model::render(), so all a page adds is a leading icon + a color
 * accent by action category. This is the client-side twin of the dashboard's
 * server-side Frontend_Dashboard_Controller::_activity_icon() - kept in ONE place
 * so every entity view (Contacts / Projects / Party / Clients) decorates the feed
 * identically (cross-page consistency is the acceptance test).
 *
 * Type ranges: client 1-9, contact 10-19, project 20-29, task 30-39, party 40-49,
 * account security (passwords, API keys) 50-59.
 *
 * Usage (in on_load, after fetching the activity array):
 *   this.data.activity = result.activity.map(a => ({ ...a, ...Activity_Feed.decorate(a.type_id) }));
 * then in the template:
 *   <Feed_Row $icon=a.icon $variant=a.variant> ... </Feed_Row>
 */
class Activity_Feed {
    /**
     * May the current user see action-log rows at all?
     *
     * The action log is for administrators: every endpoint that returns its rows carries
     * the Action Log screen's own gate (can_view_user_activity), so the screen's
     * accessibility is the one question. A view page asks it before fetching its activity
     * feed and before drawing the Activity tab and KPI.
     *
     * @returns {boolean}
     */
    static can_view() {
        return Permission.can_access('Action_Logs_Index_Action');
    }

    /**
     * Return { icon, variant } for an action-log type_id.
     *
     * @param {number} type_id
     * @returns {{icon: string, variant: string}}
     */
    static decorate(type_id) {
        switch (Math.floor(int(type_id) / 10)) {
            case 0: return { icon: 'bi bi-building', variant: 'primary' };
            case 1: return { icon: 'bi bi-person', variant: 'info' };
            case 2: return { icon: 'bi bi-folder', variant: 'success' };
            case 3: return { icon: 'bi bi-check2-square', variant: 'warning' };
            case 4: return { icon: 'bi bi-diagram-3', variant: 'secondary' };
            case 5: return { icon: 'bi bi-shield-lock', variant: 'danger' };
            default: return { icon: 'bi bi-activity', variant: 'secondary' };
        }
    }
}
