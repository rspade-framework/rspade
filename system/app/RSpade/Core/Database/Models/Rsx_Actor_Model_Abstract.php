<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Core\Database\Models;

use Illuminate\Database\Eloquent\SoftDeletes;
use App\RSpade\Core\Database\Models\Rsx_Model_Abstract;
/**
 * Rsx_Actor_Model_Abstract - base class for a NON site-scoped actor. THE CANONICAL
 * ACTOR CONTRACT LIVES HERE; Rsx_Site_Actor_Model_Abstract is its site-scoped twin and
 * points back at this docblock.
 *
 * An "actor" is anything that can SIGN IN or STAMP AUTHORSHIP: the entity a
 * created_by_id/created_by_type or updated_by_id/updated_by_type pair can point at. The
 * framework ships three (User_Model, Portal_User_Model, Login_User_Model); an
 * application may add its own.
 *
 * WHY "ACTOR" AND NOT "USER". "User" is already three different things in RSpade - a
 * staff site membership (User_Model), a cross-site login identity (Login_User_Model),
 * and a client-portal account (Portal_User_Model) - so an "Rsx_User_Model_Abstract"
 * would read as "the base of User_Model" and nothing else. "Actor" names the ROLE these
 * classes share: whoever performed the write.
 *
 * WHY THERE ARE TWO ABSTRACTS. Actors do not share one base class: staff and portal
 * users are site-scoped (Rsx_Site_Model_Abstract) while the login identity is
 * deliberately cross-site (Rsx_Model_Abstract), and PHP has no multiple inheritance.
 * Pick by the TABLE's shape, not by taste:
 *
 *   - Rsx_Actor_Model_Abstract (this class) - the table has NO site_id; one row is one
 *     identity across the whole install. Framework example: Login_User_Model.
 *   - Rsx_Site_Actor_Model_Abstract - the table carries site_id and the actor lives
 *     inside a tenant. Framework examples: User_Model, Portal_User_Model.
 *
 * Extending the site abstract installs the site global scope and site write locks, which
 * a cross-site identity table must not have; omitting it on a site-scoped table is a
 * cross-tenant read leak. Code that must accept EITHER kind tests both class names
 * (there is no shared interface - the manifest indexes classes, so an interface here
 * could not be imported by the code that needs it).
 *
 * SOFT DELETES ARE MANDATORY, and installed here so nobody has to remember. An actor
 * that could be hard-deleted would leave every audit column pointing at it dangling, and
 * get_printed_name() - which historical screens still call - would have nothing to
 * answer with. $actor_soft_deletes is marked #[Sealed]: a subclass that redeclares it
 * (the natural way someone would try to opt out) is a manifest-build FATAL, and ACTOR-01
 * separately verifies the SoftDeletes trait actually survives in every descendant.
 *
 * =========================================================================
 * IMPLEMENTING get_printed_name()
 * =========================================================================
 *
 * The human-readable name for this actor, for display anywhere a record's authorship is
 * shown. Requirements, all of them load-bearing:
 *
 * - MUST return a non-empty string. There is no "no name" answer: an audit column that
 *   renders blank is indistinguishable from an unattributed record. Cascade through
 *   whatever the model has - full name, then email, then the email localpart, then a
 *   stable last-resort like "User #17" - but always return something.
 * - MUST work on a TRASHED record. Actors are soft-deleted precisely so historical
 *   authorship stays readable; a resolver reaching a deleted actor calls this method and
 *   must not get an exception or an empty string.
 * - MUST render a deleted actor IDENTICALLY to a live one. Do NOT append "(deleted)",
 *   "(inactive)", or any other marker. Owner ruling: the model states the name and
 *   nothing else. A widget or screen that wants to decorate deleted authorship is free
 *   to do so from trashed(), but the model never bakes presentation into the name.
 * - MUST NOT perform authorization. A name is not a permission decision; whether the
 *   viewer may follow a link to the actor is get_view_profile_url()'s job.
 *
 * =========================================================================
 * IMPLEMENTING get_view_profile_url()
 * =========================================================================
 *
 * The URL of a page where the CURRENT viewer can see this actor, or null.
 *
 * - MAY return null, and often should. Null means "there is no page, or you may not see
 *   it" - callers render the printed name as plain text. Login_User_Model returns null
 *   unconditionally today because no login-identity screen exists.
 * - MUST resolve permission through the auth gates, never a hand-rolled role or
 *   permission test. Auth_Gates::accessible_route($target, $realm, $params) is the one
 *   call: it returns the URL when the surface exists in this install AND every gate
 *   declared ON THAT DESTINATION passes for the current session, and null otherwise. So
 *   a link can never promise a page the viewer would be bounced from, adding a gate to
 *   the destination automatically tightens every link to it, and naming a screen the
 *   application has removed degrades to plain text instead of throwing.
 * - MAY return a DIFFERENT URL per realm for the same record. A portal user viewed by
 *   staff resolves to the staff-side administration screen; the same portal user viewing
 *   themselves resolves to their portal account page. Branch on
 *   Rsx_Portal::is_portal_request().
 * - MUST NOT be memoized across realms, and in practice must not be memoized at all on
 *   the instance. The answer depends on WHO IS ASKING, not on the record. A cached URL
 *   is a cross-realm leak waiting to happen (a CLI process, a test, or a queued task
 *   that switches identity would serve the previous viewer's answer). Callers that need
 *   to avoid repeated resolution memoize per request AND per viewer - see
 *   Rsx_Model_Abstract::get_created_by_author().
 *
 * See: php artisan rsx:man actors
 */
abstract class Rsx_Actor_Model_Abstract extends Rsx_Model_Abstract
{
    use SoftDeletes;

    /**
     * The soft-delete mandate for actor models, declared so it can be enforced.
     *
     * This property is a DECLARATION, not a switch - flipping it to false disables
     * nothing, because SoftDeletes is installed by the `use` above. It exists to give
     * the mandate a sealed declaration site:
     *
     * - #[Sealed] (SEALED-01) makes a subclass REDECLARING it a manifest-build fatal,
     *   which is exactly the move someone makes when trying to opt out.
     * - ACTOR-01 verifies that every concrete descendant still resolves the SoftDeletes
     *   trait through its lineage.
     *
     * @var bool
     */
    #[Sealed]
    public static bool $actor_soft_deletes = true;

    /**
     * The human-readable name of this actor. Never empty; identical for a trashed
     * record. See the class docblock for the full contract.
     *
     * @return string
     */
    abstract public function get_printed_name(): string;

    /**
     * A URL where the CURRENT viewer may see this actor, or null when no such page
     * exists or the viewer may not reach it. Realm-dependent; never memoized. See the
     * class docblock for the full contract.
     *
     * @return string|null
     */
    abstract public function get_view_profile_url(): ?string;
}
