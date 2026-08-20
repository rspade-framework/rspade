/**
 * Manifest - JavaScript class registry and metadata system
 *
 * This class maintains a registry of all JavaScript classes in the bundle,
 * tracking their names and inheritance relationships. It provides utilities
 * for working with class hierarchies and calling initialization methods.
 */
class Manifest {
    /**
     * Define classes in the manifest (framework internal)
     * @param {Array} items - Array of class definitions [[Class, "ClassName", ParentClass, decorators], ...]
     */
    static _define(items) {
        // Initialize the classes object if not already defined
        if (typeof Manifest._classes === 'undefined') {
            Manifest._classes = {};
        }

        // Process each class definition
        // Per-item try/catch exists ONLY for attribution - every failure is rethrown below
        const failures = [];

        items.forEach((item) => {
            let class_name = item[1];

            try {
                let class_object = item[0];
                let class_extends = item[2] || null;
                let decorators = item[3] || null;

                // Store the class information (using object to avoid duplicates)
                Manifest._classes[class_name] = {
                    class: class_object,
                    name: class_name,
                    extends: class_extends,
                    decorators: decorators,  // Store compact decorator data
                };

                // Add metadata to the class object itself
                class_object._name = class_name;
                class_object._extends = class_extends;
                class_object._decorators = decorators;
            } catch (error) {
                failures.push({ class_name: class_name, error: error });
            }
        });

        Manifest._throw_boot_failures(failures, 'Manifest._define (class registration)');

        // Build the subclass index after all classes are defined
        Manifest._build_subclass_index();
    }

    /**
     * Framework internal: rethrow collected per-class boot failures with attribution
     *
     * Boot stays FATAL. The per-item try/catch at the boot loci exists only so the
     * surfaced error NAMES the class that failed instead of dying anonymously
     * mid-iteration. Every additional failure is logged before the throw so a run
     * with several broken classes reports all of them.
     *
     * @param {Array} failures - [{class_name, error}, ...] collected by the caller
     * @param {string} phase - Human-readable boot phase, used in the error message
     */
    static _throw_boot_failures(failures, phase) {
        if (failures.length === 0) {
            return;
        }

        // Report every additional failure before dying on the first one
        for (let i = 1; i < failures.length; i++) {
            console.error(`[RSX_BOOT] Additional failure in class '${failures[i].class_name}' during ${phase}:`, failures[i].error);
        }

        const first = failures[0];
        const original = first.error;

        // A thrown value is not guaranteed to be an Error instance
        const original_message = (original instanceof Error) ? original.message : String(original);
        const original_stack = (original instanceof Error && original.stack) ? original.stack : original_message;

        // Error cause option is ES2022; the bundle targets es2020, so attach it as a property
        const wrapped = new Error(
            `Boot failure in class '${first.class_name}' during ${phase}: ${original_message}\n` +
            `--- original error ---\n${original_stack}`
        );
        wrapped.cause = original;

        throw wrapped;
    }

    /**
     * Build an index of subclasses for efficient lookups
     * This creates a mapping where each class name points to an array of all its subclasses
     * @private
     */
    static _build_subclass_index() {
        // Initialize the subclass index
        Manifest._subclass_index = {};

        // Step through each class and walk up its parent chain
        for (let class_name in Manifest._classes) {
            const classdata = Manifest._classes[class_name];
            let current_class_name = class_name;
            let current_classdata = classdata;

            // Walk up the parent chain until we reach the root
            while (current_classdata) {
                const extends_name = current_classdata.extends;

                if (extends_name) {
                    // Initialize the parent's subclass array if needed
                    if (!Manifest._subclass_index[extends_name]) {
                        Manifest._subclass_index[extends_name] = [];
                    }

                    // Add this class to its parent's subclass list
                    if (!Manifest._subclass_index[extends_name].includes(class_name)) {
                        Manifest._subclass_index[extends_name].push(class_name);
                    }

                    // Move up to the parent's metadata (if it exists in manifest)
                    if (Manifest._classes[extends_name]) {
                        current_classdata = Manifest._classes[extends_name];
                    } else {
                        // Parent not in manifest (e.g., native JavaScript class), stop here
                        current_classdata = null;
                    }
                } else {
                    // No parent, we've reached the root
                    current_classdata = null;
                }
            }
        }
    }

    /**
     * Get all classes that extend a given base class
     * @param {Class|string} base_class - The base class (object or name string) to check for
     * @returns {Array} Array of objects with {class_name, class_object} for classes that extend the base class
     */
    static get_extending(base_class) {
        if (!Manifest._classes) {
            return [];
        }

        // Convert string to class object if needed
        let base_class_object = base_class;
        if (typeof base_class === 'string') {
            base_class_object = Manifest.get_class_by_name(base_class);
            if (!base_class_object) {
                throw new Error(`Base class not found: ${base_class}`);
            }
        }

        const classes = [];

        for (let class_name in Manifest._classes) {
            const classdata = Manifest._classes[class_name];
            if (Manifest.js_is_subclass_of(classdata.class, base_class_object)) {
                classes.push({
                    class_name: class_name,
                    class_object: classdata.class,
                });
            }
        }

        // Sort alphabetically by class name to ensure deterministic behavior and prevent race condition bugs
        classes.sort((a, b) => a.class_name.localeCompare(b.class_name));

        return classes;
    }

    /**
     * Check if a class is a subclass of another class
     * Matches PHP Manifest::js_is_subclass_of() signature and behavior
     * @param {Class|string} subclass - The child class (object or name) to check
     * @param {Class|string} superclass - The parent class (object or name) to check against
     * @returns {boolean} True if subclass extends superclass (directly or indirectly)
     */
    static js_is_subclass_of(subclass, superclass) {
        // Convert string names to class objects
        let subclass_object = subclass;
        if (typeof subclass === 'string') {
            subclass_object = Manifest.get_class_by_name(subclass);
            if (!subclass_object) {
                // Can't resolve subclass - return false per spec
                return false;
            }
        }

        let superclass_object = superclass;
        if (typeof superclass === 'string') {
            superclass_object = Manifest.get_class_by_name(superclass);
            if (!superclass_object) {
                // Can't resolve superclass - fail loud per spec
                throw new Error(`Superclass not found in manifest: ${superclass}`);
            }
        }

        // Classes are not subclasses of themselves
        if (subclass_object === superclass_object) {
            return false;
        }

        // Walk up the inheritance chain
        let current_class = subclass_object;
        while (current_class) {
            if (current_class === superclass_object) {
                return true;
            }
            // Move up to parent class
            if (current_class._extends) {
                // _extends may be a string or class reference
                if (typeof current_class._extends === 'string') {
                    current_class = Manifest.get_class_by_name(current_class._extends);
                } else {
                    current_class = current_class._extends;
                }
            } else {
                current_class = null;
            }
        }

        return false;
    }

    /**
     * Get a class by its name
     * @param {string} class_name - The name of the class
     * @returns {Class|null} The class object or null if not found
     */
    static get_class_by_name(class_name) {
        if (!Manifest._classes || !Manifest._classes[class_name]) {
            return null;
        }

        return Manifest._classes[class_name].class;
    }

    /**
     * Get all registered classes
     * @returns {Array} Array of objects with {class_name, class_object, extends}
     */
    static get_all_classes() {
        if (!Manifest._classes) {
            return [];
        }

        const results = [];
        for (let class_name in Manifest._classes) {
            const classdata = Manifest._classes[class_name];
            results.push({
                class_name: classdata.name,
                class_object: classdata.class,
                extends: classdata.extends,
            });
        }

        // Sort alphabetically by class name to ensure deterministic behavior and prevent race condition bugs
        results.sort((a, b) => a.class_name.localeCompare(b.class_name));

        return results;
    }

    /**
     * Get the build key from the application configuration
     * @returns {string} The build key or "NOBUILD" if not available
     */
    static build_key() {
        if (window.rsxapp && window.rsxapp.build_key) {
            return window.rsxapp.build_key;
        }
        return 'NOBUILD';
    }

    /**
     * Get decorators for a specific class and method
     * @param {string|Class} class_name - The class name or class object
     * @param {string} method_name - The method name
     * @returns {Array|null} Array of decorator objects or null if none found
     */
    static get_decorators(class_name, method_name) {
        // Convert class object to name if needed
        if (typeof class_name !== 'string') {
            class_name = class_name._name || class_name.name;
        }

        const class_info = Manifest._classes[class_name];
        if (!class_info || !class_info.decorators || !class_info.decorators[method_name]) {
            return null;
        }

        // Transform compact format to object format
        return Manifest._transform_decorators(class_info.decorators[method_name]);
    }

    /**
     * Get all methods with decorators for a class
     * @param {string|Class} class_name - The class name or class object
     * @returns {Object} Object with method names as keys and decorator arrays as values
     */
    static get_all_decorators(class_name) {
        // Convert class object to name if needed
        if (typeof class_name !== 'string') {
            class_name = class_name._name || class_name.name;
        }

        const class_info = Manifest._classes[class_name];
        if (!class_info || !class_info.decorators) {
            return {};
        }

        // Transform all decorators from compact to object format
        const result = {};
        for (let method_name in class_info.decorators) {
            result[method_name] = Manifest._transform_decorators(class_info.decorators[method_name]);
        }
        return result;
    }

    /**
     * Transform compact decorator format to object format
     * @param {Array} compact_decorators - Array of [name, [args]] tuples
     * @returns {Array} Array of decorator objects with name and arguments properties
     * @private
     */
    static _transform_decorators(compact_decorators) {
        if (!Array.isArray(compact_decorators)) {
            return [];
        }

        return compact_decorators.map(decorator => {
            if (Array.isArray(decorator) && decorator.length >= 2) {
                return {
                    name: decorator[0],
                    arguments: decorator[1] || []
                };
            }
            // Handle malformed decorator data
            return {
                name: 'unknown',
                arguments: []
            };
        });
    }

    /**
     * Check if a method has a specific decorator
     * @param {string|Class} class_name - The class name or class object
     * @param {string} method_name - The method name
     * @param {string} decorator_name - The decorator name to check for
     * @returns {boolean} True if the method has the decorator
     */
    static has_decorator(class_name, method_name, decorator_name) {
        const decorators = Manifest.get_decorators(class_name, method_name);
        if (!decorators) {
            return false;
        }

        return decorators.some(d => d.name === decorator_name);
    }

    /**
     * Get all subclasses of a given class using the pre-built index
     * This is the JavaScript equivalent of PHP's Manifest::js_get_subclasses_of()
     * @param {Class|string} base_class - The base class (object or name string) to get subclasses of
     * @returns {Array<Class>} Array of actual class objects that are subclasses of the base class
     */
    static js_get_subclasses_of(base_class) {
        // Initialize index if needed
        if (!Manifest._subclass_index) {
            Manifest._build_subclass_index();
        }

        // Convert class object to name if needed
        let base_class_name = base_class;
        if (typeof base_class !== 'string') {
            base_class_name = base_class._name || base_class.name;
        }

        // Check if the base class exists
        if (!Manifest._classes[base_class_name]) {
            // Base class not in manifest - return empty array
            return [];
        }

        // Get subclass names from the index
        const subclass_names = Manifest._subclass_index[base_class_name] || [];

        // Convert names to actual class objects
        const subclass_objects = [];
        for (let subclass_name of subclass_names) {
            const classdata = Manifest._classes[subclass_name];
            subclass_objects.push(classdata.class);
        }

        // Sort by class name for deterministic behavior
        subclass_objects.sort((a, b) => {
            const name_a = a._name || a.name;
            const name_b = b._name || b.name;
            return name_a.localeCompare(name_b);
        });

        return subclass_objects;
    }
}

// RSX manifest automatically makes classes global - no manual assignment needed
