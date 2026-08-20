class Login_Layout {
    static on_app_ready() {
        if (!$('.Login_Layout').exists()) return;
        $('.Login_Layout').removeClass('preload');
    }
}
