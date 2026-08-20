/**
 * Dev ACL page JavaScript
 */
class Dev_Acl {
    static on_app_ready() {
        if (!$('.Dev_Acl').exists()) return;

        $('#btn-populate').click(async () => {
            try {
                const result = await Dev_Acl_Controller.populate_test_users();
                let message = '';
                if (result.created.length > 0) {
                    message += `Created: ${result.created.join(', ')}\n`;
                }
                if (result.skipped.length > 0) {
                    message += `Skipped (already exist): ${result.skipped.join(', ')}`;
                }
                if (message === '') {
                    message = 'No users to create.';
                }
                await Modal.alert(message, 'Test Users Created');
                window.location.reload();
            } catch (e) {
                await Modal.alert(`Error: ${e.message}`, 'Error');
            }
        });

        $('#btn-delete').click(async () => {
            const confirmed = await Modal.confirm('Delete all test users (@rspade.test)?', 'Confirm Delete');
            if (!confirmed) return;

            try {
                const result = await Dev_Acl_Controller.delete_test_users();
                if (result.deleted.length > 0) {
                    await Modal.alert(`Deleted: ${result.deleted.join(', ')}`, 'Test Users Deleted');
                } else {
                    await Modal.alert('No test users found to delete.', 'Nothing Deleted');
                }
                window.location.reload();
            } catch (e) {
                await Modal.alert(`Error: ${e.message}`, 'Error');
            }
        });
    }
}
