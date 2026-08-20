#!/usr/bin/env node
/**
 * JQHTML SSR Server
 *
 * Long-running Node.js process that accepts render requests via TCP or Unix socket.
 * See SPECIFICATION.md for protocol details.
 */

const net = require('net');
const fs = require('fs');
const path = require('path');

const {
  ErrorCodes,
  parseRequest,
  renderResponse,
  renderSpaResponse,
  pingResponse,
  flushCacheResponse,
  errorResponse,
  MessageBuffer
} = require('./protocol.js');

const { SSREnvironment } = require('./environment.js');
const { BundleCache, prepareBundleCode } = require('./bundle-cache.js');

/**
 * SSR Server
 */
class SSRServer {
  constructor(options = {}) {
    this.options = {
      maxBundles: options.maxBundles || 10,
      defaultTimeout: options.defaultTimeout || 30000,
      ...options
    };

    this.server = null;
    this.bundleCache = new BundleCache(this.options.maxBundles);
    this.startTime = Date.now();
    this.requestCount = 0;
  }

  /**
   * Start server on TCP port
   * @param {number} port
   * @returns {Promise<void>}
   */
  listenTCP(port) {
    return new Promise((resolve, reject) => {
      this.server = net.createServer((socket) => this._handleConnection(socket));

      this.server.on('error', reject);

      this.server.listen(port, () => {
        console.log(`[SSR] Server listening on tcp://0.0.0.0:${port}`);
        resolve();
      });
    });
  }

  /**
   * Start server on Unix socket
   * @param {string} socketPath
   * @returns {Promise<void>}
   */
  listenUnix(socketPath) {
    return new Promise((resolve, reject) => {
      // Remove existing socket file if present
      if (fs.existsSync(socketPath)) {
        fs.unlinkSync(socketPath);
      }

      this.server = net.createServer((socket) => this._handleConnection(socket));

      this.server.on('error', reject);

      this.server.listen(socketPath, () => {
        // Set socket permissions (owner read/write only)
        fs.chmodSync(socketPath, 0o600);
        console.log(`[SSR] Server listening on unix://${socketPath}`);
        resolve();
      });
    });
  }

  /**
   * Handle incoming connection
   * @param {net.Socket} socket
   * @private
   */
  _handleConnection(socket) {
    const messageBuffer = new MessageBuffer();
    const remoteId = `${socket.remoteAddress || 'unix'}:${socket.remotePort || 'local'}`;

    socket.on('data', async (data) => {
      const messages = messageBuffer.push(data.toString());

      for (const message of messages) {
        await this._handleMessage(socket, message);
      }
    });

    socket.on('error', (err) => {
      console.error(`[SSR] Socket error (${remoteId}):`, err.message);
    });

    socket.on('close', () => {
      if (messageBuffer.hasIncomplete()) {
        console.warn(`[SSR] Connection closed with incomplete message (${remoteId})`);
      }
    });
  }

  /**
   * Handle a single message
   * @param {net.Socket} socket
   * @param {string} message
   * @private
   */
  async _handleMessage(socket, message) {
    this.requestCount++;
    const requestId = this.requestCount;

    // Parse request
    const parseResult = parseRequest(message);

    if (!parseResult.ok) {
      const response = errorResponse(
        null,
        parseResult.error.code,
        parseResult.error.message,
        parseResult.error.stack
      );
      socket.write(response);
      return;
    }

    const request = parseResult.request;

    try {
      let response;

      switch (request.type) {
        case 'ping':
          response = this._handlePing(request);
          break;

        case 'flush_cache':
          response = this._handleFlushCache(request);
          break;

        case 'render':
          response = await this._handleRender(request);
          break;

        case 'render_spa':
          response = await this._handleRenderSpa(request);
          break;

        default:
          response = errorResponse(
            request.id,
            ErrorCodes.PARSE_ERROR,
            `Unknown request type: ${request.type}`
          );
      }

      socket.write(response);

    } catch (err) {
      console.error(`[SSR] Error handling request ${request.id}:`, err);

      const response = errorResponse(
        request.id,
        ErrorCodes.INTERNAL_ERROR,
        err.message,
        err.stack
      );
      socket.write(response);
    }
  }

  /**
   * Handle ping request
   * @param {object} request
   * @returns {string} Response
   * @private
   */
  _handlePing(request) {
    const uptime_ms = Date.now() - this.startTime;
    return pingResponse(request.id, uptime_ms);
  }

  /**
   * Handle flush_cache request
   * @param {object} request
   * @returns {string} Response
   * @private
   */
  _handleFlushCache(request) {
    const bundleId = request.payload?.bundle_id;

    if (bundleId) {
      // Flush specific bundle - need to find all cache keys containing this bundle
      const stats = this.bundleCache.stats();
      let flushed = false;

      for (const key of stats.keys) {
        if (key.includes(bundleId)) {
          this.bundleCache.delete(key);
          flushed = true;
        }
      }

      return flushCacheResponse(request.id, flushed);
    } else {
      // Flush all
      this.bundleCache.clear();
      return flushCacheResponse(request.id, true);
    }
  }

  /**
   * Handle render request
   * @param {object} request
   * @returns {Promise<string>} Response
   * @private
   */
  async _handleRender(request) {
    const startTime = Date.now();
    const payload = request.payload;

    // Get or prepare bundle code
    const cacheKey = BundleCache.generateKey(payload.bundles);
    let bundleCode = this.bundleCache.get(cacheKey);
    let bundleLoadMs = 0;

    if (!bundleCode) {
      const bundleStartTime = Date.now();
      bundleCode = prepareBundleCode(payload.bundles);
      this.bundleCache.set(cacheKey, bundleCode);
      bundleLoadMs = Date.now() - bundleStartTime;
    }

    // Create fresh environment for this request
    const env = new SSREnvironment({
      baseUrl: payload.options.baseUrl,
      timeout: payload.options.timeout || this.options.defaultTimeout
    });

    try {
      // Initialize environment
      env.init();

      // Execute bundle code
      const execStartTime = Date.now();
      env.execute(bundleCode, `bundles:${cacheKey}`);
      bundleLoadMs += Date.now() - execStartTime;

      // Check if jqhtml loaded
      if (!env.isJqhtmlReady()) {
        throw new Error('jqhtml runtime not available after loading bundles');
      }

      // Check if component is registered
      const templates = env.getRegisteredTemplates();
      if (!templates.includes(payload.component)) {
        return errorResponse(
          request.id,
          ErrorCodes.COMPONENT_NOT_FOUND,
          `Component "${payload.component}" not found. Available: ${templates.slice(0, 10).join(', ')}${templates.length > 10 ? '...' : ''}`
        );
      }

      // Render component
      const renderStartTime = Date.now();
      const result = await env.render(payload.component, payload.args || {});
      const renderMs = Date.now() - renderStartTime;

      const totalMs = Date.now() - startTime;

      return renderResponse(request.id, result.html, result.cache, {
        total_ms: totalMs,
        bundle_load_ms: bundleLoadMs,
        render_ms: renderMs
      });

    } catch (err) {
      // Determine error type
      let errorCode = ErrorCodes.RENDER_ERROR;

      if (err.message.includes('timeout')) {
        errorCode = ErrorCodes.RENDER_TIMEOUT;
      } else if (err.message.includes('SyntaxError') || err.message.includes('parse')) {
        errorCode = ErrorCodes.BUNDLE_ERROR;
      }

      return errorResponse(request.id, errorCode, err.message, err.stack);

    } finally {
      // Always destroy environment to free resources
      env.destroy();
    }
  }

  /**
   * Handle render_spa request
   * @param {object} request
   * @returns {Promise<string>} Response
   * @private
   */
  async _handleRenderSpa(request) {
    const startTime = Date.now();
    const payload = request.payload;

    // Get or prepare bundle code
    const cacheKey = BundleCache.generateKey(payload.bundles);
    let bundleCode = this.bundleCache.get(cacheKey);
    let bundleLoadMs = 0;

    if (!bundleCode) {
      const bundleStartTime = Date.now();
      try {
        bundleCode = prepareBundleCode(payload.bundles);
      } catch (err) {
        return errorResponse(
          request.id,
          ErrorCodes.BUNDLE_LOAD_ERROR,
          err.message,
          err.stack
        );
      }
      this.bundleCache.set(cacheKey, bundleCode);
      bundleLoadMs = Date.now() - bundleStartTime;
    }

    // Build the jsdom URL from baseUrl + requested URL path
    const baseUrlObj = new URL(payload.options.baseUrl);
    const spaUrl = `${baseUrlObj.protocol}//${baseUrlObj.host}${payload.url}`;

    // Create fresh environment with SPA options
    const env = new SSREnvironment({
      baseUrl: spaUrl,
      timeout: payload.options.timeout || this.options.defaultTimeout,
      ssrToken: payload.options.ssr_token || null,
      rsxapp: payload.rsxapp || {},
      extractMeta: payload.options.extract_meta || false,
      readySelector: payload.options.ready_selector || '#spa-root > *:first-child'
    });

    try {
      // Initialize environment
      env.init();

      // Execute bundle code (SPA framework boots if rsxapp.is_spa === true)
      const execStartTime = Date.now();
      env.execute(bundleCode, `spa-bundles:${cacheKey}`);
      bundleLoadMs += Date.now() - execStartTime;

      // Render SPA (waits for route dispatch + component ready)
      const renderStartTime = Date.now();
      const result = await env.renderSpa(payload.url);
      const renderMs = Date.now() - renderStartTime;

      const totalMs = Date.now() - startTime;

      return renderSpaResponse(request.id, result.html, result.meta, result.cache, {
        total_ms: totalMs,
        bundle_load_ms: bundleLoadMs,
        render_ms: renderMs
      });

    } catch (err) {
      // Determine error type
      let errorCode = ErrorCodes.RENDER_ERROR;

      if (err.message.includes('timeout')) {
        errorCode = ErrorCodes.RENDER_TIMEOUT;
      } else if (err.message.includes('Bundle file not found')) {
        errorCode = ErrorCodes.BUNDLE_LOAD_ERROR;
      } else if (err.message.includes('SPA')) {
        errorCode = ErrorCodes.SPA_BOOT_ERROR;
      }

      return errorResponse(request.id, errorCode, err.message, err.stack);

    } finally {
      // Always destroy environment to free resources
      env.destroy();
    }
  }

  /**
   * Stop the server
   * @returns {Promise<void>}
   */
  stop() {
    return new Promise((resolve) => {
      if (this.server) {
        this.server.close(() => {
          console.log('[SSR] Server stopped');
          resolve();
        });
      } else {
        resolve();
      }
    });
  }

  /**
   * Get server statistics
   * @returns {object}
   */
  stats() {
    return {
      uptime_ms: Date.now() - this.startTime,
      request_count: this.requestCount,
      bundle_cache: this.bundleCache.stats()
    };
  }
}

module.exports = { SSRServer };

// CLI entry point
if (require.main === module) {
  const args = process.argv.slice(2);

  let tcpPort = null;
  let socketPath = null;
  let maxBundles = 10;
  let timeout = 30000;

  // Parse arguments
  for (let i = 0; i < args.length; i++) {
    switch (args[i]) {
      case '--tcp':
        tcpPort = parseInt(args[++i], 10);
        break;
      case '--socket':
        socketPath = args[++i];
        break;
      case '--max-bundles':
        maxBundles = parseInt(args[++i], 10);
        break;
      case '--timeout':
        timeout = parseInt(args[++i], 10);
        break;
      case '--help':
        console.log(`
JQHTML SSR Server

Usage:
  node server.js --tcp <port>
  node server.js --socket <path>

Options:
  --tcp <port>        Listen on TCP port
  --socket <path>     Listen on Unix socket
  --max-bundles <n>   Max cached bundle sets (default: 10)
  --timeout <ms>      Default render timeout (default: 30000)
  --help              Show this help
        `);
        process.exit(0);
    }
  }

  if (!tcpPort && !socketPath) {
    console.error('Error: Must specify --tcp <port> or --socket <path>');
    process.exit(1);
  }

  const server = new SSRServer({ maxBundles, defaultTimeout: timeout });

  // Start server
  (async () => {
    try {
      if (tcpPort) {
        await server.listenTCP(tcpPort);
      } else {
        await server.listenUnix(socketPath);
      }
    } catch (err) {
      console.error('[SSR] Failed to start server:', err);
      process.exit(1);
    }
  })();

  // Graceful shutdown
  process.on('SIGINT', async () => {
    console.log('\n[SSR] Shutting down...');
    await server.stop();
    process.exit(0);
  });

  process.on('SIGTERM', async () => {
    console.log('\n[SSR] Shutting down...');
    await server.stop();
    process.exit(0);
  });
}
