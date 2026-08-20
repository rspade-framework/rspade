/*
 * Hashing and comparison utility functions for the RSpade framework.
 * These functions handle object hashing and deep comparison.
 */

// ============================================================================
// HASHING AND COMPARISON
// ============================================================================

/**
 * Generates a unique hash for any value (handles objects, arrays, circular references)
 * @param {*} the_var - Value to hash
 * @param {boolean} [calc_sha1=true] - If true, returns SHA1 hash; if false, returns JSON
 * @param {Array<string>} [ignored_keys=null] - Keys to ignore when hashing objects
 * @returns {string} SHA1 hash or JSON string of the value
 */
function hash(the_var, calc_sha1 = true, ignored_keys = null) {
    if (typeof the_var == undef) {
        the_var = '__undefined__';
    }

    if (ignored_keys === null) {
        ignored_keys = ['$'];
    }

    // Converts value to json, discarding circular references
    let json_stringify_nocirc = function (value) {
        const cache = [];
        return JSON.stringify(value, function (key, v) {
            if (typeof v === 'object' && typeof the_var._cache_key == 'function') {
                return the_var._hash_key();
            } else if (typeof v === 'object' && v !== null) {
                if (cache.indexOf(v) !== -1) {
                    // Duplicate reference found, discard key
                    return;
                }
                cache.push(v);
            }
            return v;
        });
    };

    // Turn every property and all its children into a single depth array of values that we can then
    // sort and hash as a whole
    let flat_var = {};
    let _flatten = function (the_var, prefix, depth = 0) {
        // If a class object is provided, circular references can make the call stack recursive.
        // For the purposes of how the hash function is called, this should be sufficient.
        if (depth > 10) {
            return;
        }

        // Does not account for dates i think...

        if (is_object(the_var) && typeof the_var._cache_key == 'function') {
            // Use _cache_key to hash components
            flat_var[prefix] = the_var._hash_key();
        } else if (is_object(the_var) && typeof Abstract !== 'undefined' && the_var instanceof Abstract) {
            // Stringify all class objects
            flat_var[prefix] = json_stringify_nocirc(the_var);
        } else if (is_object(the_var)) {
            // Iterate other objects
            flat_var[prefix] = {};
            for (let k in the_var) {
                if (the_var.hasOwnProperty(k) && ignored_keys.indexOf(k) == -1) {
                    _flatten(the_var[k], prefix + '..' + k, depth + 1);
                }
            }
        } else if (is_array(the_var)) {
            // Iterate arrays
            flat_var[prefix] = [];
            let i = 0;
            foreach(the_var, (v) => {
                _flatten(v, prefix + '..' + i, depth + 1);
                i++;
            });
        } else if (is_function(the_var)) {
            // nothing
        } else if (!is_numeric(the_var)) {
            flat_var[prefix] = String(the_var);
        } else {
            flat_var[prefix] = the_var;
        }
    };

    _flatten(the_var, '_');

    let sorter = [];

    foreach(flat_var, function (v, k) {
        sorter.push([k, v]);
    });

    // Canonicalize: sort by flattened key path so property order never affects the
    // hash (a boolean comparator here would not sort at all - sort() needs -1/0/1).
    sorter.sort(function (a, b) {
        return a[0] < b[0] ? -1 : (a[0] > b[0] ? 1 : 0);
    });

    let json = JSON.stringify(sorter);

    if (calc_sha1) {
        // sha1 = the js-sha1 vendor global (Core_Bundle npm map); callable directly.
        return sha1(json);
    } else {
        return json;
    }
}

/**
 * Deep comparison of two values (ignores property order and functions)
 * @param {*} a - First value to compare
 * @param {*} b - Second value to compare
 * @returns {boolean} True if values are deeply equal
 */
function deep_equal(a, b) {
    return hash(a, false) == hash(b, false);
}