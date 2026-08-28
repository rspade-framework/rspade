/**
 * Modal_Abstract - Base class for modal orchestration classes
 *
 * **Philosophy**:
 * Modal classes are orchestration layers that manage the lifecycle of showing
 * a modal, collecting user input, and returning results. They do NOT contain
 * form validation or business logic - that belongs in jqhtml components and
 * controller endpoints.
 *
 * **Purpose**:
 * - Provides a common base class for type identification
 * - Enforces file naming conventions (modal classes end with _Modal)
 * - Documents the modal class pattern
 * - Enables framework-level features (future: discovery, validation)
 *
 * **Responsibilities of Modal Classes**:
 * - Invoke Modal.form() / Modal.show() / Modal.confirm() with appropriate configuration
 * - Handle modal lifecycle (show, submit, cancel, errors)
 * - Return Promise that resolves with data or false
 * - Encapsulate modal-specific UI logic
 *
 * **Contract**:
 * All modal classes extending Modal_Abstract must implement:
 * - `static async show(params)`: Primary entry point, returns Promise
 *
 * **Return Values**:
 * - Success: Resolve with data object (e.g., created user record)
 * - Cancel/Close: Resolve with false
 * - Error: Show error in modal, keep open, don't resolve until user acts
 *
 * **Integration**:
 * Modal classes use Modal.js static API (Modal.form(), Modal.show(), etc.)
 * as building blocks. A form modal is CHROME around an <Rsx_Form>: the form owns
 * the endpoint, the submission and the error rendering, so the modal class names
 * none of them.
 *
 * **Pattern Examples**:
 *
 * Simple form modal - the component's template holds the <Rsx_Form>, and that form
 * declares the endpoint. There is nothing else to write:
 * ```
 * class Add_User_Modal extends Modal_Abstract {
 *     static async show() {
 *         const result = await Modal.form({
 *             title: 'Add User',
 *             component: 'Add_User_Modal_Form',
 *         });
 *         return result || false;
 *     }
 * }
 * ```
 *
 * A dialog with NO form - it collects a choice and the caller acts on it - is
 * Modal.show({buttons}), never Modal.form(). Returning literal false from a button
 * callback keeps that dialog open; any other value (null included) closes it.
 *
 * Custom content modal:
 * ```
 * class Confirm_Delete_Modal extends Modal_Abstract {
 *     static async show({item_name}) {
 *         return await Modal.confirm(
 *             'Confirm Delete',
 *             `Are you sure you want to delete ${item_name}?`
 *         );
 *     }
 * }
 * ```
 *
 * Modal with backend call:
 * ```
 * class Send_Invite_Modal extends Modal_Abstract {
 *     static async show(user_id) {
 *         const result = await Controller.send_invite({user_id});
 *         if (result.invite_url) {
 *             await Modal.alert('Invite Sent', result.invite_url);
 *         }
 *         return result;
 *     }
 * }
 * ```
 *
 * **Usage Pattern**:
 * ```
 * // Page JS orchestrates flow, modals handle UI
 * const user = await Add_User_Modal.show();
 * if (user) {
 *     $('.Users_DataGrid').component().reload();
 *     await Send_User_Invite_Modal.show(user.id);
 * }
 * ```
 *
 * **Best Practices**:
 * - Keep modal classes focused: one modal = one class
 * - Page JS orchestrates sequence, modal classes handle individual modals
 * - Modal classes don't call each other directly
 * - Modal classes don't update UI (grids, lists) - page JS does that
 * - Use descriptive names ending in _Modal (Add_User_Modal, Send_Invite_Modal)
 * - Place feature-specific modals in feature directory
 * - Place reusable modals in theme/components/modal/
 *
 * **When to Use Modal Classes**:
 * - Multi-step forms
 * - Forms with complex validation
 * - Modals called from multiple places
 * - Modals with backend interactions
 *
 * **When NOT to Use Modal Classes**:
 * - Simple alerts: `await Modal.alert('Saved!')`
 * - Simple confirmations: `if (await Modal.confirm('Delete?')) {...}`
 * - One-off prompts: `const name = await Modal.prompt('Enter name:')`
 */
class Modal_Abstract {
    // This class provides structure and documentation for modal patterns.
    // Concrete modal classes extend this and implement static show() method.
}
