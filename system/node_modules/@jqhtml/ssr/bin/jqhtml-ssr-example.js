#!/usr/bin/env node
/**
 * JQHTML SSR Reference Example CLI
 *
 * This is the canonical reference implementation for integrating with the
 * JQHTML SSR server. Use this as a source of truth for external integrations.
 *
 * Usage:
 *   jqhtml-ssr-example --vendor <path> --app <path> --component <name> [options]
 *
 * Example:
 *   jqhtml-ssr-example \
 *     --vendor ./bundles/vendor.js \
 *     --app ./bundles/app.js \
 *     --component Dashboard_Index_Action \
 *     --base-url http://localhost:3000
 */

const net = require('net');
const fs = require('fs');
const path = require('path');

// ANSI colors for output
const colors = {
  reset: '\x1b[0m',
  bold: '\x1b[1m',
  dim: '\x1b[2m',
  red: '\x1b[31m',
  green: '\x1b[32m',
  yellow: '\x1b[33m',
  blue: '\x1b[34m',
  cyan: '\x1b[36m'
};

function color(text, c) {
  return `${colors[c]}${text}${colors.reset}`;
}

/**
 * Parse command line arguments
 */
function parseArgs() {
  const args = process.argv.slice(2);
  const config = {
    vendor: null,
    app: null,
    component: null,
    componentArgs: {},
    baseUrl: 'http://localhost:3000',
    timeout: 30000,
    port: 9876,
    socket: null,
    outputFormat: 'pretty', // 'pretty', 'json', 'html-only'
    showHelp: false
  };

  for (let i = 0; i < args.length; i++) {
    switch (args[i]) {
      case '--vendor':
      case '-v':
        config.vendor = args[++i];
        break;
      case '--app':
      case '-a':
        config.app = args[++i];
        break;
      case '--component':
      case '-c':
        config.component = args[++i];
        break;
      case '--args':
        // Parse JSON args
        try {
          config.componentArgs = JSON.parse(args[++i]);
        } catch (e) {
          console.error(color('Error: --args must be valid JSON', 'red'));
          process.exit(1);
        }
        break;
      case '--base-url':
      case '-b':
        config.baseUrl = args[++i];
        break;
      case '--timeout':
      case '-t':
        config.timeout = parseInt(args[++i], 10);
        break;
      case '--port':
      case '-p':
        config.port = parseInt(args[++i], 10);
        break;
      case '--socket':
      case '-s':
        config.socket = args[++i];
        break;
      case '--format':
      case '-f':
        config.outputFormat = args[++i];
        break;
      case '--help':
      case '-h':
        config.showHelp = true;
        break;
    }
  }

  return config;
}

/**
 * Show help message
 */
function showHelp() {
  console.log(`
${color('JQHTML SSR Reference Example', 'bold')}

This CLI demonstrates the complete SSR workflow and serves as the
canonical reference for external integrations.

${color('USAGE:', 'yellow')}
  jqhtml-ssr-example --vendor <path> --app <path> --component <name> [options]

${color('REQUIRED:', 'yellow')}
  --vendor, -v <path>      Path to vendor bundle (contains @jqhtml/core)
  --app, -a <path>         Path to app bundle (contains components)
  --component, -c <name>   Component name to render

${color('OPTIONS:', 'yellow')}
  --args <json>            Component arguments as JSON (default: {})
  --base-url, -b <url>     Base URL for fetch requests (default: http://localhost:3000)
  --timeout, -t <ms>       Render timeout in milliseconds (default: 30000)
  --port, -p <port>        SSR server port (default: 9876)
  --socket, -s <path>      Use Unix socket instead of TCP
  --format, -f <format>    Output format: pretty, json, html-only (default: pretty)
  --help, -h               Show this help message

${color('EXAMPLES:', 'yellow')}
  # Basic render
  jqhtml-ssr-example -v ./vendor.js -a ./app.js -c Dashboard_Index_Action

  # With component arguments
  jqhtml-ssr-example -v ./vendor.js -a ./app.js -c User_Profile \\
    --args '{"user_id": 123}'

  # JSON output for piping
  jqhtml-ssr-example -v ./vendor.js -a ./app.js -c Dashboard_Index_Action \\
    --format json > result.json

  # HTML only output
  jqhtml-ssr-example -v ./vendor.js -a ./app.js -c Dashboard_Index_Action \\
    --format html-only > output.html

${color('OUTPUT:', 'yellow')}
  The 'pretty' format shows:
  - Rendered HTML (formatted)
  - localStorage cache entries
  - sessionStorage cache entries
  - Timing information

  The 'json' format outputs the raw server response.
  The 'html-only' format outputs just the HTML.

${color('INTEGRATION NOTES:', 'yellow')}
  This example demonstrates the exact protocol used by the SSR server.
  See the source code for implementation details that can be adapted
  to any language (PHP, Python, Ruby, Go, etc.).

  Key integration points:
  1. Connect to TCP port or Unix socket
  2. Send newline-delimited JSON request
  3. Read newline-delimited JSON response
  4. Parse response and extract HTML + cache

${color('SEE ALSO:', 'yellow')}
  README.md        - Full documentation
  SPECIFICATION.md - Protocol specification
`);
}

/**
 * Connect to SSR server
 * @param {object} config - Connection configuration
 * @returns {Promise<net.Socket>}
 */
function connect(config) {
  return new Promise((resolve, reject) => {
    const options = config.socket
      ? { path: config.socket }
      : { port: config.port, host: 'localhost' };

    const client = net.createConnection(options, () => {
      resolve(client);
    });

    client.on('error', (err) => {
      if (err.code === 'ECONNREFUSED') {
        reject(new Error(
          `Cannot connect to SSR server at ${config.socket || `localhost:${config.port}`}\n` +
          `Start the server with: node src/server.js --tcp ${config.port}`
        ));
      } else {
        reject(err);
      }
    });
  });
}

/**
 * Send request and receive response
 * @param {net.Socket} client - Connected socket
 * @param {object} request - Request object
 * @param {number} timeout - Timeout in ms
 * @returns {Promise<object>}
 */
function sendRequest(client, request, timeout) {
  return new Promise((resolve, reject) => {
    // Set timeout
    const timer = setTimeout(() => {
      client.end();
      reject(new Error(`Request timeout after ${timeout}ms`));
    }, timeout);

    // Collect response
    let data = '';
    client.on('data', (chunk) => {
      data += chunk.toString();
      if (data.includes('\n')) {
        clearTimeout(timer);
        client.end();
        try {
          resolve(JSON.parse(data.trim()));
        } catch (e) {
          reject(new Error(`Invalid response: ${data}`));
        }
      }
    });

    // Send request
    // IMPORTANT: Request must end with newline for server to process
    const requestStr = JSON.stringify(request) + '\n';
    client.write(requestStr);
  });
}

/**
 * Load bundle file
 * @param {string} filePath - Path to bundle file
 * @returns {string} Bundle content
 */
function loadBundle(filePath) {
  const resolvedPath = path.resolve(filePath);
  if (!fs.existsSync(resolvedPath)) {
    throw new Error(`Bundle file not found: ${resolvedPath}`);
  }
  return fs.readFileSync(resolvedPath, 'utf8');
}

/**
 * Format HTML for display (basic indentation)
 * @param {string} html - Raw HTML
 * @returns {string} Formatted HTML
 */
function formatHtml(html) {
  // Simple formatting - indent nested tags
  let formatted = '';
  let indent = 0;
  const lines = html
    .replace(/></g, '>\n<')
    .split('\n');

  for (const line of lines) {
    const trimmed = line.trim();
    if (!trimmed) continue;

    // Decrease indent for closing tags
    if (trimmed.startsWith('</')) {
      indent = Math.max(0, indent - 1);
    }

    formatted += '  '.repeat(indent) + trimmed + '\n';

    // Increase indent for opening tags (not self-closing)
    if (trimmed.startsWith('<') &&
        !trimmed.startsWith('</') &&
        !trimmed.endsWith('/>') &&
        !trimmed.includes('</')) {
      indent++;
    }
  }

  return formatted;
}

/**
 * Pretty print the result
 * @param {object} response - Server response
 * @param {object} config - Configuration
 */
function prettyPrint(response, config) {
  console.log('\n' + color('═'.repeat(70), 'dim'));
  console.log(color(' JQHTML SSR Render Result', 'bold'));
  console.log(color('═'.repeat(70), 'dim'));

  // Component info
  console.log(`\n${color('Component:', 'cyan')} ${config.component}`);
  if (Object.keys(config.componentArgs).length > 0) {
    console.log(`${color('Arguments:', 'cyan')} ${JSON.stringify(config.componentArgs)}`);
  }

  // Status
  if (response.status === 'success') {
    console.log(`${color('Status:', 'cyan')} ${color('SUCCESS', 'green')}`);
  } else {
    console.log(`${color('Status:', 'cyan')} ${color('ERROR', 'red')}`);
    console.log(`${color('Error Code:', 'red')} ${response.error.code}`);
    console.log(`${color('Error Message:', 'red')} ${response.error.message}`);
    if (response.error.stack) {
      console.log(`${color('Stack:', 'dim')}\n${response.error.stack}`);
    }
    return;
  }

  // Timing
  const timing = response.payload.timing;
  console.log(`\n${color('Timing:', 'cyan')}`);
  console.log(`  Total:       ${timing.total_ms}ms`);
  console.log(`  Bundle Load: ${timing.bundle_load_ms}ms`);
  console.log(`  Render:      ${timing.render_ms}ms`);

  // HTML
  console.log(`\n${color('Rendered HTML:', 'cyan')} (${response.payload.html.length} bytes)`);
  console.log(color('─'.repeat(70), 'dim'));
  console.log(formatHtml(response.payload.html));
  console.log(color('─'.repeat(70), 'dim'));

  // Cache - localStorage
  const localStorage = response.payload.cache.localStorage;
  const localStorageKeys = Object.keys(localStorage);
  console.log(`\n${color('localStorage Cache:', 'cyan')} (${localStorageKeys.length} entries)`);
  if (localStorageKeys.length > 0) {
    for (const key of localStorageKeys) {
      const value = localStorage[key];
      const preview = value.length > 100 ? value.substring(0, 100) + '...' : value;
      console.log(`  ${color(key, 'yellow')}: ${preview}`);
    }
  } else {
    console.log(color('  (empty)', 'dim'));
  }

  // Cache - sessionStorage
  const sessionStorage = response.payload.cache.sessionStorage;
  const sessionStorageKeys = Object.keys(sessionStorage);
  console.log(`\n${color('sessionStorage Cache:', 'cyan')} (${sessionStorageKeys.length} entries)`);
  if (sessionStorageKeys.length > 0) {
    for (const key of sessionStorageKeys) {
      const value = sessionStorage[key];
      const preview = value.length > 100 ? value.substring(0, 100) + '...' : value;
      console.log(`  ${color(key, 'yellow')}: ${preview}`);
    }
  } else {
    console.log(color('  (empty)', 'dim'));
  }

  console.log('\n' + color('═'.repeat(70), 'dim') + '\n');
}

/**
 * Main entry point
 */
async function main() {
  const config = parseArgs();

  if (config.showHelp) {
    showHelp();
    process.exit(0);
  }

  // Validate required arguments
  if (!config.vendor || !config.app || !config.component) {
    console.error(color('Error: --vendor, --app, and --component are required', 'red'));
    console.error('Use --help for usage information');
    process.exit(1);
  }

  try {
    // Load bundles
    console.log(color('Loading bundles...', 'dim'));
    const vendorContent = loadBundle(config.vendor);
    const appContent = loadBundle(config.app);
    console.log(`  Vendor: ${(vendorContent.length / 1024).toFixed(1)} KB`);
    console.log(`  App: ${(appContent.length / 1024).toFixed(1)} KB`);

    // Connect to server
    console.log(color('Connecting to SSR server...', 'dim'));
    const client = await connect(config);
    console.log(`  Connected to ${config.socket || `localhost:${config.port}`}`);

    // Build request
    // This is the canonical request format - use this as reference for integrations
    const request = {
      // Unique request ID - use for correlating requests/responses
      id: `render-${Date.now()}`,

      // Request type: 'ping', 'render', or 'flush_cache'
      type: 'render',

      // Payload contains all render parameters
      payload: {
        // Bundles array - order matters! Vendor before app.
        // Each bundle has 'id' (for caching) and 'content' (JS source)
        bundles: [
          { id: 'vendor', content: vendorContent },
          { id: 'app', content: appContent }
        ],

        // Component name to render (must be registered in bundles)
        component: config.component,

        // Arguments passed to component (becomes this.args)
        args: config.componentArgs,

        // Render options
        options: {
          // Base URL for resolving relative fetch/XHR URLs
          baseUrl: config.baseUrl,

          // Render timeout in milliseconds
          timeout: config.timeout
        }
      }
    };

    // Send request and get response
    console.log(color(`Rendering ${config.component}...`, 'dim'));
    const response = await sendRequest(client, request, config.timeout + 5000);

    // Output based on format
    switch (config.outputFormat) {
      case 'json':
        console.log(JSON.stringify(response, null, 2));
        break;

      case 'html-only':
        if (response.status === 'success') {
          console.log(response.payload.html);
        } else {
          console.error(`Error: ${response.error.message}`);
          process.exit(1);
        }
        break;

      case 'pretty':
      default:
        prettyPrint(response, config);
        break;
    }

    // Exit with appropriate code
    process.exit(response.status === 'success' ? 0 : 1);

  } catch (err) {
    console.error(color(`Error: ${err.message}`, 'red'));
    process.exit(1);
  }
}

main();
