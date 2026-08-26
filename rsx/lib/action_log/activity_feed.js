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
 * Type ranges: client 1-9, contact 10-19, project 20-29, task 30-39, party 40-49.
 *
 * Usage (in on_load, after fetching the activity array):
 *   this.data.activity = result.activity.map(a => ({ ...a, ...Activity_Feed.decorate(a.type_id) }));
 * then in the template:
 *   <Feed_Row $icon=a.icon $variant=a.variant> ... </Feed_Row>
 */
class Activity_Feed {
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
            default: return { icon: 'bi bi-activity', variant: 'secondary' };
        }
    }
}
