/**
 * Portal_Login_Verify
 *
 * The challenge page owns one piece of copy of its own - the recovery-code hint under
 * <Two_Factor_Challenge>. When the component swaps its controls for "You have signed in
 * using the email link." (the sign-in finished in another tab of this browser), the hint is
 * advice about a step nobody has to take any more, so it goes. The component announces the
 * swap with its 'completed' event; the page never reads the component's markup.
 */
class Portal_Login_Verify {
    static on_app_ready() {
        if (!$('.Portal_Login_Verify').exists()) return;

        const challenge = $('.Two_Factor_Challenge').component();

        challenge.on('completed', function () {
            $('.Portal_Login_Verify__footer').hide();
        });
    }
}
