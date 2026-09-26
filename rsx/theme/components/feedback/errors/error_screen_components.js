/**
 * Error_Screen_Components
 *
 * Registers this theme's error bodies as the SPA's error screens. The framework's
 * Error_Screens mounts whatever the running bundle registered - a denied @auth gate,
 * a URL no action claims, an action that failed to boot - into the live layout's
 * content area. Every bundle that includes rsx/theme gets this registration, so the
 * staff app and the portal both answer with the components beside this file.
 *
 * To use a different body for one outcome, name that component here.
 */
class Error_Screen_Components {
    static on_app_modules_define() {
        Error_Screens.set_components({
            unauthorized: 'Unauthorized_Error_Page_Component',
            not_found: 'Not_Found_Error_Page_Component',
            fatal: 'Generic_Error_Page_Component',
        });
    }
}
