/**
 * Login_Two_Factor_Setup - the forced-enrollment interstitial's page behaviour.
 *
 * A Blade page's JS is a static on_app_ready() that fires for EVERY page in the bundle, so
 * the guard is the first line (see rsx/app/login/CLAUDE.md).
 *
 * The two enrollment components are mounted from here rather than placed in the template,
 * because mounting is what yields the instance to listen on: 'enrolled' and 'registered' are
 * the events that say the account now has a second factor, and the only thing this page does
 * afterwards is leave. '/' is the destination - a logged-in identity is bounced from there to
 * the dashboard, and by then pre_dispatch has nothing left to intercept.
 */
class Login_Two_Factor_Setup {
    static on_app_ready() {
        if (!$('.Login_Two_Factor_Setup').exists()) return;

        $('.Login_Two_Factor_Setup').on('click', '[data-tfa-choice]', function () {
            const $button = $(this);

            Login_Two_Factor_Setup.mount($button.data('tfa-choice'));
        });
    }

    /**
     * Swap in one enrollment ceremony and wait for it to finish.
     *
     * @param {string} choice 'totp' or 'passkey'
     */
    static mount(choice) {
        const $choices = $('.Login_Two_Factor_Setup__choices');
        const $mount = $('.Login_Two_Factor_Setup__mount');

        $choices.find('[data-tfa-choice]').removeClass('btn-primary btn-outline-secondary').addClass('btn-outline-secondary');
        $choices.find('[data-tfa-choice="' + choice + '"]').removeClass('btn-outline-secondary').addClass('btn-primary');

        $mount.empty();

        const name = choice === 'passkey' ? 'Passkey_Register' : 'Totp_Enrollment';
        const event = choice === 'passkey' ? 'registered' : 'enrolled';
        const component = $mount.component(name).component();

        component.on(event, function () {
            window.location = '/';
        });
    }
}
