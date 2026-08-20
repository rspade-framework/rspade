<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Core\Database\Models;

use Illuminate\Database\Eloquent\SoftDeletes;
use App\RSpade\Core\Database\Models\Rsx_Site_Model_Abstract;
/**
 * Rsx_Site_Actor_Model_Abstract - base class for a SITE-SCOPED actor.
 *
 * The site-scoped half of the actor layer: everything Rsx_Actor_Model_Abstract declares,
 * on top of Rsx_Site_Model_Abstract's tenant isolation (site global scope, site_id forced
 * from the session on write, site write locks). Framework examples: User_Model (staff
 * membership in a site) and Portal_User_Model (a client-portal account, one row per site).
 *
 * THE CANONICAL ACTOR CONTRACT IS ON Rsx_Actor_Model_Abstract - what get_printed_name()
 * and get_view_profile_url() must do, why there are two abstracts, and how to choose
 * between them. Read it before implementing either method.
 *
 * See: php artisan rsx:man actors
 */
abstract class Rsx_Site_Actor_Model_Abstract extends Rsx_Site_Model_Abstract
{
    use SoftDeletes;

    /**
     * The soft-delete mandate for actor models, declared so it can be enforced.
     * Identical in purpose and enforcement to the declaration on
     * Rsx_Actor_Model_Abstract - see that class for the full rationale.
     *
     * @var bool
     */
    #[Sealed]
    public static bool $actor_soft_deletes = true;

    /**
     * The human-readable name of this actor. Never empty; identical for a trashed
     * record. Contract: Rsx_Actor_Model_Abstract.
     *
     * @return string
     */
    abstract public function get_printed_name(): string;

    /**
     * A URL where the CURRENT viewer may see this actor, or null. Realm-dependent;
     * never memoized. Contract: Rsx_Actor_Model_Abstract.
     *
     * @return string|null
     */
    abstract public function get_view_profile_url(): ?string;
}
