/**
 * JQHTML SSR Environment
 *
 * Creates isolated jsdom environments for SSR rendering.
 * Each render request gets a fresh environment to ensure isolation.
 */

const { JSDOM } = require('jsdom');
const vm = require('vm');
const { createStoragePair } = require('./storage.js');
const { installHttpIntercept, installAjaxTransport } = require('./http-intercept.js');

// CRITICAL: Require jQuery BEFORE any global.window is set
// This ensures jQuery returns a factory function, not an auto-bound object
const jqueryFactory = require('jquery');

/**
 * SSR Environment - Isolated rendering context
 */
class SSREnvironment {
  constructor(options = {}) {
    this.options = {
      baseUrl: options.baseUrl || 'http://localhost',
      timeout: options.timeout || 30000,
      ssrToken: options.ssrToken || null,
      rsxapp: options.rsxapp || null,
      extractMeta: options.extractMeta || false,
      readySelector: options.readySelector || '#spa-root > *:first-child'
    };

    this.dom = null;
    this.window = null;
    this.$ = null;
    this.storage = null;
    this.initialized = false;
    this.startTime = Date.now();
  }

  /**
   * Initialize the jsdom environment with jQuery and all required globals
   */
  init() {
    // Create jsdom instance
    this.dom = new JSDOM(`
      <!DOCTYPE html>
      <html>
        <head><title>SSR</title></head>
        <body></body>
      </html>
    `, {
      url: this.options.baseUrl,
      pretendToBeVisual: true,
      runScripts: 'outside-only'
    });

    this.window = this.dom.window;

    // Create fake storage
    this.storage = createStoragePair();

    // Set up global references that bundles expect
    global.window = this.window;
    global.document = this.window.document;
    global.HTMLElement = this.window.HTMLElement;
    global.Element = this.window.Element;
    global.Node = this.window.Node;
    global.NodeList = this.window.NodeList;
    global.DOMParser = this.window.DOMParser;
    global.Text = this.window.Text;
    global.DocumentFragment = this.window.DocumentFragment;
    global.Event = this.window.Event;
    global.CustomEvent = this.window.CustomEvent;
    global.MutationObserver = this.window.MutationObserver;

    // Window-like globals
    global.self = this.window;
    global.top = this.window;
    global.parent = this.window;
    global.frames = this.window;
    global.location = this.window.location;
    global.history = this.window.history;
    global.screen = this.window.screen;
    // NOTE: Don't set global.performance - causes issues between jsdom instances
    // The window.performance is available for code that accesses it via window
    global.requestAnimationFrame = (cb) => setTimeout(cb, 16);
    global.cancelAnimationFrame = (id) => clearTimeout(id);
    global.getComputedStyle = this.window.getComputedStyle.bind(this.window);

    // Install fake storage
    global.localStorage = this.storage.localStorage;
    global.sessionStorage = this.storage.sessionStorage;
    this.window.localStorage = this.storage.localStorage;
    this.window.sessionStorage = this.storage.sessionStorage;

    // Mark as SSR environment
    global.__SSR__ = true;
    global.__JQHTML_SSR_MODE__ = true;
    this.window.__SSR__ = true;
    this.window.__JQHTML_SSR_MODE__ = true;

    // Install HTTP interception (fetch + XHR URL rewriting + optional SSR token)
    installHttpIntercept(this.window, this.options.baseUrl, this.options.ssrToken);

    // Create jQuery bound to jsdom window
    this.$ = jqueryFactory(this.window);

    // Make jQuery available globally
    global.$ = this.$;
    global.jQuery = this.$;
    this.window.$ = this.$;
    this.window.jQuery = this.$;

    // Install $.ajaxTransport for real HTTP requests via jQuery $.ajax()
    // This ensures $.ajax() → Node fetch() instead of jsdom's limited XHR
    installAjaxTransport(this.window, this.options.baseUrl, this.options.ssrToken);

    // Stub console_debug if bundles use it
    this.window.console_debug = function() {};
    global.console_debug = function() {};

    // rsxapp config object - inject from request or create empty default
    if (this.options.rsxapp) {
      this.window.rsxapp = this.options.rsxapp;
    } else {
      this.window.rsxapp = this.window.rsxapp || {};
    }
    global.rsxapp = this.window.rsxapp;

    this.initialized = true;
  }

  /**
   * Execute JavaScript code in the environment
   * @param {string} code - JavaScript code to execute
   * @param {string} filename - Filename for error reporting
   */
  execute(code, filename = 'bundle.js') {
    if (!this.initialized) {
      throw new Error('SSR environment not initialized. Call init() first.');
    }

    // Remove sourcemap comments to avoid errors
    const cleanCode = code.replace(/\/\/# sourceMappingURL=.*/g, '');

    // Execute in Node's global context
    vm.runInThisContext(cleanCode, {
      filename,
      displayErrors: true
    });
  }

  /**
   * Render a component to HTML
   * @param {string} componentName - Component name to render
   * @param {object} args - Arguments to pass to component
   * @returns {Promise<{ html: string, cache: object }>}
   */
  async render(componentName, args = {}) {
    if (!this.initialized) {
      throw new Error('SSR environment not initialized. Call init() first.');
    }

    const $ = global.$;

    // Create container
    const $container = $('<div>');

    // Create component
    $container.component(componentName, args);

    const component = $container.component();
    if (!component) {
      throw new Error(`Failed to create component: ${componentName}`);
    }

    // Wait for ready with timeout
    const timeoutPromise = new Promise((_, reject) => {
      setTimeout(() => {
        reject(new Error(`Component render exceeded ${this.options.timeout}ms timeout`));
      }, this.options.timeout);
    });

    await Promise.race([
      component.ready(),
      timeoutPromise
    ]);

    // Get rendered HTML
    const html = component.$.prop('outerHTML');

    // Export cache state
    const cache = this.storage.exportAll();

    return { html, cache };
  }

  /**
   * Check if jqhtml runtime is available
   * @returns {boolean}
   */
  isJqhtmlReady() {
    return !!(global.window && global.window.jqhtml);
  }

  /**
   * Get list of registered templates
   * @returns {string[]}
   */
  getRegisteredTemplates() {
    if (global.window.jqhtml) {
      // Real jqhtml API
      if (typeof global.window.jqhtml.get_registered_templates === 'function') {
        return global.window.jqhtml.get_registered_templates();
      }
      // Mock bundle API (for testing)
      if (global.window.jqhtml._templates) {
        return Array.from(global.window.jqhtml._templates.keys());
      }
    }
    return [];
  }

  /**
   * Render a SPA page by URL
   *
   * Assumes bundles have already been executed via execute(), which triggers
   * SPA framework boot (rsxapp.is_spa = true → route dispatch to URL).
   * Waits for the root component to reach ready state, then extracts HTML.
   *
   * @param {string} url - The URL path being rendered (for logging/errors)
   * @returns {Promise<{ html: string, meta: object, cache: object }>}
   */
  async renderSpa(url) {
    if (!this.initialized) {
      throw new Error('SSR environment not initialized. Call init() first.');
    }

    const $ = global.$;
    const readySelector = this.options.readySelector;

    // Poll for root component to appear (SPA dispatch may be async)
    const component = await this._waitForComponent(readySelector);

    // Wait for component ready (full lifecycle including children)
    const timeoutPromise = new Promise((_, reject) => {
      setTimeout(() => {
        reject(new Error(`SPA render exceeded ${this.options.timeout}ms timeout`));
      }, this.options.timeout);
    });

    await Promise.race([
      component.ready(),
      timeoutPromise
    ]);

    // Extract HTML from #spa-root or the component itself
    const $root = $('#spa-root');
    const html = $root.length ? $root.html() : component.$.prop('outerHTML');

    // Extract metadata if requested
    let meta = {};
    if (this.options.extractMeta) {
      meta = this._extractMeta(component);
    }

    // Export cache state
    const cache = this.storage.exportAll();

    return { html, meta, cache };
  }

  /**
   * Poll for a jqhtml component on the given selector
   * @param {string} selector - CSS selector to find the component element
   * @returns {Promise<object>} Component instance
   * @private
   */
  _waitForComponent(selector) {
    const $ = global.$;
    const timeout = this.options.timeout;
    const startTime = Date.now();

    return new Promise((resolve, reject) => {
      const check = () => {
        const elapsed = Date.now() - startTime;
        if (elapsed > timeout) {
          reject(new Error(`SPA component not found on "${selector}" within ${timeout}ms timeout`));
          return;
        }

        const $el = $(selector);
        if ($el.length > 0) {
          const component = $el.component ? $el.component() : null;
          if (component) {
            resolve(component);
            return;
          }
        }

        setTimeout(check, 10);
      };

      check();
    });
  }

  /**
   * Extract page metadata from the rendered DOM and component
   * @param {object} component - The root component instance
   * @returns {{ title: string|null, description: string|null, og_image: string|null }}
   * @private
   */
  _extractMeta(component) {
    const doc = global.document;
    const meta = {
      title: null,
      description: null,
      og_image: null
    };

    // Check document.title
    if (doc && doc.title) {
      meta.title = doc.title;
    }

    // Check if component has a page_title method
    if (component && typeof component.page_title === 'function') {
      try {
        const title = component.page_title();
        if (title && typeof title === 'string') {
          meta.title = title;
        }
      } catch (e) {
        // page_title() may throw - ignore
      }
    }

    // Check meta tags in DOM
    if (doc) {
      const descEl = doc.querySelector('meta[name="description"]');
      if (descEl) {
        meta.description = descEl.getAttribute('content');
      }

      const ogImageEl = doc.querySelector('meta[property="og:image"]');
      if (ogImageEl) {
        meta.og_image = ogImageEl.getAttribute('content');
      }

      // Also check og:description as fallback
      if (!meta.description) {
        const ogDescEl = doc.querySelector('meta[property="og:description"]');
        if (ogDescEl) {
          meta.description = ogDescEl.getAttribute('content');
        }
      }
    }

    return meta;
  }

  /**
   * Destroy the environment and clean up globals
   *
   * NOTE: We set globals to undefined instead of deleting them,
   * because jsdom expects certain globals to exist (even if undefined)
   * when creating new windows.
   */
  destroy() {
    // Close jsdom window first
    if (this.window) {
      this.window.close();
    }

    // Clean up global references by setting to undefined
    // This is safer than delete because some libraries check for existence
    global.window = undefined;
    global.document = undefined;
    global.HTMLElement = undefined;
    global.Element = undefined;
    global.Node = undefined;
    global.NodeList = undefined;
    global.DOMParser = undefined;
    global.Text = undefined;
    global.DocumentFragment = undefined;
    global.Event = undefined;
    global.CustomEvent = undefined;
    global.MutationObserver = undefined;
    global.self = undefined;
    global.top = undefined;
    global.parent = undefined;
    global.frames = undefined;
    global.location = undefined;
    global.history = undefined;
    global.screen = undefined;
    // Don't touch performance - jsdom needs it
    global.requestAnimationFrame = undefined;
    global.cancelAnimationFrame = undefined;
    global.getComputedStyle = undefined;
    global.localStorage = undefined;
    global.sessionStorage = undefined;
    global.$ = undefined;
    global.jQuery = undefined;
    global.__SSR__ = undefined;
    global.__JQHTML_SSR_MODE__ = undefined;
    global.console_debug = undefined;
    global.rsxapp = undefined;
    global.fetch = undefined;
    global.XMLHttpRequest = undefined;

    this.dom = null;
    this.window = null;
    this.$ = null;
    this.storage = null;
    this.initialized = false;
  }
}

module.exports = { SSREnvironment };
