/**
 * JQHTML SSR - Server-Side Rendering
 *
 * @module @jqhtml/ssr
 */

const { SSRServer } = require('./server.js');
const { SSREnvironment } = require('./environment.js');
const { BundleCache, prepareBundleCode } = require('./bundle-cache.js');
const { createStoragePair, SSR_Storage } = require('./storage.js');
const { resolveUrl, createFetch, installHttpIntercept, installAjaxTransport, installSsrTokenIntercept } = require('./http-intercept.js');
const {
  ErrorCodes,
  parseRequest,
  successResponse,
  errorResponse,
  renderResponse,
  renderSpaResponse,
  pingResponse,
  flushCacheResponse,
  MessageBuffer
} = require('./protocol.js');

module.exports = {
  // Main server
  SSRServer,

  // Environment for direct use
  SSREnvironment,

  // Bundle caching
  BundleCache,
  prepareBundleCode,

  // Storage
  SSR_Storage,
  createStoragePair,

  // HTTP interception
  resolveUrl,
  createFetch,
  installHttpIntercept,
  installAjaxTransport,
  installSsrTokenIntercept,

  // Protocol
  ErrorCodes,
  parseRequest,
  successResponse,
  errorResponse,
  renderResponse,
  renderSpaResponse,
  pingResponse,
  flushCacheResponse,
  MessageBuffer
};
