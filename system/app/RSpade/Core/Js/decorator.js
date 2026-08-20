/**
 * Decorator function that marks a function as a decorator implementation.
 *
 * When a function has @decorator in its JSDoc comment, it whitelists that function
 * to be used as a decorator on other methods throughout the codebase.
 *
 * The function itself performs no operation - it simply returns its input unchanged.
 * Its purpose is purely as a marker for the manifest validation system.
 *
 * Usage:
 *   // /**
 *   //  * My custom decorator implementation
 *   //  * @decorator
 *   //  *\/
 *   function my_custom_decorator(target, key, descriptor) {
 *       // Decorator implementation
 *   }
 *
 * This allows my_custom_decorator to be used as @my_custom_decorator on static methods.
 *
 * TODO: This is probably no longer necessary? maybe?
 */
function decorator(value) {
    return value;
}
