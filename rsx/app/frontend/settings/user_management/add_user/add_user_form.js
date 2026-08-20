/**
 * Add_User_Form
 *
 * Modal form component for inviting users to the current site.
 * See add_user_form.jqhtml for full documentation.
 *
 * JavaScript Responsibilities:
 * - Implements vals() method for form data extraction
 * - Provides default role_id (Member = 3)
 */
class Add_User_Form extends Component {
    /**
     * Get or set form values
     * @param {Object} [values] - If provided, populates form with these values
     * @returns {Object|null} - Form values if getting, null if setting
     */
    // vals(values) {
    //     const form = this.$.find('form');
    //     if (values) {
    //         // Setter - populate form
    //         form.find('[name="email"]').val(values.email || '');
    //         form.find('[name="first_name"]').val(values.first_name || '');
    //         form.find('[name="last_name"]').val(values.last_name || '');
    //         form.find('[name="role_id"]').val(values.role_id || 3); // Default to Member
    //         form.find('[name="phone"]').val(values.phone || '');
    //         return null;
    //     } else {
    //         // Getter - extract form values
    //         return {
    //             email: form.find('[name="email"]').val(),
    //             first_name: form.find('[name="first_name"]').val(),
    //             last_name: form.find('[name="last_name"]').val(),
    //             role_id: parseInt(form.find('[name="role_id"]').val()) || 3,
    //             phone: form.find('[name="phone"]').val()
    //         };
    //     }
    // }
}
