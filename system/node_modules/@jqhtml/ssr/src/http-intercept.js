/**
 * JQHTML SSR HTTP Interception
 *
 * Intercepts fetch() and XMLHttpRequest to rewrite relative URLs
 * using the configured baseUrl.
 */

const http = require('http');
const https = require('https');

/**
 * Resolve a URL relative to a base URL
 * @param {string} url - URL to resolve (may be relative)
 * @param {string} baseUrl - Base URL (e.g., "https://example.com")
 * @returns {string} Absolute URL
 */
function resolveUrl(url, baseUrl) {
  // Already absolute with protocol
  if (url.startsWith('http://') || url.startsWith('https://')) {
    return url;
  }

  // Protocol-relative (//cdn.example.com/...)
  if (url.startsWith('//')) {
    const baseProtocol = new URL(baseUrl).protocol;
    return baseProtocol + url;
  }

  // Absolute path (/api/users)
  if (url.startsWith('/')) {
    const base = new URL(baseUrl);
    return `${base.protocol}//${base.host}${url}`;
  }

  // Relative path (api/users)
  const base = new URL(baseUrl);
  const basePath = base.pathname.endsWith('/') ? base.pathname : base.pathname + '/';
  return `${base.protocol}//${base.host}${basePath}${url}`;
}

/**
 * Create a fetch function that rewrites URLs
 * @param {string} baseUrl - Base URL for relative URL resolution
 * @returns {function} Configured fetch function
 */
function createFetch(baseUrl) {
  // Use native fetch if available (Node 18+), otherwise use a simple implementation
  const nativeFetch = globalThis.fetch;

  if (nativeFetch) {
    return async function ssrFetch(url, options = {}) {
      const resolvedUrl = resolveUrl(String(url), baseUrl);

      // Remove any auth-related headers for SSR (SEO mode)
      const safeHeaders = { ...(options.headers || {}) };
      delete safeHeaders['Authorization'];
      delete safeHeaders['authorization'];
      delete safeHeaders['Cookie'];
      delete safeHeaders['cookie'];

      return nativeFetch(resolvedUrl, {
        ...options,
        headers: safeHeaders
      });
    };
  }

  // Fallback for older Node versions - basic fetch implementation
  return function ssrFetch(url, options = {}) {
    return new Promise((resolve, reject) => {
      const resolvedUrl = resolveUrl(String(url), baseUrl);
      const parsedUrl = new URL(resolvedUrl);
      const isHttps = parsedUrl.protocol === 'https:';
      const lib = isHttps ? https : http;

      const requestOptions = {
        hostname: parsedUrl.hostname,
        port: parsedUrl.port || (isHttps ? 443 : 80),
        path: parsedUrl.pathname + parsedUrl.search,
        method: options.method || 'GET',
        headers: options.headers || {}
      };

      // Remove auth headers
      delete requestOptions.headers['Authorization'];
      delete requestOptions.headers['authorization'];
      delete requestOptions.headers['Cookie'];
      delete requestOptions.headers['cookie'];

      const req = lib.request(requestOptions, (res) => {
        let data = '';
        res.on('data', chunk => data += chunk);
        res.on('end', () => {
          resolve({
            ok: res.statusCode >= 200 && res.statusCode < 300,
            status: res.statusCode,
            statusText: res.statusMessage,
            headers: new Map(Object.entries(res.headers)),
            text: () => Promise.resolve(data),
            json: () => Promise.resolve(JSON.parse(data))
          });
        });
      });

      req.on('error', reject);

      if (options.body) {
        req.write(options.body);
      }

      req.end();
    });
  };
}

/**
 * Create an XMLHttpRequest class that rewrites URLs
 * This is needed for jQuery $.ajax compatibility
 * @param {string} baseUrl - Base URL for relative URL resolution
 * @param {function} OriginalXHR - Original XMLHttpRequest constructor from jsdom
 * @returns {function} Wrapped XMLHttpRequest constructor
 */
function createXHRWrapper(baseUrl, OriginalXHR) {
  return class SSR_XMLHttpRequest extends OriginalXHR {
    open(method, url, async = true, user, password) {
      const resolvedUrl = resolveUrl(String(url), baseUrl);
      return super.open(method, resolvedUrl, async, user, password);
    }

    setRequestHeader(name, value) {
      // Block auth headers for SSR
      const lowerName = name.toLowerCase();
      if (lowerName === 'authorization' || lowerName === 'cookie') {
        return;
      }
      return super.setRequestHeader(name, value);
    }
  };
}

/**
 * Wrap an existing fetch function to inject the SSR token header on all requests
 * @param {function} fetchFn - The fetch function to wrap
 * @param {string} ssrToken - SSR token value
 * @returns {function} Wrapped fetch function
 */
function wrapFetchWithSsrToken(fetchFn, ssrToken) {
  return function ssrTokenFetch(url, options = {}) {
    const headers = { ...(options.headers || {}) };
    headers['X-SSR-Token'] = ssrToken;
    return fetchFn(url, { ...options, headers });
  };
}

/**
 * Install SSR token injection on fetch and XHR
 * @param {Window} window - jsdom window object
 * @param {string} ssrToken - SSR token value
 */
function installSsrTokenIntercept(window, ssrToken) {
  if (!ssrToken) return;

  // Wrap fetch with SSR token
  const currentFetch = window.fetch;
  window.fetch = wrapFetchWithSsrToken(currentFetch, ssrToken);

  if (typeof global !== 'undefined') {
    global.fetch = window.fetch;
  }
}

/**
 * Register a custom jQuery $.ajaxTransport that uses Node's fetch() for real HTTP.
 *
 * This solves the problem of jsdom's XMLHttpRequest not making real network requests.
 * jQuery's $.ajax() will use this transport instead of XHR, so the chain becomes:
 *   $.ajax() → ajaxTransport → Node fetch() → real HTTP request
 *
 * @param {Window} window - jsdom window object (must have jQuery on window.$)
 * @param {string} baseUrl - Base URL for resolving relative URLs
 * @param {string} [ssrToken] - Optional SSR token to include in requests
 */
function installAjaxTransport(window, baseUrl, ssrToken) {
  const $ = window.$;
  if (!$ || !$.ajaxTransport) return;

  $.ajaxTransport('+*', function(options, originalOptions, jqXHR) {
    return {
      send: function(headers, completeCallback) {
        // Build absolute URL
        const url = resolveUrl(options.url || '', baseUrl);

        // Build fetch options
        const fetchHeaders = {};

        // Copy headers from jQuery, stripping auth headers
        for (const [name, value] of Object.entries(headers)) {
          const lowerName = name.toLowerCase();
          if (lowerName === 'authorization' || lowerName === 'cookie') continue;
          fetchHeaders[name] = value;
        }

        // Inject SSR token if provided
        if (ssrToken) {
          fetchHeaders['X-SSR-Token'] = ssrToken;
        }

        // Set content type if not already set
        if (options.contentType && !fetchHeaders['Content-Type']) {
          fetchHeaders['Content-Type'] = options.contentType;
        }

        const fetchOptions = {
          method: options.type || 'GET',
          headers: fetchHeaders
        };

        // Add body for non-GET requests
        if (options.data && options.type && options.type.toUpperCase() !== 'GET') {
          fetchOptions.body = typeof options.data === 'string'
            ? options.data
            : JSON.stringify(options.data);
        }

        // Use the globally available fetch (already has URL rewriting from createFetch)
        const fetchFn = globalThis.fetch || window.fetch;

        fetchFn(url, fetchOptions)
          .then(async (response) => {
            const responseText = await response.text();
            const responseHeaders = {};
            if (response.headers && typeof response.headers.forEach === 'function') {
              response.headers.forEach((value, name) => {
                responseHeaders[name] = value;
              });
            }

            const headerString = Object.entries(responseHeaders)
              .map(([k, v]) => `${k}: ${v}`)
              .join('\r\n');

            completeCallback(
              response.status,
              response.statusText,
              { text: responseText },
              headerString
            );
          })
          .catch((err) => {
            completeCallback(0, 'Network Error: ' + err.message, {}, '');
          });
      },
      abort: function() {
        // Cannot abort native fetch, but jQuery expects this method
      }
    };
  });
}

/**
 * Install HTTP interception on a jsdom window
 * @param {Window} window - jsdom window object
 * @param {string} baseUrl - Base URL for URL resolution
 * @param {string} [ssrToken] - Optional SSR token for outgoing requests
 */
function installHttpIntercept(window, baseUrl, ssrToken) {
  // Override fetch
  window.fetch = createFetch(baseUrl);

  // Also set on global for code that accesses global.fetch
  if (typeof global !== 'undefined') {
    global.fetch = window.fetch;
  }

  // Override XMLHttpRequest if it exists
  if (window.XMLHttpRequest) {
    const WrappedXHR = createXHRWrapper(baseUrl, window.XMLHttpRequest);
    window.XMLHttpRequest = WrappedXHR;

    if (typeof global !== 'undefined') {
      global.XMLHttpRequest = WrappedXHR;
    }
  }

  // Install SSR token on fetch/XHR if provided
  if (ssrToken) {
    installSsrTokenIntercept(window, ssrToken);
  }
}

module.exports = {
  resolveUrl,
  createFetch,
  createXHRWrapper,
  installHttpIntercept,
  installSsrTokenIntercept,
  installAjaxTransport
};
