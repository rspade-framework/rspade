/**
 * JQHTML SSR Bundle Cache
 *
 * Caches parsed bundle code to avoid re-parsing on every request.
 * Uses LRU (Least Recently Used) eviction strategy.
 */

const fs = require('fs');

/**
 * LRU Cache for bundle sets
 */
class BundleCache {
  constructor(maxSize = 10) {
    this.maxSize = maxSize;
    this.cache = new Map(); // bundleSetId -> { code, lastUsed }
  }

  /**
   * Generate a cache key from bundle array
   * @param {Array<{id: string, content: string}>} bundles
   * @returns {string} Cache key
   */
  static generateKey(bundles) {
    // Key is concatenation of all bundle IDs in order
    return bundles.map(b => b.id).join('|');
  }

  /**
   * Get cached bundle code if available
   * @param {string} key - Cache key from generateKey()
   * @returns {string|null} Concatenated bundle code or null if not cached
   */
  get(key) {
    const entry = this.cache.get(key);
    if (entry) {
      entry.lastUsed = Date.now();
      return entry.code;
    }
    return null;
  }

  /**
   * Store bundle code in cache
   * @param {string} key - Cache key
   * @param {string} code - Concatenated bundle code
   */
  set(key, code) {
    // Evict if at capacity
    if (this.cache.size >= this.maxSize && !this.cache.has(key)) {
      this._evictLRU();
    }

    this.cache.set(key, {
      code,
      lastUsed: Date.now()
    });
  }

  /**
   * Check if a bundle set is cached
   * @param {string} key - Cache key
   * @returns {boolean}
   */
  has(key) {
    return this.cache.has(key);
  }

  /**
   * Remove a specific bundle set from cache
   * @param {string} key - Cache key
   * @returns {boolean} True if entry was removed
   */
  delete(key) {
    return this.cache.delete(key);
  }

  /**
   * Clear all cached bundles
   */
  clear() {
    this.cache.clear();
  }

  /**
   * Get cache statistics
   * @returns {{ size: number, maxSize: number, keys: string[] }}
   */
  stats() {
    return {
      size: this.cache.size,
      maxSize: this.maxSize,
      keys: Array.from(this.cache.keys())
    };
  }

  /**
   * Evict the least recently used entry
   * @private
   */
  _evictLRU() {
    let oldestKey = null;
    let oldestTime = Infinity;

    for (const [key, entry] of this.cache) {
      if (entry.lastUsed < oldestTime) {
        oldestTime = entry.lastUsed;
        oldestKey = key;
      }
    }

    if (oldestKey) {
      this.cache.delete(oldestKey);
    }
  }
}

/**
 * Prepare bundle code for execution
 * Concatenates bundles and removes problematic patterns
 * @param {Array<{id: string, content: string}>} bundles
 * @returns {string} Prepared code
 */
function prepareBundleCode(bundles) {
  const codeChunks = [];

  for (const bundle of bundles) {
    // Load code from filesystem path or use inline content
    let raw;
    if (bundle.path) {
      if (!fs.existsSync(bundle.path)) {
        throw new Error(`Bundle file not found: ${bundle.path}`);
      }
      raw = fs.readFileSync(bundle.path, 'utf-8');
    } else {
      raw = bundle.content;
    }

    // Remove sourcemap comments
    let code = raw.replace(/\/\/# sourceMappingURL=.*/g, '');

    // Add bundle marker comment for debugging
    code = `\n/* === Bundle: ${bundle.id} === */\n${code}`;

    codeChunks.push(code);
  }

  return codeChunks.join('\n');
}

module.exports = {
  BundleCache,
  prepareBundleCode
};
