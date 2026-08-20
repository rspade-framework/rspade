/**
 * JQHTML SSR Protocol
 *
 * JSON-based protocol with newline-delimited messages for TCP/Unix socket communication.
 *
 * Request format:
 * {
 *   "id": "request-uuid",
 *   "type": "render" | "ping" | "flush_cache",
 *   "payload": { ... }
 * }
 *
 * Response format:
 * {
 *   "id": "request-uuid",
 *   "status": "success" | "error",
 *   "payload": { ... } | "error": { code, message, stack }
 * }
 */

// Error codes matching HTTP semantics
const ErrorCodes = {
  PARSE_ERROR: 'PARSE_ERROR',           // 400 - Invalid request JSON
  BUNDLE_ERROR: 'BUNDLE_ERROR',         // 400 - Bundle failed to parse/execute
  COMPONENT_NOT_FOUND: 'COMPONENT_NOT_FOUND', // 404 - Component not registered
  RENDER_ERROR: 'RENDER_ERROR',         // 500 - Component threw during lifecycle
  RENDER_TIMEOUT: 'RENDER_TIMEOUT',     // 504 - Render exceeded timeout
  INTERNAL_ERROR: 'INTERNAL_ERROR',     // 500 - Unexpected server error
  ROUTE_NOT_FOUND: 'ROUTE_NOT_FOUND',   // 404 - No SPA action matched the URL
  BUNDLE_LOAD_ERROR: 'BUNDLE_LOAD_ERROR', // 400 - Failed to read bundle from filesystem
  SPA_BOOT_ERROR: 'SPA_BOOT_ERROR',     // 500 - SPA framework failed to initialize
  DATA_FETCH_ERROR: 'DATA_FETCH_ERROR'  // 502 - on_load() HTTP request(s) failed
};

/**
 * Parse a request message from JSON string
 * @param {string} message - Raw JSON string
 * @returns {{ ok: true, request: object } | { ok: false, error: object }}
 */
function parseRequest(message) {
  let parsed;

  try {
    parsed = JSON.parse(message);
  } catch (e) {
    return {
      ok: false,
      error: {
        code: ErrorCodes.PARSE_ERROR,
        message: `Invalid JSON: ${e.message}`,
        stack: e.stack
      }
    };
  }

  // Validate required fields
  if (!parsed.id || typeof parsed.id !== 'string') {
    return {
      ok: false,
      error: {
        code: ErrorCodes.PARSE_ERROR,
        message: 'Missing or invalid "id" field (must be string)'
      }
    };
  }

  if (!parsed.type || typeof parsed.type !== 'string') {
    return {
      ok: false,
      error: {
        code: ErrorCodes.PARSE_ERROR,
        message: 'Missing or invalid "type" field (must be string)'
      }
    };
  }

  const validTypes = ['render', 'render_spa', 'ping', 'flush_cache'];
  if (!validTypes.includes(parsed.type)) {
    return {
      ok: false,
      error: {
        code: ErrorCodes.PARSE_ERROR,
        message: `Invalid request type "${parsed.type}". Must be one of: ${validTypes.join(', ')}`
      }
    };
  }

  // Type-specific validation
  if (parsed.type === 'render') {
    const validation = validateRenderPayload(parsed.payload);
    if (!validation.ok) {
      return validation;
    }
  }

  if (parsed.type === 'render_spa') {
    const validation = validateRenderSpaPayload(parsed.payload);
    if (!validation.ok) {
      return validation;
    }
  }

  return { ok: true, request: parsed };
}

/**
 * Validate render request payload
 * @param {object} payload
 * @returns {{ ok: true } | { ok: false, error: object }}
 */
function validateRenderPayload(payload) {
  if (!payload || typeof payload !== 'object') {
    return {
      ok: false,
      error: {
        code: ErrorCodes.PARSE_ERROR,
        message: 'Missing or invalid "payload" field for render request'
      }
    };
  }

  // Validate bundles
  if (!Array.isArray(payload.bundles) || payload.bundles.length === 0) {
    return {
      ok: false,
      error: {
        code: ErrorCodes.PARSE_ERROR,
        message: 'payload.bundles must be a non-empty array'
      }
    };
  }

  for (let i = 0; i < payload.bundles.length; i++) {
    const bundle = payload.bundles[i];
    if (!bundle.id || typeof bundle.id !== 'string') {
      return {
        ok: false,
        error: {
          code: ErrorCodes.PARSE_ERROR,
          message: `payload.bundles[${i}].id must be a string`
        }
      };
    }
    if (!bundle.content || typeof bundle.content !== 'string') {
      return {
        ok: false,
        error: {
          code: ErrorCodes.PARSE_ERROR,
          message: `payload.bundles[${i}].content must be a string`
        }
      };
    }
  }

  // Validate component name
  if (!payload.component || typeof payload.component !== 'string') {
    return {
      ok: false,
      error: {
        code: ErrorCodes.PARSE_ERROR,
        message: 'payload.component must be a string'
      }
    };
  }

  // Validate options
  if (payload.options) {
    if (typeof payload.options !== 'object') {
      return {
        ok: false,
        error: {
          code: ErrorCodes.PARSE_ERROR,
          message: 'payload.options must be an object'
        }
      };
    }

    if (!payload.options.baseUrl || typeof payload.options.baseUrl !== 'string') {
      return {
        ok: false,
        error: {
          code: ErrorCodes.PARSE_ERROR,
          message: 'payload.options.baseUrl is required and must be a string'
        }
      };
    }

    if (payload.options.timeout !== undefined && typeof payload.options.timeout !== 'number') {
      return {
        ok: false,
        error: {
          code: ErrorCodes.PARSE_ERROR,
          message: 'payload.options.timeout must be a number'
        }
      };
    }
  } else {
    return {
      ok: false,
      error: {
        code: ErrorCodes.PARSE_ERROR,
        message: 'payload.options is required (must include baseUrl)'
      }
    };
  }

  // args is optional, but if present must be object
  if (payload.args !== undefined && (typeof payload.args !== 'object' || payload.args === null)) {
    return {
      ok: false,
      error: {
        code: ErrorCodes.PARSE_ERROR,
        message: 'payload.args must be an object if provided'
      }
    };
  }

  return { ok: true };
}

/**
 * Validate render_spa request payload
 * @param {object} payload
 * @returns {{ ok: true } | { ok: false, error: object }}
 */
function validateRenderSpaPayload(payload) {
  if (!payload || typeof payload !== 'object') {
    return {
      ok: false,
      error: {
        code: ErrorCodes.PARSE_ERROR,
        message: 'Missing or invalid "payload" field for render_spa request'
      }
    };
  }

  // Validate bundles
  if (!Array.isArray(payload.bundles) || payload.bundles.length === 0) {
    return {
      ok: false,
      error: {
        code: ErrorCodes.PARSE_ERROR,
        message: 'payload.bundles must be a non-empty array'
      }
    };
  }

  for (let i = 0; i < payload.bundles.length; i++) {
    const bundle = payload.bundles[i];
    if (!bundle.id || typeof bundle.id !== 'string') {
      return {
        ok: false,
        error: {
          code: ErrorCodes.PARSE_ERROR,
          message: `payload.bundles[${i}].id must be a string`
        }
      };
    }
    // Must have either path or content
    const hasPath = bundle.path && typeof bundle.path === 'string';
    const hasContent = bundle.content && typeof bundle.content === 'string';
    if (!hasPath && !hasContent) {
      return {
        ok: false,
        error: {
          code: ErrorCodes.PARSE_ERROR,
          message: `payload.bundles[${i}] must have either "path" (string) or "content" (string)`
        }
      };
    }
  }

  // Validate URL
  if (!payload.url || typeof payload.url !== 'string') {
    return {
      ok: false,
      error: {
        code: ErrorCodes.PARSE_ERROR,
        message: 'payload.url must be a string'
      }
    };
  }

  // Validate rsxapp
  if (!payload.rsxapp || typeof payload.rsxapp !== 'object') {
    return {
      ok: false,
      error: {
        code: ErrorCodes.PARSE_ERROR,
        message: 'payload.rsxapp must be an object'
      }
    };
  }

  // Validate options
  if (!payload.options || typeof payload.options !== 'object') {
    return {
      ok: false,
      error: {
        code: ErrorCodes.PARSE_ERROR,
        message: 'payload.options is required (must include baseUrl)'
      }
    };
  }

  if (!payload.options.baseUrl || typeof payload.options.baseUrl !== 'string') {
    return {
      ok: false,
      error: {
        code: ErrorCodes.PARSE_ERROR,
        message: 'payload.options.baseUrl is required and must be a string'
      }
    };
  }

  if (payload.options.timeout !== undefined && typeof payload.options.timeout !== 'number') {
    return {
      ok: false,
      error: {
        code: ErrorCodes.PARSE_ERROR,
        message: 'payload.options.timeout must be a number'
      }
    };
  }

  if (payload.options.ssr_token !== undefined && typeof payload.options.ssr_token !== 'string') {
    return {
      ok: false,
      error: {
        code: ErrorCodes.PARSE_ERROR,
        message: 'payload.options.ssr_token must be a string'
      }
    };
  }

  return { ok: true };
}

/**
 * Create a render_spa success response
 * @param {string} id - Request ID
 * @param {string} html - Rendered HTML
 * @param {object} meta - Page metadata { title, description, og_image }
 * @param {object} cache - Cache state { localStorage, sessionStorage }
 * @param {object} timing - Timing info { total_ms, bundle_load_ms, render_ms }
 * @returns {string} JSON string with newline
 */
function renderSpaResponse(id, html, meta, cache, timing) {
  return successResponse(id, {
    html,
    meta,
    cache,
    timing
  });
}

/**
 * Create a success response
 * @param {string} id - Request ID
 * @param {object} payload - Response payload
 * @returns {string} JSON string with newline
 */
function successResponse(id, payload) {
  return JSON.stringify({
    id,
    status: 'success',
    payload
  }) + '\n';
}

/**
 * Create an error response
 * @param {string} id - Request ID (or null if unknown)
 * @param {string} code - Error code from ErrorCodes
 * @param {string} message - Human-readable error message
 * @param {string} [stack] - Optional stack trace
 * @returns {string} JSON string with newline
 */
function errorResponse(id, code, message, stack) {
  return JSON.stringify({
    id: id || 'unknown',
    status: 'error',
    error: {
      code,
      message,
      ...(stack && { stack })
    }
  }) + '\n';
}

/**
 * Create a render success response
 * @param {string} id - Request ID
 * @param {string} html - Rendered HTML
 * @param {object} cache - Cache state { localStorage, sessionStorage }
 * @param {object} timing - Timing info { total_ms, bundle_load_ms, render_ms }
 * @returns {string} JSON string with newline
 */
function renderResponse(id, html, cache, timing) {
  return successResponse(id, {
    html,
    cache,
    timing
  });
}

/**
 * Create a ping response
 * @param {string} id - Request ID
 * @param {number} uptime_ms - Server uptime in milliseconds
 * @returns {string} JSON string with newline
 */
function pingResponse(id, uptime_ms) {
  return successResponse(id, { uptime_ms });
}

/**
 * Create a flush_cache response
 * @param {string} id - Request ID
 * @param {boolean} flushed - Whether flush was successful
 * @returns {string} JSON string with newline
 */
function flushCacheResponse(id, flushed) {
  return successResponse(id, { flushed });
}

/**
 * MessageBuffer - Handles newline-delimited message framing
 *
 * TCP streams don't guarantee message boundaries. This class buffers
 * incoming data and emits complete messages (delimited by newlines).
 */
class MessageBuffer {
  constructor() {
    this.buffer = '';
  }

  /**
   * Add data to buffer and return any complete messages
   * @param {string} data - Incoming data chunk
   * @returns {string[]} Array of complete messages
   */
  push(data) {
    this.buffer += data;
    const messages = [];

    let newlineIndex;
    while ((newlineIndex = this.buffer.indexOf('\n')) !== -1) {
      const message = this.buffer.slice(0, newlineIndex);
      this.buffer = this.buffer.slice(newlineIndex + 1);
      if (message.trim()) {
        messages.push(message);
      }
    }

    return messages;
  }

  /**
   * Check if there's incomplete data in the buffer
   * @returns {boolean}
   */
  hasIncomplete() {
    return this.buffer.trim().length > 0;
  }

  /**
   * Clear the buffer
   */
  clear() {
    this.buffer = '';
  }
}

module.exports = {
  ErrorCodes,
  parseRequest,
  validateRenderPayload,
  validateRenderSpaPayload,
  successResponse,
  errorResponse,
  renderResponse,
  renderSpaResponse,
  pingResponse,
  flushCacheResponse,
  MessageBuffer
};
