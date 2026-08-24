class Dev_Attachments {
    static init() {
        if (!$(".Dev_Attachments").exists()) return;

        console.log('Dev_Attachments initialized');

        // Handle upload button click - trigger hidden file input
        $('#basic-upload-btn').on('click', function() {
            $('#basic-upload-input').click();
        });

        // Handle file selection
        $('#basic-upload-input').on('change', (e) => {
            const file = e.target.files[0];
            if (!file) return;

            // Update status
            $('#upload-status').text(file.name);

            // Upload the file
            Dev_Attachments.upload_image(file);
        });
    }

    static upload_image(file) {
        // Show spinner on profile image only
        $('#spinner-profile').removeClass('d-none');
        $('#thumb-profile').css('opacity', '0.3');

        // Create FormData for file upload (site_id is derived server-side from the session)
        const form_data = new FormData();
        form_data.append('file', file);
        form_data.append('fileable_type', 'dev_test');
        form_data.append('fileable_category', 'attachment_demo');

        // Upload file via AJAX
        $.ajax({
            // Rebased onto this page's channel (staff or portal) - see
            // Rsx_Portal.internal_url(). The $.ajax chokepoint attaches the CSRF header.
            url: Rsx_Portal.internal_url('/_upload'),
            type: 'POST',
            data: form_data,
            processData: false,
            contentType: false,
            success: function(response) {
                console.log('Upload successful!');
                console.log('Response:', response);
                console.log('File key:', response.attachment.key);

                // Show thumbnails container
                $('#thumbnails-container').removeClass('d-none');

                // Mount a thumbnail component per box. Nothing here builds a URL: the
                // component is handed the attachment id and owns the picture from there,
                // including the swap when an Office document finishes rendering.
                const key = response.attachment.key;
                const attachment_id = response.attachment.id;
                const extension = response.attachment.file_extension;

                $('#thumb-profile').component('Attachment_Thumbnail', { attachment_id: attachment_id, type: 'cover', width: 96, height: 96 });
                $('#thumb-200').component('Attachment_Thumbnail', { attachment_id: attachment_id, type: 'cover', width: 200, height: 200 });
                $('#thumb-240x180').component('Attachment_Thumbnail', { attachment_id: attachment_id, type: 'cover', width: 240, height: 180 });

                // Use icon endpoint for file type icon
                $('#thumb-icon').attr('src', `/_icon_by_extension/${extension}?width=100&height=100`);

                // Set download/inline URLs
                $('#btn-view-inline').attr('href', `/_inline/${key}`);
                $('#btn-view-download').attr('href', `/_download/${key}`);

                // Hide spinner, restore opacity
                $('#spinner-profile').addClass('d-none');
                $('#thumb-profile').css('opacity', '1');

                // Clear file input for future uploads
                $('#basic-upload-input').val('');
                $('#upload-status').html(`<span class="text-success"><i class="bi bi-check-circle"></i> Upload complete!</span>`);
            },
            error: function(xhr, status, error) {
                console.error('Upload failed:', error);
                console.error('Response:', xhr.responseJSON);

                // Hide spinner, restore opacity
                $('#spinner-profile').addClass('d-none');
                $('#thumb-profile').css('opacity', '1');

                // Clear file input
                $('#basic-upload-input').val('');
                $('#upload-status').html(`<span class="text-danger"><i class="bi bi-x-circle"></i> Upload failed</span>`);

                // Show error to user
                alert('Upload failed: ' + (xhr.responseJSON?.error || error));
            }
        });
    }

    static on_app_ready() {
        Dev_Attachments.init();
    }
}