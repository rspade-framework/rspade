/**
 * JQHTML SSR Fake Storage
 *
 * Implements localStorage/sessionStorage API that captures all writes
 * for export at the end of SSR render.
 */

class SSR_Storage {
  constructor(name = 'storage') {
    this._name = name;
    this._data = new Map();
  }

  getItem(key) {
    const value = this._data.get(key);
    return value !== undefined ? value : null;
  }

  setItem(key, value) {
    this._data.set(key, String(value));
  }

  removeItem(key) {
    this._data.delete(key);
  }

  clear() {
    this._data.clear();
  }

  get length() {
    return this._data.size;
  }

  key(index) {
    const keys = Array.from(this._data.keys());
    return index < keys.length ? keys[index] : null;
  }

  /**
   * SSR-specific: Export all data as plain object
   * @returns {Object} Key-value pairs
   */
  _export() {
    return Object.fromEntries(this._data);
  }

  /**
   * SSR-specific: Import data (for testing or pre-population)
   * @param {Object} data - Key-value pairs to import
   */
  _import(data) {
    for (const [key, value] of Object.entries(data)) {
      this._data.set(key, String(value));
    }
  }

  /**
   * SSR-specific: Check if any data was written
   * @returns {boolean}
   */
  _hasData() {
    return this._data.size > 0;
  }
}

/**
 * Create a pair of storage instances for an SSR request
 * @returns {{ localStorage: SSR_Storage, sessionStorage: SSR_Storage, exportAll: function }}
 */
function createStoragePair() {
  const localStorage = new SSR_Storage('localStorage');
  const sessionStorage = new SSR_Storage('sessionStorage');

  return {
    localStorage,
    sessionStorage,
    exportAll() {
      return {
        localStorage: localStorage._export(),
        sessionStorage: sessionStorage._export()
      };
    }
  };
}

module.exports = {
  SSR_Storage,
  createStoragePair
};
