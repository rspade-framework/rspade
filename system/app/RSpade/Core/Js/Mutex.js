/**
 * Mutex decorator for exclusive method execution
 *
 * Without arguments: Per-instance locking (each object has its own lock per method)
 *   @mutex
 *   async my_method() { ... }
 *
 * With ID argument: Global locking by ID (all instances share the lock)
 *   @mutex('operation_name')
 *   async my_method() { ... }
 *
 * Uses the 2023-11 decorator proposal (Stage 3).
 * Decorator receives (value, context) where:
 *   - value: the original method function
 *   - context: { kind, name, static, private, access, addInitializer }
 *
 * @decorator
 * @param {string} [global_id] - Optional global mutex ID for cross-instance locking
 */
function mutex(arg) {
    // Storage for mutex locks
    const instance_mutexes = (function() {
        if (!mutex._instance_storage) {
            mutex._instance_storage = new WeakMap();
        }
        return mutex._instance_storage;
    })();

    const global_mutexes = (function() {
        if (!mutex._global_storage) {
            mutex._global_storage = new Map();
        }
        return mutex._global_storage;
    })();

    /**
     * Get or create a mutex for a specific instance and method
     */
    function get_instance_mutex(instance, method_name) {
        let instance_locks = instance_mutexes.get(instance);
        if (!instance_locks) {
            instance_locks = new Map();
            instance_mutexes.set(instance, instance_locks);
        }

        let lock_state = instance_locks.get(method_name);
        if (!lock_state) {
            lock_state = { active: false, queue: [] };
            instance_locks.set(method_name, lock_state);
        }

        return lock_state;
    }

    /**
     * Get or create a global mutex by ID
     */
    function get_global_mutex(id) {
        let lock_state = global_mutexes.get(id);
        if (!lock_state) {
            lock_state = { active: false, queue: [] };
            global_mutexes.set(id, lock_state);
        }
        return lock_state;
    }

    /**
     * Execute the next queued operation for a mutex
     */
    function schedule_next(lock_state) {
        if (lock_state.active || lock_state.queue.length === 0) {
            return;
        }

        const { fn, resolve, reject } = lock_state.queue.shift();
        lock_state.active = true;

        Promise.resolve()
            .then(fn)
            .then(resolve, reject)
            .finally(() => {
                lock_state.active = false;
                schedule_next(lock_state);
            });
    }

    /**
     * Acquire a mutex lock and execute callback
     */
    function acquire_lock(lock_state, fn) {
        return new Promise((resolve, reject) => {
            lock_state.queue.push({ fn, resolve, reject });
            schedule_next(lock_state);
        });
    }

    /**
     * Create the wrapper method for a given original method
     * @param {Function} original_method - The original method
     * @param {string} method_name - The method name
     * @param {string|null} global_id - Global mutex ID (null for instance-level)
     */
    function create_mutex_wrapper(original_method, method_name, global_id) {
        return function(...args) {
            let lock_state;
            if (global_id) {
                lock_state = get_global_mutex(global_id);
            } else {
                lock_state = get_instance_mutex(this, method_name);
            }
            return acquire_lock(lock_state, () => original_method.apply(this, args));
        };
    }

    // 2023-11 decorator spec: decorators receive (value, context)
    // If called with a string argument: @mutex('id') - returns decorator function
    // If called directly on method: @mutex - arg is the method function, second arg is context

    if (typeof arg === 'string') {
        // Called with ID: @mutex('operation_name')
        // Returns a decorator function
        const global_id = arg;
        return function(value, context) {
            if (context.kind !== 'method') {
                throw new Error(`@mutex can only be applied to methods, not ${context.kind}`);
            }
            return create_mutex_wrapper(value, context.name, global_id);
        };
    }

    // Called without arguments: @mutex
    // In 2023-11 spec, arg is the method function, second argument is context
    const value = arg;
    const context = arguments[1];

    if (!context || context.kind !== 'method') {
        throw new Error(`@mutex can only be applied to methods`);
    }

    return create_mutex_wrapper(value, context.name, null);
}
