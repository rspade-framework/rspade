class Dev_Index {
    static init() {
        if (!$(".Dev_Index").exists()) return;

        // Initialize your component here
        console.log('Dev_Index initialized');

        // Example: Handle button clicks
        $('.btn-action').on('click', function() {
            // Handle action
        });

        // Example: Initialize tooltips
        $('[data-bs-toggle="tooltip"]').tooltip();
    }

    static on_app_ready() {
        Dev_Index.init();
    }
}