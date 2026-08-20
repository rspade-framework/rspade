class Dev_Modals {
    static on_app_ready() {
        if (!$('.Dev_Modals').exists()) return;
        Dev_Modals.init();
    }

    static init() {
        // Simple Dialogs
        $('#test-alert').on('click', async () => {
            await Modal.alert('This is a simple alert message');
            Dev_Modals.show_result('simple', 'Alert acknowledged');
        });

        $('#test-alert-title').on('click', async () => {
            await Modal.alert('Custom Title', 'This alert has a custom title');
            Dev_Modals.show_result('simple', 'Alert with title acknowledged');
        });

        $('#test-confirm').on('click', async () => {
            const result = await Modal.confirm('Are you sure you want to proceed?');
            Dev_Modals.show_result('simple', `Confirm result: ${result}`);
        });

        $('#test-confirm-title').on('click', async () => {
            const result = await Modal.confirm('Delete Item', 'Are you sure you want to delete this item?');
            Dev_Modals.show_result('simple', `Confirm with title result: ${result}`);
        });

        $('#test-prompt').on('click', async () => {
            const result = await Modal.prompt('What is your name?');
            Dev_Modals.show_result('simple', `Prompt result: ${result === false ? 'Cancelled' : result}`);
        });

        $('#test-prompt-default').on('click', async () => {
            const result = await Modal.prompt('Enter your email:', null, 'user@example.com');
            Dev_Modals.show_result('simple', `Prompt with default result: ${result === false ? 'Cancelled' : result}`);
        });

        $('#test-prompt-multiline').on('click', async () => {
            const result = await Modal.prompt('Enter your feedback:', null, 'Type your feedback here...', true);
            Dev_Modals.show_result('simple', `Multiline prompt result: ${result === false ? 'Cancelled' : result}`);
        });

        $('#test-prompt-rich').on('click', async () => {
            // Create rich formatted content above the input
            const $rich_content = $('<div>')
                .append($('<h5 style="color: #2c3e50; margin-bottom: 15px;">').text('User Registration Form'))
                .append(
                    $('<p style="margin-bottom: 10px;">').html(
                        'Please enter your <strong style="color: #e74c3c;">full name</strong> as it appears on your ' +
                            '<em style="color: #3498db;">official documents</em>.'
                    )
                )
                .append(
                    $('<p style="margin-bottom: 10px;">').html(
                        '<span style="text-decoration: underline; color: #27ae60;">Required fields</span> must be completed before submission.'
                    )
                )
                .append(
                    $('<div style="background-color: #fff3cd; border-left: 4px solid #ffc107; padding: 10px; margin-bottom: 20px;">').html(
                        '<strong>⚠ Note:</strong> <span style="font-size: 14px;">Your name will be used for all official correspondence.</span>'
                    )
                )
                .append($('<p style="font-size: 13px; color: #7f8c8d; margin-bottom: 25px;">').text('Example: John Michael Smith'));

            const result = await Modal.prompt($rich_content, null, '', false);
            Dev_Modals.show_result('simple', `Rich text prompt result: ${result === false ? 'Cancelled' : result}`);
        });

        $('#test-prompt-validation').on('click', async () => {
            // Demonstrate validation pattern: keep reopening until valid or cancelled
            let email = '';
            let error = null;
            let valid = false;

            while (!valid) {
                email = await Modal.prompt('Email Validation', 'Please enter a valid email address:', email, false, error);

                // User cancelled
                if (email === false) {
                    Dev_Modals.show_result('simple', 'Prompt cancelled');
                    return;
                }

                // Validate email (simple check)
                if (!email || email.trim() === '') {
                    error = 'Email address is required';
                } else if (!email.includes('@') || !email.includes('.')) {
                    error = 'Please enter a valid email address (must contain @ and .)';
                } else if (email.length < 5) {
                    error = 'Email address is too short';
                } else {
                    // Valid!
                    valid = true;
                }
            }

            Dev_Modals.show_result('simple', `Valid email entered: ${email}`);
        });

        $('#test-select').on('click', async () => {
            const options = [
                {value: 'apple', label: 'Apple'},
                {value: 'banana', label: 'Banana'},
                {value: 'cherry', label: 'Cherry'},
                {value: 'date', label: 'Date'},
                {value: 'elderberry', label: 'Elderberry'}
            ];
            const result = await Modal.select('Choose a fruit:', null, options);
            Dev_Modals.show_result('simple', `Select result: ${result === false ? 'Cancelled' : result}`);
        });

        $('#test-select-default').on('click', async () => {
            const options = [
                {value: 'admin', label: 'Administrator'},
                {value: 'manager', label: 'Manager'},
                {value: 'user', label: 'Standard User'},
                {value: 'guest', label: 'Guest'}
            ];
            const result = await Modal.select('Select Role', 'Choose a role for the new user:', options, 'user', 'Select a role...');
            Dev_Modals.show_result('simple', `Select with default result: ${result === false ? 'Cancelled' : result}`);
        });

        // Custom Modals
        $('#test-custom-2btn').on('click', async () => {
            const result = await Modal.show({
                title: 'Custom Two Button Modal',
                body: 'This modal has two custom buttons with different values',
                buttons: [
                    { label: 'Option A', value: 'option_a', class: 'btn-secondary' },
                    { label: 'Option B', value: 'option_b', class: 'btn-primary', default: true },
                ],
            });
            Dev_Modals.show_result('custom', `Two button result: ${result}`);
        });

        $('#test-custom-3btn').on('click', async () => {
            const result = await Modal.show({
                title: 'Three Button Modal',
                body: 'Choose one of three options',
                buttons: [
                    { label: 'Cancel', value: false, class: 'btn-secondary' },
                    { label: 'Save Draft', value: 'draft', class: 'btn-info' },
                    { label: 'Publish', value: 'publish', class: 'btn-success', default: true },
                ],
            });
            Dev_Modals.show_result('custom', `Three button result: ${result}`);
        });

        $('#test-custom-danger').on('click', async () => {
            const result = await Modal.show({
                title: 'Dangerous Action',
                body: 'This action cannot be undone. Are you absolutely sure?',
                buttons: [
                    { label: 'Cancel', value: false, class: 'btn-secondary' },
                    { label: 'Delete Forever', value: true, class: 'btn-danger', default: true },
                ],
            });
            Dev_Modals.show_result('custom', `Dangerous action result: ${result}`);
        });

        $('#test-custom-jquery').on('click', async () => {
            const $content = $('<div>')
                .append($('<p>').text('This modal body contains jQuery elements'))
                .append(
                    $('<ul>')
                        .append($('<li>').text('First item'))
                        .append($('<li>').text('Second item'))
                        .append($('<li>').text('Third item'))
                )
                .append($('<p>').html('<strong>Formatted text</strong> and <em>styling</em>'));

            const result = await Modal.show({
                title: 'jQuery Content',
                body: $content,
                buttons: [{ label: 'Close', value: true, class: 'btn-primary', default: true }],
            });
            Dev_Modals.show_result('custom', 'jQuery content modal closed');
        });

        $('#test-custom-wide').on('click', async () => {
            const result = await Modal.show({
                title: 'Wide Modal (1200px)',
                body: 'This modal has a max width of 1200px, useful for forms or data tables that need more horizontal space.',
                max_width: 1200,
                buttons: [{ label: 'Close', value: true, class: 'btn-primary', default: true }],
            });
            Dev_Modals.show_result('custom', 'Wide modal closed');
        });

        // Special Behaviors
        $('#test-unclosable').on('click', async () => {
            Modal.unclosable('Processing', 'Please wait while we process your request...');

            // Simulate processing
            setTimeout(async () => {
                await Modal.close();
                Dev_Modals.show_result('special', 'Unclosable modal was closed programmatically after 3 seconds');
            }, 3000);
        });

        $('#test-queue').on('click', async () => {
            // Queue 3 modals
            const promise1 = Modal.alert('First Modal', 'This is the first modal in the queue');
            const promise2 = Modal.alert('Second Modal', 'This is the second modal in the queue');
            const promise3 = Modal.alert('Third Modal', 'This is the third and final modal');

            await Promise.all([promise1, promise2, promise3]);
            Dev_Modals.show_result('special', 'All 3 queued modals completed');
        });

        $('#test-error').on('click', async () => {
            const error_obj = {
                message: 'An error occurred while processing your request',
            };

            await Modal.error(error_obj, 'Error');
            Dev_Modals.show_result('special', 'Error modal shown');
        });

        $('#test-tall').on('click', async () => {
            let tall_content = '<h6>Scrollable Content</h6>';
            for (let i = 1; i <= 50; i++) {
                tall_content += `<p>Paragraph ${i}: This is a long paragraph that helps demonstrate scrolling behavior when content exceeds the 80% viewport height threshold.</p>`;
            }

            const result = await Modal.show({
                title: 'Tall Content (Scrolling Test)',
                body: tall_content,
                buttons: [{ label: 'Close', value: true, class: 'btn-primary', default: true }],
            });
            Dev_Modals.show_result('special', 'Tall content modal closed');
        });

        // Form Modals
        $('#test-form-simple').on('click', async () => {
            const result = await Modal.form({
                title: 'User Registration',
                component: 'Test_Modal_Form',
                on_submit: async (form) => {
                    // Get form values
                    const values = form.vals();

                    // Simple validation - just check required fields
                    if (!values.name || !values.email || !values.role) {
                        Form_Utils.apply_form_errors(form.$, 'Please fill in all required fields');
                        return false; // Keep modal open
                    }

                    // Simulate saving
                    await sleep(500);

                    // Return data to close modal
                    return values;
                },
            });

            if (result) {
                Dev_Modals.show_result('form', `Form submitted: ${json_encode(result)}`);
            } else {
                Dev_Modals.show_result('form', 'Form cancelled');
            }
        });

        $('#test-form-validation').on('click', async () => {
            const result = await Modal.form({
                title: 'User Registration (With Server Validation)',
                component: 'Test_Modal_Form',
                on_submit: async (form) => {
                    // Get form values
                    const values = form.vals();

                    // Simulate server-side validation
                    await sleep(500);

                    // Simulate validation errors
                    const errors = {};
                    if (!values.name || values.name.length < 3) {
                        errors.name = 'Name must be at least 3 characters';
                    }
                    if (!values.email || !values.email.includes('@')) {
                        errors.email = 'Please enter a valid email address';
                    }
                    if (!values.role) {
                        errors.role = 'Please select a role';
                    }

                    // If there are errors, show them and keep modal open
                    if (Object.keys(errors).length > 0) {
                        Form_Utils.apply_form_errors(form.$, errors);
                        return false; // Keep modal open
                    }

                    // Success - return data
                    return values;
                },
            });

            if (result) {
                Dev_Modals.show_result('form', `Form submitted successfully: ${json_encode(result)}`);
            } else {
                Dev_Modals.show_result('form', 'Form cancelled');
            }
        });

        $('#test-form-prefilled').on('click', async () => {
            const result = await Modal.form({
                title: 'Edit User Profile',
                component: 'Test_Modal_Form',
                component_args: {
                    data: {
                        name: 'John Smith',
                        email: 'john.smith@example.com',
                        role: 'manager',
                    },
                },
                submit_label: 'Update',
                on_submit: async (form) => {
                    const values = form.vals();

                    // Simple validation
                    if (!values.name || !values.email || !values.role) {
                        await Modal.alert('Please fill in all required fields');
                        return false;
                    }

                    // Simulate update
                    await sleep(500);
                    return values;
                },
            });

            if (result) {
                Dev_Modals.show_result('form', `Profile updated: ${json_encode(result)}`);
            } else {
                Dev_Modals.show_result('form', 'Update cancelled');
            }
        });

        $('#test-form-pin').on('click', async () => {
            const result = await Modal.form({
                title: 'Enter Verification Code',
                component: 'Pin_Verification_Form',
                submit_label: 'Verify',
                max_width: 450,
                on_submit: async (form) => {
                    const pin = form.val();

                    // Validate PIN is complete
                    if (pin.length !== 6) {
                        Form_Utils.apply_form_errors(form.$, 'Please enter all 6 digits');
                        return false;
                    }

                    // Simulate PIN verification
                    await sleep(800);

                    // Simulate checking against correct PIN
                    const correct_pin = '123456';
                    if (pin !== correct_pin) {
                        Form_Utils.apply_form_errors(form.$, `Incorrect PIN. (Hint: try ${correct_pin})`);
                        // Clear the PIN and refocus first input
                        form.val('');
                        return false;
                    }

                    // Success
                    return { pin: pin, verified: true };
                },
            });

            if (result) {
                Dev_Modals.show_result('form', `PIN verified successfully: ${result.pin}`);
            } else {
                Dev_Modals.show_result('form', 'PIN verification cancelled');
            }
        });

        // Modal State
        $('#check-state').on('click', () => {
            const state = {
                is_open: Modal.is_open(),
                current_modal: Modal.get_current() !== null ? 'Modal instance exists' : null,
            };

            const formatted = json_encode(state, null, 2);
            $('#state-result').show();
            $('#state-result-text').text(formatted);
        });

        $('#force-close').on('click', async () => {
            if (Modal.is_open()) {
                await Modal.close();
                Dev_Modals.show_result('special', 'Modal was force closed');
            } else {
                Dev_Modals.show_result('special', 'No modal is currently open');
            }
        });
    }

    static show_result(section, message) {
        const $result = $(`#${section}-result`);
        const $text = $(`#${section}-result-text`);

        $text.text(message);
        $result.show();

        // Hide after 5 seconds
        setTimeout(() => {
            $result.fadeOut();
        }, 5000);
    }
}
