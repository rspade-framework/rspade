/**
 * Rsx_Storage - Scoped browser storage helper with graceful degradation
 *
 * Provides safe, scoped access to sessionStorage and localStorage with automatic
 * handling of unavailable storage, quota exceeded errors, and scope invalidation.
 *
 * Key Features:
 * - **Automatic scoping**: All keys scoped by session, user, site, and build version
 * - **Graceful degradation**: Returns null when storage unavailable (private browsing, etc.)
 * - **Quota management**: Auto-clears storage when full and retries operation
 * - **Scope validation**: Clears storage when scope changes (user logout, build update, etc.)
 * - **Developer-friendly keys**: Scoped suffix allows easy inspection in dev tools
 *
 * Scoping Strategy:
 * The scope suffix is `window.rsxapp.cache_key` (via Rsx.scope_key()) - a single
 * server-computed md5 of session_hash + user id + site id + build_key
 * (Rsx_Bundle_Abstract::_compute_cache_key). Scope is derived server-side so the
 * client never assembles it from session_hash; the ingredients are:
 * - `session_hash` (hashed session identifier, non-reversible)
 * - `user.id` (current user)
 * - `site.id` (current site)
 * - `build_key` (application version)
 *
 * This scope is stored in `_rsx_scope_key`. If the scope changes between page loads,
 * all RSpade keys are cleared to prevent stale data from different sessions/users/builds.
 *
 * Key Format:
 * Keys are stored as: `rsx::developer_key::scope_suffix`
 * Example: `rsx::flash_queue::9f8e7d6c5b4a3021...` (a 32-char md5)
 *
 * The `rsx::` prefix identifies RSpade keys, allowing safe clearing of only our keys
 * without affecting other JavaScript libraries. This enables transparent coexistence
 * with third-party libraries that also use browser storage.
 *
 * Quota Exceeded Handling:
 * When storage quota is exceeded during a set operation, only RSpade keys (prefixed with
 * `rsx::`) are cleared, preserving other libraries' data. The operation is then retried
 * once. This ensures the application continues functioning even when storage is full.
 *
 * Usage:
 *   // Session storage (cleared on tab close)
 *   Rsx_Storage.session_set('user_preferences', {theme: 'dark'});
 *   const prefs = Rsx_Storage.session_get('user_preferences');
 *   Rsx_Storage.session_remove('user_preferences');
 *
 *   // Local storage (persists across sessions)
 *   Rsx_Storage.local_set('cached_data', {items: [...]});
 *   const data = Rsx_Storage.local_get('cached_data');
 *   Rsx_Storage.local_remove('cached_data');
 *
 * IMPORTANT - Volatile Storage:
 * Storage can be cleared at any time due to:
 * - User clearing browser data
 * - Private browsing mode restrictions
 * - Quota exceeded errors
 * - Scope changes (logout, build update, session change)
 * - Browser storage unavailable
 *
 * Therefore, NEVER store critical data that impacts application functionality.
 * Only store:
 * - Cached data (performance optimization)
 * - UI state (convenience, not required)
 * - Transient messages (flash alerts, notifications)
 *
 * If the data is required for the application to function, store it server-side.
 */
class Rsx_Storage {
    static _scope_suffix = null;
    static _session_available = null;
    static _local_available = null;

    /**
     * Initialize storage system and validate scope
     * Called automatically on first access
     * @private
     */
    static _init() {
        // Check if already initialized
        if (this._scope_suffix !== null) {
            return;
        }

        // Check storage availability
        this._session_available = this._is_storage_available('sessionStorage');
        this._local_available = this._is_storage_available('localStorage');

        // Calculate current scope suffix
        this._scope_suffix = Rsx.scope_key();

        // Validate scope for both storages
        if (this._session_available) {
            this._validate_scope(sessionStorage, 'session');
        }
        if (this._local_available) {
            this._validate_scope(localStorage, 'local');
        }
    }

    /**
     * Check if a storage type is available
     * @param {string} type - 'sessionStorage' or 'localStorage'
     * @returns {boolean}
     * @private
     */
    static _is_storage_available(type) {
        try {
            const storage = window[type];
            const test = '__rsx_storage_test__';
            storage.setItem(test, test);
            storage.removeItem(test);
            return true;
        } catch (e) {
            return false;
        }
    }

    /**
     * Validate storage scope and clear RSpade keys if changed
     * Only clears keys prefixed with 'rsx::' to preserve other libraries' data
     * @param {Storage} storage - sessionStorage or localStorage
     * @param {string} type - 'session' or 'local' (for logging)
     * @private
     */
    static _validate_scope(storage, type) {
        try {
            const stored_scope = storage.getItem('_rsx_scope_key');

            // If scope key exists and has changed, clear only RSpade keys
            if (stored_scope !== null && stored_scope !== this._scope_suffix) {
                console.log(`[Rsx_Storage] Scope changed for ${type}Storage, clearing RSpade keys only:`, {
                    old_scope: stored_scope,
                    new_scope: this._scope_suffix,
                });
                this._clear_rsx_keys(storage);
                storage.setItem('_rsx_scope_key', this._scope_suffix);
            } else if (stored_scope === null) {
                // First time RSpade is using this storage - just set the key, don't clear
                console.log(`[Rsx_Storage] Initializing scope for ${type}Storage (first use):`, {
                    new_scope: this._scope_suffix,
                });
                storage.setItem('_rsx_scope_key', this._scope_suffix);
            }
        } catch (e) {
            console.error(`[Rsx_Storage] Failed to validate scope for ${type}Storage:`, e);
        }
    }

    /**
     * Clear only RSpade keys from storage (keys starting with 'rsx::')
     * Preserves keys from other libraries
     * @param {Storage} storage - sessionStorage or localStorage
     * @private
     */
    static _clear_rsx_keys(storage) {
        const keys_to_remove = [];

        // Collect all RSpade keys
        for (let i = 0; i < storage.length; i++) {
            const key = storage.key(i);
            if (key && key.startsWith('rsx::')) {  // @JS-DEFENSIVE-01-EXCEPTION - Browser API storage.key(i) can return null when i >= storage.length
                keys_to_remove.push(key);
            }
        }

        // Remove collected keys
        keys_to_remove.forEach((key) => {
            try {
                storage.removeItem(key);
            } catch (e) {
                console.error('[Rsx_Storage] Failed to remove key:', key, e);
            }
        });

        console.log(`[Rsx_Storage] Cleared ${keys_to_remove.length} RSpade keys`);
    }

    /**
     * Build scoped key with RSpade namespace prefix
     * @param {string} key - Developer-provided key
     * @returns {string}
     * @private
     */
    static _build_key(key) {
        return `rsx::${key}::${this._scope_suffix}`;
    }

    /**
     * Set item in sessionStorage
     * @param {string} key - Storage key
     * @param {*} value - Value to store (will be JSON serialized)
     */
    static session_set(key, value) {
        this._init();

        if (!this._session_available) {
            return;
        }

        this._set_item(sessionStorage, key, value, 'session');
    }

    /**
     * Get item from sessionStorage
     * @param {string} key - Storage key
     * @returns {*|null} Parsed value or null if not found/unavailable
     */
    static session_get(key) {
        this._init();

        if (!this._session_available) {
            return null;
        }

        return this._get_item(sessionStorage, key);
    }

    /**
     * Remove item from sessionStorage
     * @param {string} key - Storage key
     */
    static session_remove(key) {
        this._init();

        if (!this._session_available) {
            return;
        }

        this._remove_item(sessionStorage, key);
    }

    /**
     * Set item in localStorage
     * @param {string} key - Storage key
     * @param {*} value - Value to store (will be JSON serialized)
     */
    static local_set(key, value) {
        this._init();

        if (!this._local_available) {
            return;
        }

        this._set_item(localStorage, key, value, 'local');
    }

    /**
     * Get item from localStorage
     * @param {string} key - Storage key
     * @returns {*|null} Parsed value or null if not found/unavailable
     */
    static local_get(key) {
        this._init();

        if (!this._local_available) {
            return null;
        }

        return this._get_item(localStorage, key);
    }

    /**
     * Remove item from localStorage
     * @param {string} key - Storage key
     */
    static local_remove(key) {
        this._init();

        if (!this._local_available) {
            return;
        }

        this._remove_item(localStorage, key);
    }

    /**
     * Internal set implementation with scope validation and quota handling
     * @param {Storage} storage
     * @param {string} key
     * @param {*} value
     * @param {string} type - 'session' or 'local' (for logging)
     * @private
     */
    static _set_item(storage, key, value, type) {
        // Validate scope before every write
        this._validate_scope(storage, type);

        const scoped_key = this._build_key(key);

        try {
            const serialized = JSON.stringify(value);

            // Check size - skip if larger than 1MB
            const size_bytes = new Blob([serialized]).size;
            const size_mb = size_bytes / (1024 * 1024);

            if (size_mb > 1) {
                console.warn(`[Rsx_Storage] Skipping storage for key "${key}" - data too large (${size_mb.toFixed(2)} MB, limit 1 MB)`);
                return;
            }

            storage.setItem(scoped_key, serialized);
        } catch (e) {
            // Check if quota exceeded
            if (e.name === 'QuotaExceededError' || e.code === 22) {
                console.warn(`[Rsx_Storage] Quota exceeded for ${type}Storage, clearing RSpade keys and retrying`);

                // Clear only RSpade keys and retry once
                this._clear_rsx_keys(storage);
                storage.setItem('_rsx_scope_key', this._scope_suffix);

                try {
                    const serialized = JSON.stringify(value);
                    storage.setItem(scoped_key, serialized);
                } catch (retry_error) {
                    console.error(`[Rsx_Storage] Failed to set item after clearing RSpade keys from ${type}Storage:`, retry_error);
                }
            } else {
                console.error(`[Rsx_Storage] Failed to set item in ${type}Storage:`, e);
            }
        }
    }

    /**
     * Internal get implementation
     * @param {Storage} storage
     * @param {string} key
     * @returns {*|null}
     * @private
     */
    static _get_item(storage, key) {
        const scoped_key = this._build_key(key);

        try {
            const serialized = storage.getItem(scoped_key);
            if (serialized === null) {
                return null;
            }
            return JSON.parse(serialized);
        } catch (e) {
            console.error('[Rsx_Storage] Failed to get item:', e);
            return null;
        }
    }

    /**
     * Internal remove implementation
     * @param {Storage} storage
     * @param {string} key
     * @private
     */
    static _remove_item(storage, key) {
        const scoped_key = this._build_key(key);

        try {
            storage.removeItem(scoped_key);
        } catch (e) {
            console.error('[Rsx_Storage] Failed to remove item:', e);
        }
    }
}
