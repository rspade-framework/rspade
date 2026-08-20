/**
 * Responsive - JavaScript breakpoint detection for RSX two-tier responsive system
 *
 * Provides runtime detection of current viewport against CSS breakpoint values.
 * Values are read from CSS custom properties defined in rsx/theme/responsive.scss.
 *
 * Two-tier system:
 *   Tier 1 (Semantic): is_mobile() / is_desktop() - broad categories
 *   Tier 2 (Granular): is_phone() / is_tablet() / is_desktop_sm() / is_desktop_md() / is_desktop_lg() / is_desktop_xl()
 *
 * Usage:
 *   if (Responsive.is_mobile()) { ... }
 *   if (Responsive.is_desktop_md()) { ... }
 */
class Responsive {
    static _cache = {};

    /**
     * Get breakpoint value from CSS custom property as integer
     * @param {string} name - Breakpoint name (e.g., 'desktop', 'tablet', 'desktop-md')
     * @returns {number} Breakpoint value in pixels
     * @private
     */
    static _get_breakpoint(name) {
        if (this._cache[name] !== undefined) {
            return this._cache[name];
        }

        const styles = getComputedStyle(document.documentElement);
        const value = styles.getPropertyValue(`--bp-${name}`).trim();
        const parsed = parseInt(value, 10);

        this._cache[name] = parsed;
        return parsed;
    }

    /**
     * Get current viewport width
     * @returns {number}
     * @private
     */
    static _viewport() {
        return window.innerWidth;
    }

    /**
     * Tier 1: Check if viewport is mobile (0 - 1023px)
     * Includes phone and tablet sizes
     * @returns {boolean}
     */
    static is_mobile() {
        return !this.is_desktop();
    }

    /**
     * Tier 1: Check if viewport is desktop (1024px+)
     * Includes desktop-sm, desktop-md, and desktop-lg sizes
     * @returns {boolean}
     */
    static is_desktop() {
        return this._viewport() >= this._get_breakpoint('desktop');
    }

    /**
     * Tier 2: Check if viewport is phone (0 - 799px)
     * @returns {boolean}
     */
    static is_phone() {
        return this._viewport() < this._get_breakpoint('tablet');
    }

    /**
     * Tier 2: Check if viewport is tablet (800 - 1023px)
     * @returns {boolean}
     */
    static is_tablet() {
        const vp = this._viewport();
        return vp >= this._get_breakpoint('tablet') && vp < this._get_breakpoint('desktop');
    }

    /**
     * Tier 2: Check if viewport is desktop-sm (1024 - 1199px)
     * @returns {boolean}
     */
    static is_desktop_sm() {
        const vp = this._viewport();
        return vp >= this._get_breakpoint('desktop') && vp < this._get_breakpoint('desktop-md');
    }

    /**
     * Tier 2: Check if viewport is desktop-md (1200 - 1639px)
     * @returns {boolean}
     */
    static is_desktop_md() {
        const vp = this._viewport();
        return vp >= this._get_breakpoint('desktop-md') && vp < this._get_breakpoint('desktop-lg');
    }

    /**
     * Tier 2: Check if viewport is desktop-lg (1640 - 2199px)
     * @returns {boolean}
     */
    static is_desktop_lg() {
        const vp = this._viewport();
        return vp >= this._get_breakpoint('desktop-lg') && vp < this._get_breakpoint('desktop-xl');
    }

    /**
     * Tier 2: Check if viewport is desktop-xl (2200px+)
     * @returns {boolean}
     */
    static is_desktop_xl() {
        return this._viewport() >= this._get_breakpoint('desktop-xl');
    }
}
