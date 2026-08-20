/**
 * Profile_Photo_Input
 *
 * Profile photo upload widget with thumbnail display and upload handling.
 * See profile_photo_input.jqhtml for full documentation.
 *
 * JavaScript Responsibilities:
 * - Handle file selection and upload
 * - Update thumbnail on successful upload
 * - Manage loading state with spinner
 * - Provide val() getter/setter for attachment key
 * - Handle remove button functionality
 */
class Profile_Photo_Input extends Form_Input_Abstract {
    on_create() {
        super.on_create();

        this.state = {
            attachment_key: '',
            thumbnail_url: ''
        };
    }

    _get_value() {
        return this.state.attachment_key || '';
    }

    _set_value(key) {
        this.state.attachment_key = key || '';

        if (this.state.attachment_key) {
            // Generate thumbnail URL from attachment key
            const width = this.args.width || 96;
            const height = this.args.height || 96;
            this.state.thumbnail_url = `/_thumbnail/dynamic/${this.state.attachment_key}/cover/${width}/${height}`;
        } else {
            // No key - clear thumbnail
            this.state.thumbnail_url = '';
        }

        // Re-render to switch between icon and image
        this.render();
    }

    on_render() {
        // Handle upload button click - trigger hidden file input
        this.$sid('upload_btn').on('click', () => {
            this.$sid('file_input').click();
        });

        // Handle file selection
        this.$sid('file_input').on('change', () => {
            const file = this.$sid('file_input')[0].files[0];
            if (!file) return;

            this.upload_photo(file);
        });

        // Handle remove button
        if (this.args.show_remove) {
            this.$sid('remove_btn').on('click', () => {
                this.remove_photo();
            });
        }
    }

    on_ready() {
        this._mark_ready();
    }

    /**
     * The effective size ceiling in bytes.
     *
     * The framework limit (rsx.files.max_file_size, injected as
     * window.rsxapp.files.max_file_size) is the real one - /_upload enforces it and
     * Ajax.upload() refuses before sending. $max_size is an optional TIGHTER app cap in
     * MB, so the answer is whichever is smaller. It used to default to a hardcoded 25 MB,
     * which was simply a number the server had never agreed to.
     *
     * 0 from either side means "no ceiling there", not "reject everything".
     */
    _max_bytes() {
        const framework = window.rsxapp?.files?.max_file_size || 0;
        const widget = int(this.args.max_size) * 1024 * 1024;

        const limits = [framework, widget].filter(v => v > 0);

        return limits.length ? Math.min(...limits) : 0;
    }

    upload_photo(file) {
        // Validate file size against the EFFECTIVE ceiling (see _max_bytes).
        const max_bytes = this._max_bytes();
        if (max_bytes > 0 && file.size > max_bytes) {
            alert(`File size must be less than ${Ajax.bytes_to_size_label(max_bytes)}`);
            this.$sid('file_input').val(''); // Clear selection
            return;
        }

        // Show spinner, dim image
        this.$sid('spinner').removeClass('d-none');
        this.$sid('photo').css('opacity', '0.3');

        // Create FormData for file upload (site_id is derived server-side from the session)
        const form_data = new FormData();
        form_data.append('file', file);

        // Upload file via AJAX
        $.ajax({
            // Rebased onto this page's channel (staff or portal) - see
            // Rsx_Portal.internal_url(). The $.ajax chokepoint attaches the CSRF header.
            url: Rsx_Portal.internal_url('/_upload'),
            type: 'POST',
            data: form_data,
            processData: false,
            contentType: false,
            success: (response) => {
                // Update attachment key (this will also update thumbnail)
                this.val(response.attachment.key);

                // Hide spinner, restore opacity
                this.$sid('spinner').addClass('d-none');
                this.$sid('photo').css('opacity', '1');

                // Clear file input for future uploads
                this.$sid('file_input').val('');

                // Trigger change event for form tracking
                this.trigger('input', this.val());
                this.trigger('val', this.val());
            },
            error: (xhr, status, error) => {
                console.error('Profile photo upload failed:', error);
                console.error('Response:', xhr.responseJSON);

                // Hide spinner, restore opacity
                this.$sid('spinner').addClass('d-none');
                this.$sid('photo').css('opacity', '1');

                // Clear file input
                this.$sid('file_input').val('');

                // Show error to user
                alert('Upload failed: ' + (xhr.responseJSON?.error || error));
            },
        });
    }

    remove_photo() {
        // Clear attachment key (sets to placeholder)
        this.val('');

        // Trigger change event for form tracking
        this.trigger('input', this.val());
        this.trigger('val', this.val());
    }

    async seed() {
        // For testing - set a placeholder key
        this.val('');
    }
}
