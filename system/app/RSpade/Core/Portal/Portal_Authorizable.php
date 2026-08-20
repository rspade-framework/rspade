<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Core\Portal;

/**
 * Portal_Authorizable - record-level authorization for portal-exposed models.
 *
 * This is the portal equivalent of the staff fetch() security contract. A model
 * that uses this trait becomes fetchable from portal JavaScript via the ORM
 * (Rsx_Js_Model.fetch -> Orm_Controller, which calls portal_fetch() in a portal
 * request context).
 *
 * The trait supplies a default portal_fetch() that defers the per-row visibility
 * decision to the model's portal_can_read(). The "is there a portal session" half is
 * the model's declarative gate: every adopting model carries #[Auth('is_logged_in')],
 * evaluated in the PORTAL realm by the ORM seam before any model code runs.
 * See: php artisan rsx:man auth_gates.
 *
 * Each model that uses this trait MUST implement portal_can_read(): bool, picking
 * exactly ONE fail-closed visibility rule:
 *   - own-record       : $this->id === Portal_Session::get_portal_user_id()
 *   - shared-recipient  : the row's recipient matches the portal user's contact
 *   - membership-scoped : Portal_Permission::has_client_access($this->client_id)
 *
 * The trait body itself references NO class at all, so it carries no core->app
 * dependency. Core models implement portal_can_read() against Portal_Session; app
 * models implement it against the app-side Portal_Permission.
 */
trait Portal_Authorizable
{
    /**
     * Ajax model fetch for portal users.
     *
     * Called by the JavaScript ORM (via Orm_Controller) when a portal request
     * fetches this model. Returns the row as an array on success, or false when
     * the portal user may not read the row.
     *
     * Security: the adopting model's #[Auth] gates run at the ORM seam first (the
     * portal-session requirement); this body is the record-level layer only - the
     * model's fail-closed portal_can_read() check.
     *
     * @param int $id
     * @return array|false
     */
    #[Ajax_Endpoint_Model_Fetch]
    public static function portal_fetch($id)
    {
        $row = static::find($id);

        if (!$row || !$row->portal_can_read()) {
            return false;
        }

        return $row->toArray();
    }
}
