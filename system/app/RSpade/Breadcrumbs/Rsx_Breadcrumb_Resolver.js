/**
 * Rsx_Breadcrumb_Resolver - Progressive breadcrumb resolution with caching
 *
 * Provides instant breadcrumb rendering using cached data while resolving
 * live labels progressively in the background.
 *
 * Resolution Flow:
 * 1. Keep previous breadcrumbs visible initially
 * 2. Try to load cached chain - if complete, display immediately
 * 3. Walk breadcrumb_parent() chain to get structure (URLs)
 * 4. Compare structure to cache:
 *    - Different length or no cache shown: render skeleton with blank labels
 *    - Same length: render skeleton pre-populated with cached labels
 * 5. Progressively resolve each label via breadcrumb_label()/breadcrumb_label_active()
 * 6. Cache final result when all labels resolved
 *
 * Important: Actions that need loaded data in breadcrumb methods should
 * await their own 'loaded' event internally, not rely on ready() being called.
 *
 * Usage:
 *   const cancel = Rsx_Breadcrumb_Resolver.stream(action, url, callbacks);
 */
class Rsx_Breadcrumb_Resolver {
    /**
     * Generation counter for cancellation detection.
     */
    static _generation = 0;

    /**
     * Cache key prefix for breadcrumb chains
     */
    static CACHE_PREFIX = 'breadcrumb_chain:';

    /**
     * Stream breadcrumb data as it becomes available
     *
     * @param {Spa_Action} action - The active action instance
     * @param {string} url - Current URL (pathname + search, no hash)
     * @param {object} callbacks - Callback functions:
     *   - on_chain(chain): Called when chain structure is ready (may have null labels)
     *   - on_label_update(index, label): Called when individual label resolves
     *   - on_complete(chain): Called when all labels resolved
     * @returns {Function} Cancel function - call to stop streaming
     */
    static stream(action, url, callbacks) {
        const generation = ++Rsx_Breadcrumb_Resolver._generation;

        // Normalize URL (strip hash if present)
        const normalized_url = Rsx_Breadcrumb_Resolver._normalize_url(url);

        // Start async resolution
        Rsx_Breadcrumb_Resolver._resolve(action, normalized_url, generation, callbacks);

        // Return cancel function
        return () => {
            if (generation === Rsx_Breadcrumb_Resolver._generation) {
                Rsx_Breadcrumb_Resolver._generation++;
            }
        };
    }

    /**
     * Normalize URL by stripping hash
     */
    static _normalize_url(url) {
        const hash_index = url.indexOf('#');
        return hash_index >= 0 ? url.substring(0, hash_index) : url;
    }

    /**
     * Check if resolution should continue (not cancelled)
     */
    static _is_active(generation) {
        return generation === Rsx_Breadcrumb_Resolver._generation;
    }

    /**
     * Build cache key (Rsx_Storage already scopes by build_key, so no manual append)
     */
    static _cache_key(url) {
        return Rsx_Breadcrumb_Resolver.CACHE_PREFIX + url;
    }

    /**
     * Get cached chain for a URL
     */
    static _get_cached_chain(url) {
        const cache_key = Rsx_Breadcrumb_Resolver._cache_key(url);
        return Rsx_Storage.session_get(cache_key);
    }

    /**
     * Cache a resolved chain
     */
    static _cache_chain(url, chain) {
        const cache_key = Rsx_Breadcrumb_Resolver._cache_key(url);
        // Store simplified chain data (url, label, is_active)
        const cache_data = chain.map(item => ({
            url: item.url,
            label: item.label,
            is_active: item.is_active
        }));
        Rsx_Storage.session_set(cache_key, cache_data);
    }

    /**
     * Main resolution orchestrator
     */
    static async _resolve(action, url, generation, callbacks) {
        // Step 1: Try to get cached chain
        const cached_chain = Rsx_Breadcrumb_Resolver._get_cached_chain(url);
        let showed_cached = false;

        if (cached_chain && is_array(cached_chain) && cached_chain.length > 0) {
            // Show cached chain immediately
            if (!Rsx_Breadcrumb_Resolver._is_active(generation)) return;
            callbacks.on_chain(cached_chain.map(item => ({
                url: item.url,
                label: item.label,
                is_active: item.is_active,
                resolved: true
            })));
            showed_cached = true;
        }

        // Step 2: Walk the chain structure to get URLs
        if (!Rsx_Breadcrumb_Resolver._is_active(generation)) return;

        const chain_structure = await Rsx_Breadcrumb_Resolver._walk_chain_structure(action, url, generation);
        if (!chain_structure || !Rsx_Breadcrumb_Resolver._is_active(generation)) return;

        // Step 3: Compare to cache and build display chain
        const chain_length_matches = showed_cached && cached_chain.length === chain_structure.length;
        const should_use_cached_labels = showed_cached && chain_length_matches;

        // Build the chain with either cached labels or nulls
        const chain = chain_structure.map((item, index) => {
            let label = null;
            if (should_use_cached_labels && cached_chain[index]) {
                label = cached_chain[index].label;
            }
            return {
                url: item.url,
                label: label,
                is_active: item.is_active,
                resolved: false,
                action: item.action // Keep action reference for label resolution
            };
        });

        // Step 4: Emit chain structure (with cached labels or nulls)
        // Only emit if we didn't show cached OR if structure differs
        if (!showed_cached || !chain_length_matches) {
            if (!Rsx_Breadcrumb_Resolver._is_active(generation)) return;
            callbacks.on_chain(chain.map(item => ({
                url: item.url,
                label: item.label,
                is_active: item.is_active,
                resolved: item.label !== null
            })));
        }

        // Step 5: Resolve labels progressively
        const label_promises = chain.map(async (item, index) => {
            if (!Rsx_Breadcrumb_Resolver._is_active(generation)) return;

            try {
                let label;
                if (item.is_active) {
                    // Active item uses breadcrumb_label_active()
                    label = await item.action.breadcrumb_label_active();
                } else {
                    // Parent items use breadcrumb_label()
                    label = await item.action.breadcrumb_label();
                }

                if (!Rsx_Breadcrumb_Resolver._is_active(generation)) return;

                // Update chain and notify
                chain[index].label = label;
                chain[index].resolved = true;
                callbacks.on_label_update(index, label);
            } catch (e) {
                console.warn('Breadcrumb label resolution failed:', e);
                // Use generic label when resolution fails
                chain[index].label = item.is_active ? 'Page' : 'Link';
                chain[index].resolved = true;
                callbacks.on_label_update(index, chain[index].label);
            }
        });

        // Wait for all labels to resolve
        await Promise.all(label_promises);

        if (!Rsx_Breadcrumb_Resolver._is_active(generation)) return;

        // Step 6: Cache the final result and notify completion
        Rsx_Breadcrumb_Resolver._cache_chain(url, chain);

        callbacks.on_complete(chain.map(item => ({
            url: item.url,
            label: item.label,
            is_active: item.is_active,
            resolved: true
        })));
    }

    /**
     * Walk the chain structure by following breadcrumb_parent()
     * Returns array of {url, is_active, action} from root to current
     */
    static async _walk_chain_structure(action, url, generation) {
        const chain = [];

        // Start with current action
        let current_url = url;
        let current_action = action;

        while (current_action && current_url) {
            if (!Rsx_Breadcrumb_Resolver._is_active(generation)) return null;

            chain.unshift({
                url: current_url,
                is_active: current_url === url,
                action: current_action
            });

            // Get parent URL
            let parent_url = null;
            try {
                parent_url = await current_action.breadcrumb_parent();
            } catch (e) {
                console.warn('breadcrumb_parent() failed:', e);
            }

            if (!parent_url) break;

            // Load parent action (detached)
            if (!Rsx_Breadcrumb_Resolver._is_active(generation)) return null;

            const parent_action = await Spa.load_detached_action(parent_url, {
                use_cached_data: true
            });

            if (!parent_action) break;

            current_url = parent_url;
            current_action = parent_action;
        }

        return chain;
    }

    /**
     * Clear cached chain for a URL (if needed for invalidation)
     */
    static clear_cache(url) {
        if (url) {
            const cache_key = Rsx_Breadcrumb_Resolver._cache_key(url);
            Rsx_Storage.session_remove(cache_key);
        }
    }
}
