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
            // THE ONE PLACE THIS WIDGET DOES NOT MOUNT <Attachment_Thumbnail>, and the reason is
            // the form contract: this input's value IS the attachment KEY (that is what the form
            // posts and what _set_value() receives), while <Attachment_Thumbnail> is addressed by
            // attachment ID and fetches the record itself. There is no id to give it here.
            //
            // Safe, because the widget only ever holds an IMAGE: an image's thumbnail is rendered
            // straight from the blob (render state NOT_REQUIRED), so there is no pending state to
            // wait for and nothing that would later swap. The URL still goes through the single
            // builder - no app code spells '/_thumbnail/' - and the builder needs only .key.
            const width = this.args.width || 96;
            const height = this.args.height || 96;
            this.state.thumbnail_url = File_Attachment_Model.thumbnail_url(
                { key: this.state.attachment_key },
                { type: 'cover', width: width, height: height }
            );
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

                // Announce the user's change ('input' then 'val').
                this._notify_input(this.val());
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

        // Announce the user's change ('input' then 'val').
        this._notify_input(this.val());
    }

    async seed() {
        // For testing - set a placeholder key
        this.val('');
    }
}
