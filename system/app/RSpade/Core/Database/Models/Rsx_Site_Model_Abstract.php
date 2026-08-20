<?php

namespace App\RSpade\Core\Database\Models;

use Exception;
use Illuminate\Database\Eloquent\Builder;
use App\RSpade\Core\Database\Models\Rsx_Model_Abstract;
use App\RSpade\Core\Locks\RsxLocks;
use App\RSpade\Core\Portal\Portal_Session;
use App\RSpade\Core\Portal\Rsx_Portal;
use App\RSpade\Core\Session\Session;

/**
 * Abstract base model for site-scoped models with automatic concurrency control
 *
 * Models extending this class:
 * - Automatically scope queries by site_id from session
 * - Include site_id column in the database
 * - Support soft deletes when configured
 * - Provide automatic site-level database locking for write operations
 * - Strict enforcement of site boundaries - no cross-site data access
 *
 * SITE ISOLATION:
 * - All queries automatically filtered by the CURRENT EXPERIENCE's site_id
 * - All saves automatically set site_id from the same source
 * - Changing site_id on existing records is FATAL
 * - Site ID 0 used when no staff site is selected (global/unscoped data)
 * - No caching of site_id - always reads fresh
 *
 * WHICH SITE (see get_current_site_id):
 * - Staff request / CLI: the staff session's site_id
 * - Portal request: the site the app declared via Portal_Session::set_site_id
 *   (throws if it declared none - a portal request has no site to guess at)
 *
 * CONCURRENCY CONTROL:
 * Site locks key on that same resolved site, so a portal request locks the portal's
 * tenant and never the staff session's:
 * - A READ-ONLY request takes NO lock at all and never contacts the lock daemon
 * - The first save() takes the CLUSTER-wide WRITE lock for that site (rsx-lockd), held
 *   until the end of the script
 * - No automatic transactions - handle manually when needed
 *
 * This prevents race conditions for critical operations like:
 * - Inventory management
 * - Auction bidding
 * - Financial transactions
 * - Any operation requiring strict consistency within a site
 */
abstract class Rsx_Site_Model_Abstract extends Rsx_Model_Abstract
{
    /**
     * Whether to automatically apply site scoping to queries
     * Can be disabled for admin operations that need cross-site access
     *
     * @var bool
     */
    protected static $apply_site_scope = true;

    /**
     * MASTER SWITCH for the AUTOMATIC per-tenant write lock. Currently OFF.
     *
     * When true, the first save()/update()/delete() a script performs against any
     * site-scoped model silently takes cluster:SITE_<id> WRITE and holds it until the
     * process exits, making a multi-statement mutation of one tenant atomic against every
     * other writer in the cluster.
     *
     * DISABLED 2026-08-11 (owner ruling): not ready for production. The mechanism is
     * sound in isolation, but acquiring a cluster-wide lock IMPLICITLY - from a write
     * nobody asked to be exclusive, held for the life of the process - has consequences
     * that have not been worked through. The known one is rsx:debug: the CLI process
     * takes the lock, then drives a real HTTP request through Playwright, and that request
     * is a SEPARATE process which blocks on the same lock while the CLI waits for the page
     * it will never get. Lock groups do not help there - the web request is not a spawned
     * child, so there is nothing to inherit through.
     *
     * Everything else stays in place and working: the token registry, release_site_lock(),
     * release_all_site_locks(), the worker's per-task release, and the explicit
     * RsxLocks::site_write_lock($site_id) API that application code can still call
     * deliberately. Flipping this back to true is the whole re-enable.
     *
     * Backlog B-87 owns the revisit.
     */
    private const AUTOMATIC_SITE_WRITE_LOCKS_ENABLED = false;

    /**
     * Site lock tokens by site_id
     * @var array<int, string>
     */
    protected static $site_lock_tokens = [];

    /**
     * Get the current site ID for the EXPERIENCE this request is serving.
     * Always returns fresh value - never cached.
     *
     * THE TENANT BOUNDARY. This one value feeds the `site` global scope, the `creating`
     * forced site_id, the `saving` cross-site fatal, the `retrieved` cross-check, save(),
     * insert and the site write lock - for every site-scoped model, portal models included.
     *
     * It forks on the EXPERIENCE OF THE REQUEST (Rsx_Portal::is_portal_request()), never on
     * who is signed in: a browser has ONE session carrying BOTH identities, so an identity
     * test gets it wrong in both directions. A portal request is scoped by the site the
     * application DECLARED for the portal (Portal_Session::set_site_id, normally in
     * Portal_Main::init) - which lives in its own column, portal_site_id, and has nothing to
     * do with the staff session's site_id.
     *
     * A portal request with no declared site THROWS (Portal_Session::get_site_id's contract,
     * ruling 12). It is deliberate and it is the whole point: the alternative - quietly
     * scoping to site 0, or to whatever tenant a co-resident staff cookie happens to be on -
     * is a cross-tenant read/write primitive that reports success. The window before
     * Portal_Main::init() runs is not a real one for site-scoped models: Portal_Dispatcher
     * calls it as the FIRST application code in a portal request, before dev auth, CSRF, the
     * #[Auth] gates and every controller.
     *
     * @return int
     */
    public static function get_current_site_id(): int
    {
        if (Rsx_Portal::is_portal_request()) {
            return (int) Portal_Session::get_site_id();
        }

        $site_id = Session::get_site_id();

        // Use site_id 0 if null/empty (global scope)
        if ($site_id === null || $site_id === '') {
            return 0;
        }

        return (int)$site_id;
    }

    /**
     * DISABLED - see AUTOMATIC_SITE_WRITE_LOCKS_ENABLED and backlog B-87.
     *
     * Take this request's WRITE lock on the current tenant. Called automatically by the
     * first save()/insert()/update() a script performs against a site-scoped model.
     *
     * There is no read lock to upgrade FROM: a request that only reads takes no site lock
     * at all. The lock is CLUSTER-wide (rsx-lockd), waits for as long as it takes, and is
     * held until release_site_lock()/shutdown - which is what makes a multi-statement
     * mutation of one tenant atomic against every other writer, on this box or any other.
     *
     * @return void
     */
    protected static function __upgrade_to_write_lock(): void
    {
        if (!self::AUTOMATIC_SITE_WRITE_LOCKS_ENABLED) {
            return;
        }

        $site_id = static::get_current_site_id();

        if (isset(static::$site_lock_tokens[$site_id])) {
            return;
        }

        static::$site_lock_tokens[$site_id] = RsxLocks::site_write_lock($site_id);

        // Register shutdown handler to cleanup (only once)
        static $shutdown_registered = false;
        if (!$shutdown_registered) {
            register_shutdown_function([static::class, 'release_all_site_locks']);
            $shutdown_registered = true;
        }
    }

    /**
     * Release a specific site's lock
     *
     * @param int $site_id The site ID to release lock for
     * @return void
     */
    public static function release_site_lock(int $site_id): void
    {
        if (isset(static::$site_lock_tokens[$site_id])) {
            try {
                RsxLocks::release_lock(static::$site_lock_tokens[$site_id]);
            } catch (Exception $e) {
                // Ignore errors during cleanup
            }
            unset(static::$site_lock_tokens[$site_id]);
        }
    }

    /**
     * Release all site locks
     * Called automatically on shutdown
     *
     * @return void
     */
    public static function release_all_site_locks(): void
    {
        foreach (static::$site_lock_tokens as $site_id => $token) {
            try {
                RsxLocks::release_lock($token);
            } catch (Exception $e) {
                // Ignore errors during cleanup
            }
        }
        static::$site_lock_tokens = [];
    }

    /**
     * FRAMEWORK-INTERNAL. Drop registry entries for site locks that were ALREADY released
     * by someone else - it releases nothing itself.
     *
     * The registry is a "this process already holds site N" cache, so a token released
     * behind its back would leave the cache asserting a lock that is gone: the next write
     * would take the fast path at __upgrade_to_write_lock() and mutate the tenant holding
     * nothing. Sole caller is RsxLocks::_release_since(), which releases task-scoped locks
     * between jobs in a long-lived worker.
     *
     * @param array<int, string> $tokens Lock tokens that have just been released
     * @return void
     */
    public static function _forget_site_lock_tokens(array $tokens): void
    {
        $released = array_flip($tokens);

        foreach (static::$site_lock_tokens as $site_id => $token) {
            if (isset($released[$token])) {
                unset(static::$site_lock_tokens[$site_id]);
            }
        }
    }

    /**
     * Temporarily disable site scoping for admin operations
     *
     * @param callable $callback
     * @return mixed
     */
    public static function without_site_scope(callable $callback)
    {
        $was_applying = static::$apply_site_scope;
        static::$apply_site_scope = false;

        try {
            return $callback();
        } finally {
            static::$apply_site_scope = $was_applying;
        }
    }


    /**
     * Boot the model and install the site-isolation protections.
     *
     * Installs three security controls - the site read-isolation global scope,
     * the creating hook that forces site_id from the session, and the saving
     * hook that validates site_id - plus the defensive retrieved cross-check.
     *
     * booted() IS the extension point. A site-scoped model that needs its own
     * model event hooks overrides booted() and calls parent::booted() as its
     * first statement - the site protections are installed by the parent call.
     * Forgetting parent::booted() would SILENTLY drop all of them (a fail-open
     * cross-tenant read defect); that mistake is now caught fatally at manifest
     * build by PHP-PARENT-CHAIN-01, which requires every override to chain to
     * its parent unless the parent method is #[Replaceable]. This method is NOT
     * #[Replaceable] - it is the archetypal chain-mandatory method.
     */
    protected static function booted()
    {
        parent::booted();

        // Add global scope to filter by site_id
        static::addGlobalScope('site', function (Builder $builder) {
            if (static::$apply_site_scope) {
                $site_id = static::get_current_site_id();
                $builder->where($builder->getModel()->getTable() . '.site_id', $site_id);
            }
        });

        // Automatically set site_id when creating new models
        static::creating(function ($model) {
            if (static::$apply_site_scope) {
                // Always set site_id from session, even if already set
                // This ensures consistency and prevents injection attacks
                $model->site_id = static::get_current_site_id();
            }
        });

        // Validate site_id on save (both create and update)
        static::saving(function ($model) {
            if (static::$apply_site_scope) {
                $current_site_id = static::get_current_site_id();

                // For existing records, ensure site_id hasn't changed
                if ($model->exists) {
                    $original_site_id = $model->getOriginal('site_id');

                    // Fatal error if trying to change site_id
                    if ($model->site_id != $original_site_id) {
                        shouldnt_happen(
                            "Attempted to change site_id from {$original_site_id} to {$model->site_id} " .
                            'on ' . get_class($model) . " ID {$model->id}. " .
                            'Changing site_id is not allowed.'
                        );
                    }

                    // Fatal error if record doesn't belong to current site
                    if ($model->site_id != $current_site_id) {
                        shouldnt_happen(
                            'Attempted to save ' . get_class($model) . " ID {$model->id} " .
                            "with site_id {$model->site_id} but current session site_id is {$current_site_id}. " .
                            'Cross-site saves are not allowed.'
                        );
                    }
                } else {
                    // For new records, force the site_id
                    $model->site_id = $current_site_id;
                }
            }
        });

        // After retrieving records, validate they belong to current site
        static::retrieved(function ($model) {
            if (static::$apply_site_scope) {
                // Skip check if site_id wasn't selected (developer using ->get(['specific', 'columns']))
                if (!isset($model->site_id)) {
                    return;
                }

                $current_site_id = static::get_current_site_id();

                // This shouldn't happen if global scope is working, but double-check
                if ($model->site_id != $current_site_id) {
                    shouldnt_happen(
                        'Retrieved ' . get_class($model) . " ID {$model->id} " .
                        "with site_id {$model->site_id} but current session site_id is {$current_site_id}. " .
                        'Global scope should have prevented this.'
                    );
                }
            }
        });

    }

    /**
     * Override save to handle site locking
     *
     * @param array $options
     * @return bool
     */
    public function save(array $options = [])
    {
        // Always upgrade to write lock for saves
        static::__upgrade_to_write_lock();

        // Additional validation before save
        if (static::$apply_site_scope) {
            $current_site_id = static::get_current_site_id();

            // Ensure we're not trying to save a record from wrong site
            if ($this->exists && $this->site_id != $current_site_id) {
                shouldnt_happen(
                    'Attempted to save ' . get_class($this) . " with site_id {$this->site_id} " .
                    "but current session site_id is {$current_site_id}. " .
                    'This indicates a serious security issue.'
                );
            }

            // Force site_id for new records
            if (!$this->exists) {
                $this->site_id = $current_site_id;
            }
        }

        return parent::save($options);
    }

    /**
     * Override update to handle site locking
     *
     * @param array $attributes
     * @param array $options
     * @return bool
     */
    public function update(array $attributes = [], array $options = [])
    {
        // Fatal if trying to change site_id via update
        if (isset($attributes['site_id']) && static::$apply_site_scope) {
            if ($attributes['site_id'] != $this->site_id) {
                shouldnt_happen(
                    "Attempted to change site_id via update() from {$this->site_id} to {$attributes['site_id']} " .
                    'on ' . get_class($this) . " ID {$this->id}. " .
                    'Changing site_id is never allowed.'
                );
            }

            // Remove site_id from attributes since it shouldn't change
            unset($attributes['site_id']);
        }

        // Always upgrade to write lock for updates
        static::__upgrade_to_write_lock();

        return parent::update($attributes, $options);
    }

    /**
     * Override delete to handle site locking
     *
     * @return bool|null
     */
    public function delete()
    {
        // Always upgrade to write lock for updates
        static::__upgrade_to_write_lock();

        // Validate site ownership before delete
        if (static::$apply_site_scope) {
            $current_site_id = static::get_current_site_id();

            if ($this->site_id != $current_site_id) {
                shouldnt_happen(
                    'Attempted to delete ' . get_class($this) . " ID {$this->id} " .
                    "with site_id {$this->site_id} but current session site_id is {$current_site_id}. " .
                    'Cross-site deletes are not allowed.'
                );
            }
        }

        return parent::delete();
    }

    /**
     * Scope a query to a specific site
     *
     * @param Builder $query
     * @param int $site_id
     * @return Builder
     */
    public function scopeForSite($query, $site_id)
    {
        return $query->where('site_id', $site_id);
    }

    /**
     * Get models for all sites (admin use only)
     *
     * @return Builder
     */
    public static function for_all_sites()
    {
        return static::without_site_scope(function () {
            return static::query();
        });
    }

    /**
     * Create a new model instance for a specific site
     *
     * @param array $attributes
     * @param int|null $site_id Override site_id (admin use only)
     * @return static
     */
    public static function create_for_site(array $attributes = [], ?int $site_id = null)
    {
        if ($site_id !== null && !static::$apply_site_scope) {
            // Admin mode - allow specific site_id
            $attributes['site_id'] = $site_id;
        } else {
            // Normal mode - use session site_id
            $attributes['site_id'] = static::get_current_site_id();
        }

        return static::create($attributes);
    }

    /**
     * Find or create a model for the current site
     *
     * @param array $attributes
     * @param array $values
     * @return static
     */
    public static function first_or_create_for_site(array $attributes, array $values = [])
    {
        $site_id = static::get_current_site_id();
        $attributes['site_id'] = $site_id;
        $values['site_id'] = $site_id;

        return static::firstOrCreate($attributes, $values);
    }
}
