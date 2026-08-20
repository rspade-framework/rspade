/**
 * Custom JavaScript implementation for Project_Model
 *
 * This class extends the auto-generated Base_Project_Model stub,
 * allowing custom client-side model methods.
 */
class Project_Model extends Base_Project_Model {
    /**
     * Example custom method - get display name with status
     */
    get_display_name_with_status() {
        return `${this.name} (${this.status_label})`;
    }
}
