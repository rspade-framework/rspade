/**
 * Rsx_Csrf - Framework-core CSRF client wiring (native form submissions)
 *
 * AJAX + upload transports attach the X-CSRF-Token header at the $.ajax override
 * chokepoint (Rsx_Jq_Helpers). Native full-page <form> POSTs carry the token as a
 * hidden body field instead, so this global submit listener injects one from
 * window.rsxapp.csrf just before the form is submitted (if the token is set and
 * the form does not already carry it).
 *
 * Harmless for Rsx_Form (which submits via AJAX, not a native form POST); the
 * injected field is cosmetically excluded from Rsx_Form.vals().
 */
class Rsx_Csrf {
    static _on_framework_core_define() {
        // Delegated listener so it also catches forms rendered later (SPA/jqhtml).
        $(document).on('submit', 'form', function (event) {
            // Nothing to inject on an anonymous page (no session, no token).
            if (!(window.rsxapp && window.rsxapp.csrf)) {
                return;
            }

            // The delegated selector match; in jQuery this is the submitted <form>.
            const form = event.currentTarget;

            // Respect an already-present token field (e.g. a Blade @csrf field).
            if (form.querySelector('input[name="_csrf_token"]')) {
                return;
            }

            // Safe DOM construction: value assigned via property, never innerHTML.
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = '_csrf_token';
            input.value = window.rsxapp.csrf;
            form.appendChild(input);
        });
    }
}
