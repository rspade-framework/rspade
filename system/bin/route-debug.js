#!/usr/bin/env node

/**
 * RSX Route Debug Script
 * Debugs a single route using Playwright
 * 
 * Usage: node route-debug.js <route> [options]
 * 
 * Arguments:
 *   route              The route to debug (e.g., /dashboard)
 * 
 * Options:
 *   --user=<id>        Test as specific user ID
 *   --log              Always display Laravel error log
 *   --no-body          Suppress body output
 *   --follow-redirects Follow redirects and show redirect chain
 *   --help             Show this help message
 */

const { chromium } = require('playwright');
const fs = require('fs');
const path = require('path');
const os = require('os');

// Parse command line arguments
function parse_args() {
    const args = process.argv.slice(2);
    
    if (args.length === 0 || args.includes('--help')) {
        console.log('RSX Route Debug - Debug a single route using Playwright');
        console.log('');
        console.log('Usage: node route-debug.js <route> [options]');
        console.log('');
        console.log('Arguments:');
        console.log('  route              The route to debug (e.g., /dashboard)');
        console.log('');
        console.log('Options:');
        console.log('  --user=<id>        Test as specific user ID');
        console.log('  --log              Always display Laravel error log');
        console.log('  --no-body          Suppress body output');
        console.log('  --follow-redirects Follow redirects and show redirect chain');
        console.log('  --headers          Display all response headers');
        console.log('  --console-log      Display all console output (not just errors) and console_debug() output even if SHOW_CONSOLE_DEBUG_HTTP=false');
        console.log('  --xhr-dump         Full XHR/fetch dump (headers, payload, response)');
        console.log('  --xhr-list         Simple XHR list (URLs and status codes only)');
        console.log('  --input-elements   List all form input elements on the page');
        console.log('  --post=<data>      Send POST request with JSON data (e.g., --post=\'{"key":"value"}\')'); 
        console.log('  --cookies          Display all cookies');
        console.log('  --wait-for=<sel>   Wait for element selector before capturing');
        console.log('  --all-logs         Display all log files (Laravel, nginx access/error)');
        console.log('  --expect-element=<sel> Verify element exists (fail if not found)');
        console.log('  --dump-element=<sel>   Extract and display HTML of specific element');
        console.log('  --storage          Dump localStorage and sessionStorage contents');
        console.log('  --full             Enable all display options for maximum info');
        console.log('  --eval=<code>      Execute async JavaScript after page loads (supports await)');
        console.log('                     Example: --eval="$(\'.btn-primary\').first().click(); await new Promise(r => setTimeout(r, 2000));"');
        console.log('  --timeout=<ms>     Navigation timeout in milliseconds (minimum 30000ms, default 30000ms)');
        console.log('  --console-debug-filter=<ch> Filter console_debug to specific channel');
        console.log('  --console-debug-benchmark   Include benchmark timing in console_debug');
        console.log('  --console-debug-all         Show all console_debug channels');
        console.log('  --dump-dimensions=<sel>    Add layout dimensions to matching elements');
        console.log('  --portal           Test portal routes (uses /_portal/ prefix)');
        console.log('  --portal-user=<id> Test as specific portal user ID');
        console.log('  --help             Show this help message');
        process.exit(0);
    }

    // Device viewport presets
    const device_presets = {
        'mobile': 412,              // Pixel 7
        'iphone-mobile': 390,       // iPhone 12/13/14
        'tablet': 768,              // iPad Mini
        'desktop-small': 1366,      // Common laptop
        'desktop-medium': 1920,     // Full HD
        'desktop-large': 2560       // 2K/WQHD
    };

    const options = {
        route: null,
        user_id: null,
        show_log: false,
        no_body: false,
        follow_redirects: false,
        headers: false,
        console_log: false,
        xhr_dump: false,
        xhr_list: false,
        input_elements: false,
        post_data: null,
        cookies: false,
        wait_for: null,
        all_logs: false,
        expect_element: null,
        dump_element: null,
        storage: false,
        eval_code: null,
        verbose: false,
        timeout: 30000,
        console_debug_filter: null,
        console_debug_benchmark: false,
        console_debug_all: false,
        console_debug_disable: false,
        screenshot_width: null,
        screenshot_path: null,
        dump_dimensions: null,
        dev_auth_token: null,
        portal: false,
        portal_user_id: null
    };
    
    for (const arg of args) {
        if (arg.startsWith('--user=')) {
            options.user_id = arg.split('=')[1];
        } else if (arg.startsWith('--dev-auth-token=')) {
            options.dev_auth_token = arg.split('=')[1];
        } else if (arg === '--log') {
            options.show_log = true;
        } else if (arg === '--no-body') {
            options.no_body = true;
        } else if (arg === '--follow-redirects') {
            options.follow_redirects = true;
        } else if (arg === '--headers') {
            options.headers = true;
        } else if (arg === '--console-log') {
            options.console_log = true;
        } else if (arg === '--xhr-dump') {
            options.xhr_dump = true;
        } else if (arg === '--xhr-list') {
            options.xhr_list = true;
        } else if (arg === '--input-elements') {
            options.input_elements = true;
        } else if (arg.startsWith('--post=')) {
            const postData = arg.substring(7); // Remove '--post=' prefix
            try {
                // Try to parse as JSON
                options.post_data = JSON.parse(postData);
            } catch (e) {
                // If not valid JSON, treat as form-encoded string
                options.post_data = postData;
            }
        } else if (arg === '--cookies') {
            options.cookies = true;
        } else if (arg.startsWith('--wait-for=')) {
            options.wait_for = arg.substring(11);
        } else if (arg === '--all-logs') {
            options.all_logs = true;
        } else if (arg.startsWith('--expect-element=')) {
            options.expect_element = arg.substring(17);
        } else if (arg.startsWith('--dump-element=')) {
            options.dump_element = arg.substring(15);
        } else if (arg === '--storage') {
            options.storage = true;
        } else if (arg === '--full') {
            options.full = true;
        } else if (arg.startsWith('--eval=')) {
            // Get the eval code after the equals sign
            let evalCode = arg.substring(7);
            options.eval_code = evalCode;
        } else if (arg.startsWith('--timeout=')) {
            options.timeout = parseInt(arg.substring(10));
            if (options.timeout < 30000) {
                console.error('Error: Timeout value is in milliseconds and must be no less than 30000 milliseconds (30 seconds)');
                process.exit(1);
            }
        } else if (arg.startsWith('--console-debug-filter=')) {
            options.console_debug_filter = arg.substring(23);
        } else if (arg === '--console-debug-benchmark') {
            options.console_debug_benchmark = true;
        } else if (arg === '--console-debug-all') {
            options.console_debug_all = true;
        } else if (arg === '--console-debug-disable') {
            options.console_debug_disable = true;
        } else if (arg.startsWith('--screenshot-width=')) {
            const width_value = arg.substring(19);
            // Check if it's a preset name or numeric value
            if (device_presets[width_value]) {
                options.screenshot_width = device_presets[width_value];
            } else {
                options.screenshot_width = parseInt(width_value);
            }
        } else if (arg.startsWith('--screenshot-path=')) {
            options.screenshot_path = arg.substring(18);
        } else if (arg.startsWith('--dump-dimensions=')) {
            options.dump_dimensions = arg.substring(18);
        } else if (arg === '--portal') {
            options.portal = true;
        } else if (arg.startsWith('--portal-user=')) {
            options.portal_user_id = arg.substring(14);
        } else if (!arg.startsWith('--')) {
            options.route = arg;
        }
    }
    
    if (!options.route) {
        console.error('Error: Route argument is required');
        process.exit(1);
    }
    
    // Ensure route starts with /
    if (!options.route.startsWith('/')) {
        options.route = '/' + options.route;
    }
    
    // If full mode, enable all display options (except no-body and follow-redirects)
    if (options.full) {
        options.headers = true;
        options.console_log = true;
        options.xhr_dump = true;
        options.input_elements = true;
        options.cookies = true;
        options.all_logs = true;
        options.storage = true;
    }
    
    return options;
}

// Main execution
(async () => {
    const options = parse_args();

    // Storage may live at the project root (relocation marker) or the historic
    // system/storage; resolve from this script's own location, not a hardcoded path.
    const project_root = path.resolve(__dirname, '..', '..');

    // Browse the APP_URL host (DNS-mapped to loopback via a Chromium resolver rule)
    // rather than a literal localhost, so everything that keys on the request host -
    // Rsx::get_hostname(), Rsx::is_debug_site(), environment-scoped auth gates, the
    // dev-mode hostname guard - sees the same identity a real browser does. Plain
    // http: nginx serves the name on :80 (upstream SSL termination is a deployment
    // concern). Falls back to localhost when no APP_URL host can be resolved.
    let app_host = null;
    try {
        const env_raw = fs.readFileSync(path.join(project_root, '.env'), 'utf8');
        const app_url_match = env_raw.match(/^APP_URL=\s*"?https?:\/\/([^\/"\s]+)/m);
        if (app_url_match && app_url_match[1]) {
            // The literal $HOSTNAME token resolves to the OS hostname (same rule the
            // framework applies at boot).
            app_host = app_url_match[1] === '$HOSTNAME' ? os.hostname() : app_url_match[1];
        }
    } catch (e) {
        // No readable .env - keep the localhost default.
    }
    const baseUrl = app_host ? 'http://' + app_host : 'http://localhost';

    // In portal mode, ensure route has /_portal prefix
    let route = options.route;
    if (options.portal && !route.startsWith('/_portal')) {
        route = '/_portal' + route;
    }
    const fullUrl = baseUrl + route;
    const storage_root = fs.existsSync(path.join(project_root, 'storage', '.rspade_storage_relocated'))
        ? path.join(project_root, 'storage')
        : path.join(project_root, 'system', 'storage');
    const laravel_log_path = process.env.LARAVEL_LOG_PATH || path.join(storage_root, 'logs', 'laravel.log');
    
    // Launch browser (always headless)
    const browser = await chromium.launch({
        headless: true,
        args: ['--no-sandbox', '--disable-setuid-sandbox'].concat(
            app_host ? ['--host-resolver-rules=MAP ' + app_host + ' 127.0.0.1'] : [])
    });

    // Set viewport for screenshot if requested
    const contextOptions = {
        ignoreHTTPSErrors: true
    };

    if (options.screenshot_path) {
        // Default to 1920 if width not specified
        const screenshot_width = options.screenshot_width || 1920;
        contextOptions.viewport = {
            width: screenshot_width,
            height: 1080 // Will expand to full page height on screenshot
        };
        options.screenshot_width = screenshot_width; // Store for later use
    }

    const context = await browser.newContext(contextOptions);
    
    const page = await context.newPage();
    
    // Collect console messages
    const consoleErrors = [];
    const consoleMessages = [];

    // Collect uncaught page errors (exceptions)
    page.on('pageerror', error => {
        const errorMsg = `[UNCAUGHT] ${error.message}`;
        const stack = error.stack ? error.stack.split('\n').slice(1, 3).join('\n') : '';
        consoleErrors.push(errorMsg + (stack ? '\n' + stack : ''));
    });

    page.on('console', async msg => {
        const type = msg.type();
        const text = msg.text();
        const location = msg.location();

        // Get stack trace for errors and warnings
        let stackTrace = [];
        if (type === 'error' || type === 'warning') {
            try {
                const trace = msg.stackTrace();
                if (trace && trace.length > 0) {
                    // Get up to 3 stack frames
                    stackTrace = trace.slice(0, 3).map(frame => {
                        if (frame.url && !frame.url.startsWith(baseUrl)) {
                            return `    at ${frame.url}:${frame.lineNumber}:${frame.columnNumber}`;
                        } else if (frame.url && frame.url.includes('.js')) {
                            const urlPath = frame.url.replace(baseUrl, '');
                            return `    at ${urlPath}:${frame.lineNumber}:${frame.columnNumber}`;
                        } else if (frame.functionName) {
                            return `    at ${frame.functionName}`;
                        }
                        return null;
                    }).filter(Boolean);
                }
            } catch (e) {
                // Stack trace not available
            }
        }

        // Only include location for JS files, not inline HTML scripts
        let locationStr = '';
        if (location && location.url && !location.url.startsWith(baseUrl)) {
            // External JS file or file:// URL
            locationStr = `${location.url}:${location.lineNumber}`;
        } else if (location && location.url && location.url.includes('.js')) {
            // Local JS file (not inline HTML)
            const urlPath = location.url.replace(baseUrl, '');
            locationStr = `${urlPath}:${location.lineNumber}`;
        }

        // Format the message with type label for errors/warnings
        let formattedMsg = text;
        if (type === 'error') {
            formattedMsg = `[ERROR] ${locationStr ? `[${locationStr}] ` : ''}${text}`;
            if (stackTrace.length > 0) {
                formattedMsg += '\n' + stackTrace.join('\n');
            }
            consoleErrors.push(formattedMsg);
        } else if (type === 'warning') {
            formattedMsg = `[WARN] ${locationStr ? `[${locationStr}] ` : ''}${text}`;
            if (stackTrace.length > 0) {
                formattedMsg += '\n' + stackTrace.join('\n');
            }
        }

        if (options.console_log) {
            consoleMessages.push({
                type: type,
                text: formattedMsg,
                location: locationStr
            });
        }
    });
    
    // Collect network failures  
    const networkFailures = [];
    page.on('requestfailed', request => {
        networkFailures.push(request.url());
    });
    
    // Collect XHR/fetch requests and responses
    const xhrRequests = [];
    if (options.xhr_dump || options.xhr_list) {
        page.on('request', request => {
            const resourceType = request.resourceType();
            if (resourceType === 'xhr' || resourceType === 'fetch') {
                xhrRequests.push({
                    url: request.url(),
                    method: request.method(),
                    headers: request.headers(),
                    postData: request.postData(),
                    response: null
                });
            }
        });
        
        page.on('response', response => {
            const request = response.request();
            const resourceType = request.resourceType();
            if (resourceType === 'xhr' || resourceType === 'fetch') {
                const xhrRequest = xhrRequests.find(r => r.url === request.url() && !r.response);
                if (xhrRequest) {
                    xhrRequest.response = {
                        status: response.status(),
                        headers: response.headers(),
                        body: null
                    };
                    // Try to get response body
                    response.text().then(body => {
                        xhrRequest.response.body = body;
                    }).catch(() => {
                        // Ignore errors getting body
                    });
                }
            }
        });
    }
    
    // Track redirect chain
    const redirectChain = [];
    if (options.follow_redirects) {
        page.on('response', response => {
            const status = response.status();
            if (status >= 300 && status < 400) {
                const location = response.headers()['location'];
                if (location) {
                    redirectChain.push({
                        url: response.url(),
                        status: status,
                        location: location
                    });
                }
            }
        });
    }
    
    // Set up headers for initial request only
    const extraHeaders = {};
    if (options.user_id) {
        extraHeaders['X-Dev-Auth-User-Id'] = options.user_id;
    }
    if (options.portal_user_id) {
        extraHeaders['X-Dev-Auth-Portal-User-Id'] = options.portal_user_id;
    }
    if (options.dev_auth_token) {
        extraHeaders['X-Dev-Auth-Token'] = options.dev_auth_token;
    }
    // Add Playwright test header to get text errors
    extraHeaders['X-Playwright-Test'] = '1';
    // Add console debug header if console logging is requested
    if (options.console_log || options.full) {
        extraHeaders['X-Playwright-Console-Debug'] = '1';
    }
    // Add console debug filter headers
    if (options.console_debug_filter) {
        extraHeaders['X-Console-Debug-Filter'] = options.console_debug_filter;
    }
    if (options.console_debug_benchmark) {
        extraHeaders['X-Console-Debug-Benchmark'] = '1';
    }
    if (options.console_debug_all) {
        extraHeaders['X-Console-Debug-All'] = '1';
    }
    if (options.console_debug_disable) {
        extraHeaders['X-Console-Debug-Disable'] = '1';
    }

    // Use route interception to only add headers to main request
    await page.route('**/*', async (route, request) => {
        const url = request.url();
        // Only add headers to our local requests, not CDN requests
        if (url.startsWith(baseUrl)) {
            await route.continue({
                headers: {
                    ...request.headers(),
                    ...extraHeaders
                }
            });
        } else {
            // For external requests, continue without custom headers
            await route.continue();
        }
    });
    
    // Navigate to the route with retry logic for connection refused
    let response;
    let lastError;
    const maxRetries = 3;
    
    // Handle POST requests differently
    if (options.post_data) {
        // Navigate to a blank page first
        await page.goto('about:blank');
        
        // Make POST request using fetch in the page context
        const fetchResult = await page.evaluate(async ({url, data, headers}) => {
            let body;
            let contentType;
            
            if (typeof data === 'object') {
                // Send as JSON
                body = JSON.stringify(data);
                contentType = 'application/json';
            } else {
                // Send as form-encoded
                body = data;
                contentType = 'application/x-www-form-urlencoded';
            }
            
            const response = await fetch(url, {
                method: 'POST',
                headers: {
                    'Content-Type': contentType,
                    ...headers
                },
                body: body
            });
            
            const text = await response.text();
            return {
                status: response.status,
                headers: Object.fromEntries(response.headers.entries()),
                body: text,
                url: response.url
            };
        }, {url: fullUrl, data: options.post_data, headers: extraHeaders});
        
        // Create a mock response object similar to page.goto response
        response = {
            status: () => fetchResult.status,
            headers: () => fetchResult.headers,
            text: async () => fetchResult.body,
            url: () => fetchResult.url
        };
        
        // If response contains HTML, set it as page content for further analysis
        if (fetchResult.headers['content-type'] && fetchResult.headers['content-type'].includes('text/html')) {
            await page.setContent(fetchResult.body, {waitUntil: 'networkidle'});
        }
    } else {
        // GET request with retries
        for (let attempt = 1; attempt <= maxRetries; attempt++) {
            try {
                response = await page.goto(fullUrl, {
                    waitUntil: 'networkidle',
                    timeout: options.timeout
                });
                break; // Success, exit retry loop
        } catch (error) {
            lastError = error;
            
            // Check if it's a connection refused error
            if (error.message.includes('ERR_CONNECTION_REFUSED')) {
                if (attempt < maxRetries) {
                    // Wait before retrying (exponential backoff: 1s, 2s, 4s)
                    const delay = Math.pow(2, attempt - 1) * 1000;
                    console.error(`Connection refused, retrying in ${delay}ms (attempt ${attempt}/${maxRetries})...`);
                    await new Promise(resolve => setTimeout(resolve, delay));
                    continue;
                }
            }
            
                // Not a connection refused error or max retries reached
                console.log(`FAIL ${fullUrl} - Navigation error: ${error.message}`);
                await browser.close();
                process.exit(1);
            }
        }
    }
    
    if (!response) {
        console.log(`FAIL ${fullUrl} - Navigation error after ${maxRetries} attempts: ${lastError.message}`);
        await browser.close();
        process.exit(1);
    }

    // Wait for RSX framework and jqhtml components to complete initialization
    // This ensures all components have gone through their lifecycle (render -> create -> load -> ready)
    await page.evaluate(() => {
        return new Promise((resolve) => {
            // Check if RSX framework with _debug_ready event is available
            if (window.Rsx && window.Rsx.on) {
                // Use Rsx._debug_ready event which fires after all jqhtml components complete lifecycle
                window.Rsx.on('_debug_ready', function() {
                    resolve();
                });
            } else {
                // Fallback for non-RSX pages: wait for DOMContentLoaded + 200ms
                if (document.readyState === 'complete' || document.readyState === 'interactive') {
                    setTimeout(function() { resolve(); }, 200);
                } else {
                    document.addEventListener('DOMContentLoaded', function() {
                        setTimeout(function() { resolve(); }, 200);
                    });
                }
            }
        });
    });

    // Wait for specific element if requested
    if (options.wait_for) {
        try {
            await page.waitForSelector(options.wait_for, {
                timeout: options.timeout
            });
        } catch (e) {
            console.log(`Warning: Element '${options.wait_for}' not found within ${options.timeout / 1000} seconds`);
        }
    }
    
    // Verify element exists if --expect-element is passed
    if (options.expect_element) {
        const elementExists = await page.evaluate((selector) => {
            return document.querySelector(selector) !== null;
        }, options.expect_element);
        
        if (!elementExists) {
            console.log(`FAIL: Expected element '${options.expect_element}' not found on page`);
            await browser.close();
            process.exit(1);
        }
    }
    
    // Dump specific element HTML if --dump-element is passed
    if (options.dump_element) {
        const elementHTML = await page.evaluate((selector) => {
            const elem = document.querySelector(selector);
            if (elem) {
                return elem.outerHTML;
            }
            return null;
        }, options.dump_element);
        
        if (elementHTML) {
            console.log(`\nElement HTML for '${options.dump_element}':`);
            console.log(elementHTML);
            console.log('');
        } else {
            console.log(`Warning: Element '${options.dump_element}' not found for dumping`);
        }
    }

    // Add dimensions to elements matching selector if --dump-dimensions is passed
    // This injects data-dimensions attributes with layout info (x, y, width, height, margin, padding)
    // Useful for AI agents diagnosing layout issues without visual inspection
    if (options.dump_dimensions) {
        const dimensionsResult = await page.evaluate((selector) => {
            const elements = document.querySelectorAll(selector);
            if (elements.length === 0) {
                return { count: 0, error: 'No elements found' };
            }

            let count = 0;
            elements.forEach((elem) => {
                const rect = elem.getBoundingClientRect();
                const style = window.getComputedStyle(elem);

                // Parse margin values and round to nearest pixel
                const marginTop = Math.round(parseFloat(style.marginTop) || 0);
                const marginRight = Math.round(parseFloat(style.marginRight) || 0);
                const marginBottom = Math.round(parseFloat(style.marginBottom) || 0);
                const marginLeft = Math.round(parseFloat(style.marginLeft) || 0);

                // Parse padding values and round to nearest pixel
                const paddingTop = Math.round(parseFloat(style.paddingTop) || 0);
                const paddingRight = Math.round(parseFloat(style.paddingRight) || 0);
                const paddingBottom = Math.round(parseFloat(style.paddingBottom) || 0);
                const paddingLeft = Math.round(parseFloat(style.paddingLeft) || 0);

                // Format margin - use shorthand if all same, otherwise 4 values
                let margin;
                if (marginTop === marginRight && marginRight === marginBottom && marginBottom === marginLeft) {
                    margin = marginTop;
                } else {
                    margin = `${marginTop} ${marginRight} ${marginBottom} ${marginLeft}`;
                }

                // Format padding - use shorthand if all same, otherwise 4 values
                let padding;
                if (paddingTop === paddingRight && paddingRight === paddingBottom && paddingBottom === paddingLeft) {
                    padding = paddingTop;
                } else {
                    padding = `${paddingTop} ${paddingRight} ${paddingBottom} ${paddingLeft}`;
                }

                const dimensions = {
                    x: Math.round(rect.x),
                    y: Math.round(rect.y),
                    w: Math.round(rect.width),
                    h: Math.round(rect.height),
                    margin: margin,
                    padding: padding
                };

                elem.setAttribute('data-dimensions', JSON.stringify(dimensions));
                count++;
            });

            return { count: count };
        }, options.dump_dimensions);

        if (dimensionsResult.error) {
            console.log(`\nWarning: ${dimensionsResult.error} for selector '${options.dump_dimensions}'`);
        } else {
            console.log(`\nDimensions: Added data-dimensions to ${dimensionsResult.count} element(s) matching '${options.dump_dimensions}'`);
        }
    }

    // Execute eval code if --eval option is passed
    // This runs AFTER page is fully loaded and ready, supports async/await
    if (options.eval_code) {
        try {
            const evalResult = await page.evaluate(async (code) => {
                try {
                    // Wrap code in async function to support await
                    const asyncFunc = new Function('return (async () => { ' + code + ' })()');
                    const result = await asyncFunc();

                    // Convert result to string representation
                    if (result === undefined) {
                        return 'undefined';
                    } else if (result === null) {
                        return 'null';
                    } else if (typeof result === 'object') {
                        try {
                            return JSON.stringify(result, null, 2);
                        } catch (e) {
                            return String(result);
                        }
                    } else {
                        return String(result);
                    }
                } catch (error) {
                    return `Error: ${error.message}`;
                }
            }, options.eval_code);

            console.log('\nJavaScript Eval Result:');
            console.log(evalResult);
            console.log('');
        } catch (error) {
            console.log('\nJavaScript Eval Error:');
            console.log(error.message);
            console.log('');
        }
    }

    // Get response details
    const status = response.status();
    const headers_response = response.headers();
    // Get the live DOM content after JavaScript execution, not the initial HTTP response
    const body = await page.content();
    
    // Build output line
    let output = `${status} ${fullUrl}`;
    
    if (options.post_data) {
        output += ` method:POST`;
    }
    
    if (options.user_id) {
        output += ` user:${options.user_id}`;
    }

    if (options.portal) {
        output += ` portal:true`;
    }

    if (options.portal_user_id) {
        output += ` portal-user:${options.portal_user_id}`;
    }
    
    // Add key headers
    if (headers_response['content-type']) {
        output += ` type:${headers_response['content-type'].split(';')[0]}`;
    }
    
    if (headers_response['x-frame-options']) {
        output += ` x-frame:${headers_response['x-frame-options']}`;
    }
    
    // Add errors if any
    if (consoleErrors.length > 0) {
        output += ` console-errors:${consoleErrors.length}`;
    }
    
    if (networkFailures.length > 0) {
        output += ` network-failures:${networkFailures.length}`;
    }
    
    // Output single log line
    console.log(output);
    
    // Show redirect chain if --follow-redirects was used
    if (options.follow_redirects && redirectChain.length > 0) {
        console.log('');
        console.log('Redirect Chain:');
        redirectChain.forEach((redirect, index) => {
            console.log(`  ${index + 1}. ${redirect.status} ${redirect.url} -> ${redirect.location}`);
        });
        console.log(`  ${redirectChain.length + 1}. ${status} ${response.url()} (final)`);
    } else {
        // Show redirect location if this is a redirect and we're not following
        const redirect_codes = [301, 302, 303, 307, 308];
        if (redirect_codes.includes(status) && headers_response['location']) {
            console.log(`Redirect Location: ${headers_response['location']}`);
        }
    }
    
    console.log('');
    
    // Check for JavaScript syntax errors - these are critical and should be shown prominently
    const syntaxErrors = consoleErrors.filter(error =>
        error.includes('SyntaxError') ||
        error.includes('Unexpected token') ||
        error.includes('expected expression')
    );

    if (syntaxErrors.length > 0) {
        // For syntax errors, make this the primary output regardless of flags
        console.log('===============================================');
        console.log('CRITICAL: JavaScript Syntax Error Detected');
        console.log('===============================================');
        console.log('');
        console.log('The page cannot load properly due to JavaScript syntax errors:');
        console.log('');
        for (const error of syntaxErrors) {
            // Extract file and line info if present
            const fileMatch = error.match(/([^:\s]+\.js):(\d+):?(\d+)?/);
            if (fileMatch) {
                console.log(`File: ${fileMatch[1]}`);
                console.log(`Line: ${fileMatch[2]}${fileMatch[3] ? ', Column: ' + fileMatch[3] : ''}`);
                console.log('');
            }

            // Output the full error
            const lines = error.split('\n');
            lines.forEach(line => console.log(line));
        }
        console.log('');
        console.log('===============================================');
        console.log('Fix the syntax error above before proceeding.');
        console.log('Other debug output suppressed due to critical error.');
        console.log('===============================================');

        // Exit early for syntax errors
        process.exit(1);
    }

    // Show JavaScript console messages or errors (normal flow)
    if (options.console_log && consoleMessages.length > 0) {
        console.log('JavaScript Console Output:');
        for (const msg of consoleMessages) {
            // For errors and warnings, text already includes the type prefix
            if (msg.type === 'error' || msg.type === 'warning') {
                // Split on newlines to handle stack traces properly
                const lines = msg.text.split('\n');
                lines.forEach(line => console.log(`  ${line}`));
            } else {
                console.log(`  [${msg.type}] ${msg.text}`);
            }
        }
    } else if (consoleErrors.length > 0) {
        console.log('JavaScript Console Errors:');
        for (const error of consoleErrors) {
            // Split on newlines to handle stack traces properly
            const lines = error.split('\n');
            lines.forEach(line => console.log(`  ${line}`));
        }
    } else {
        console.log('JavaScript Console Errors: None');
    }
    console.log('');
    
    // Show response headers if --headers flag is passed
    if (options.headers) {
        console.log('Response Headers:');
        const sorted_headers = Object.keys(headers_response).sort();
        for (const header of sorted_headers) {
            console.log(`  ${header}: ${headers_response[header]}`);
        }
        console.log('');
    }
    
    // Show network failures if any
    if (networkFailures.length > 0) {
        console.log('Network Failures:');
        for (const url of networkFailures) {
            console.log(`  ${url}`);
        }
        console.log('');
    }
    
    // Show XHR/fetch requests based on flags
    if ((options.xhr_dump || options.xhr_list) && xhrRequests.length > 0) {
        // Wait a bit for responses to complete
        await new Promise(resolve => setTimeout(resolve, 500));
        
        if (options.xhr_list) {
            // Simple list mode
            console.log('XHR/Fetch Requests (simple list):');
            for (const xhr of xhrRequests) {
                const status = xhr.response ? xhr.response.status : 'pending';
                console.log(`  ${xhr.method} ${xhr.url} - ${status}`);
            }
            console.log('');
        }
        
        if (options.xhr_dump) {
            // Full dump mode
            console.log('XHR/Fetch Requests (full dump):');
            for (const xhr of xhrRequests) {
                console.log(`  ${xhr.method} ${xhr.url}`);
                console.log('    Request Headers:');
                for (const [key, value] of Object.entries(xhr.headers)) {
                    console.log(`      ${key}: ${value}`);
                }
                if (xhr.postData) {
                    console.log(`    Request Body:`);
                    console.log(`      ${xhr.postData}`);
                }
                if (xhr.response) {
                    console.log(`    Response Status: ${xhr.response.status}`);
                    console.log('    Response Headers:');
                    for (const [key, value] of Object.entries(xhr.response.headers)) {
                        console.log(`      ${key}: ${value}`);
                    }
                    if (xhr.response.body) {
                        console.log(`    Response Body:`);
                        console.log(`      ${xhr.response.body}`);
                    }
                } else {
                    console.log(`    Response: (pending or failed)`);
                }
                console.log('');
            }
        }
    }
    
    // Show input elements if --input-elements flag is passed
    if (options.input_elements) {
        const inputs = await page.evaluate(() => {
            const elements = [];
            
            // Find all input elements
            document.querySelectorAll('input, select, textarea').forEach(elem => {
                const info = {
                    tag: elem.tagName.toLowerCase(),
                    type: elem.type || '',
                    name: elem.name || '',
                    id: elem.id || '',
                    value: elem.value || '',
                    placeholder: elem.placeholder || '',
                    required: elem.required || false,
                    disabled: elem.disabled || false,
                    readonly: elem.readOnly || false
                };
                
                // For select elements, get options
                if (elem.tagName.toLowerCase() === 'select') {
                    info.options = Array.from(elem.options).map(opt => ({
                        value: opt.value,
                        text: opt.text,
                        selected: opt.selected
                    }));
                }
                
                // For checkboxes and radios, get checked state
                if (elem.type === 'checkbox' || elem.type === 'radio') {
                    info.checked = elem.checked || false;
                }
                
                elements.push(info);
            });
            
            return elements;
        });
        
        if (inputs.length > 0) {
            console.log('Form Input Elements:');
            for (const input of inputs) {
                let desc = `  <${input.tag}`;
                if (input.type) desc += ` type="${input.type}"`;
                if (input.name) desc += ` name="${input.name}"`;
                if (input.id) desc += ` id="${input.id}"`;
                desc += '>';
                console.log(desc);
                
                if (input.value && input.type !== 'password') {
                    console.log(`    Value: "${input.value}"`);
                }
                if (input.placeholder) {
                    console.log(`    Placeholder: "${input.placeholder}"`);
                }
                if (input.required) {
                    console.log(`    Required: true`);
                }
                if (input.disabled) {
                    console.log(`    Disabled: true`);
                }
                if (input.readonly) {
                    console.log(`    Readonly: true`);
                }
                if (input.checked !== undefined) {
                    console.log(`    Checked: ${input.checked}`);
                }
                if (input.options) {
                    console.log(`    Options:`);
                    for (const opt of input.options) {
                        console.log(`      - "${opt.text}" (value: "${opt.value}")${opt.selected ? ' [selected]' : ''}`);
                    }
                }
            }
        } else {
            console.log('Form Input Elements: None found');
        }
        console.log('');
    }
    
    // Show cookies if --cookies flag is passed
    if (options.cookies) {
        const cookies = await context.cookies();
        if (cookies.length > 0) {
            console.log('Cookies:');
            for (const cookie of cookies) {
                console.log(`  ${cookie.name}: ${cookie.value}`);
                if (cookie.domain) console.log(`    Domain: ${cookie.domain}`);
                if (cookie.path) console.log(`    Path: ${cookie.path}`);
                if (cookie.expires) console.log(`    Expires: ${new Date(cookie.expires * 1000).toISOString()}`);
                if (cookie.httpOnly) console.log(`    HttpOnly: true`);
                if (cookie.secure) console.log(`    Secure: true`);
                if (cookie.sameSite) console.log(`    SameSite: ${cookie.sameSite}`);
            }
        } else {
            console.log('Cookies: None');
        }
        console.log('');
    }
    
    // Show storage if --storage flag is passed
    if (options.storage) {
        const storageData = await page.evaluate(() => {
            const data = {
                localStorage: {},
                sessionStorage: {}
            };
            
            // Get localStorage
            try {
                for (let i = 0; i < localStorage.length; i++) {
                    const key = localStorage.key(i);
                    data.localStorage[key] = localStorage.getItem(key);
                }
            } catch (e) {
                data.localStorage = 'Error accessing localStorage: ' + e.message;
            }
            
            // Get sessionStorage
            try {
                for (let i = 0; i < sessionStorage.length; i++) {
                    const key = sessionStorage.key(i);
                    data.sessionStorage[key] = sessionStorage.getItem(key);
                }
            } catch (e) {
                data.sessionStorage = 'Error accessing sessionStorage: ' + e.message;
            }
            
            return data;
        });
        
        console.log('localStorage:');
        if (typeof storageData.localStorage === 'string') {
            console.log(`  ${storageData.localStorage}`);
        } else if (Object.keys(storageData.localStorage).length > 0) {
            for (const [key, value] of Object.entries(storageData.localStorage)) {
                console.log(`  ${key}: ${value}`);
            }
        } else {
            console.log('  (empty)');
        }
        console.log('');
        
        console.log('sessionStorage:');
        if (typeof storageData.sessionStorage === 'string') {
            console.log(`  ${storageData.sessionStorage}`);
        } else if (Object.keys(storageData.sessionStorage).length > 0) {
            for (const [key, value] of Object.entries(storageData.sessionStorage)) {
                console.log(`  ${key}: ${value}`);
            }
        } else {
            console.log('  (empty)');
        }
        console.log('');
    }
    
    // Check if body contains rsx_dump_die() output
    const has_rsx_dump_die = body && body.includes('rsx_dump_die() called');

    // Show response body unless --no-body is passed (but always show rsx_dump_die output)
    if (!options.no_body || has_rsx_dump_die) {
        if (has_rsx_dump_die) {
            // Extract and show only the rsx_dump_die() output when --no-body is set
            if (options.no_body) {
                const dump_start = body.indexOf('rsx_dump_die() called');
                const dump_output = body.substring(dump_start);
                console.log('rsx_dump_die() Output Detected:');
                console.log(dump_output);
            } else {
                // Show full body when --no-body is not set
                console.log('HTTP Response Body:');
                console.log(body);
            }
        } else if (!options.no_body) {
            // Normal body output when no rsx_dump_die
            console.log('HTTP Response Body:');
            if (body && body.trim()) {
                console.log(body);
            } else {
                console.log('(empty)');

                // If 500 with empty body, show Laravel log
                if (status === 500 && fs.existsSync(laravel_log_path)) {
                    console.log('');
                    console.log('Laravel Log:');
                    try {
                        const log = fs.readFileSync(laravel_log_path, 'utf8');
                        // Get last 50 lines of log
                        const lines = log.trim().split('\n');
                        const lastLines = lines.slice(-50).join('\n');
                        console.log(lastLines);
                    } catch (e) {
                        console.log(`Error reading log: ${e.message}`);
                    }
                }
            }
        }
    }
    
    // Show Laravel log if --log flag was passed
    if (options.show_log && fs.existsSync(laravel_log_path)) {
        console.log('');
        console.log('Laravel Log:');
        try {
            const log = fs.readFileSync(laravel_log_path, 'utf8');
            console.log(log);
        } catch (e) {
            console.log(`Error reading log: ${e.message}`);
        }
    }
    
    // Display logs based on --all-logs flag
    console.log('');
    
    const nginx_error_log = '/var/log/nginx/error.log';
    const nginx_access_log = '/var/log/nginx/access.log';
    
    if (options.all_logs) {
        // Display all logs when --all-logs is passed
        
        // Laravel log
        console.log('\x1b[1m\x1b[37mlaravel.log:\x1b[0m');
        if (fs.existsSync(laravel_log_path)) {
            try {
                const log = fs.readFileSync(laravel_log_path, 'utf8');
                console.log(log || '(empty)');
            } catch (e) {
                console.log(`Error reading log: ${e.message}`);
            }
        } else {
            console.log('(file not found)');
        }
        console.log('');
        
        // Nginx error log
        console.log('\x1b[1m\x1b[37mnginx error.log:\x1b[0m');
        if (fs.existsSync(nginx_error_log)) {
            try {
                const log = fs.readFileSync(nginx_error_log, 'utf8');
                console.log(log || '(empty)');
            } catch (e) {
                console.log(`Error reading log: ${e.message}`);
            }
        } else {
            console.log('(file not found)');
        }
        console.log('');
        
        // Nginx access log
        console.log('\x1b[1m\x1b[37mnginx access.log:\x1b[0m');
        if (fs.existsSync(nginx_access_log)) {
            try {
                const log = fs.readFileSync(nginx_access_log, 'utf8');
                console.log(log || '(empty)');
            } catch (e) {
                console.log(`Error reading log: ${e.message}`);
            }
        } else {
            console.log('(file not found)');
        }
    } else {
        // Default: Only show errors/warnings
        
        // Check Laravel log for errors/warnings
        if (fs.existsSync(laravel_log_path)) {
            try {
                const log = fs.readFileSync(laravel_log_path, 'utf8');
                const lines = log.split('\n');
                const errorLines = lines.filter(line => 
                    line.includes('.ERROR') || 
                    line.includes('.WARNING') || 
                    line.includes('.CRITICAL') ||
                    line.includes('.ALERT') ||
                    line.includes('.EMERGENCY')
                );
                
                if (errorLines.length > 0) {
                    console.log('\x1b[1m\x1b[37mlaravel.log errors:\x1b[0m');
                    console.log(errorLines.join('\n'));
                    console.log('');
                }
            } catch (e) {
                // Ignore read errors
            }
        }
        
        // Check nginx error log
        if (fs.existsSync(nginx_error_log)) {
            try {
                const log = fs.readFileSync(nginx_error_log, 'utf8');
                if (log && log.trim()) {
                    console.log('\x1b[1m\x1b[37mnginx error.log:\x1b[0m');
                    console.log(log);
                    console.log('');
                }
            } catch (e) {
                // Ignore read errors
            }
        }
    }

    // Take screenshot if requested
    if (options.screenshot_path) {
        try {
            await page.screenshot({
                path: options.screenshot_path,
                fullPage: true,
                clip: {
                    x: 0,
                    y: 0,
                    width: options.screenshot_width,
                    height: Math.min(5000, await page.evaluate(() => document.documentElement.scrollHeight))
                }
            });
            console.log(`Screenshot saved to: ${options.screenshot_path} (width: ${options.screenshot_width}px)`);
        } catch (e) {
            console.error(`Failed to save screenshot: ${e.message}`);
        }
    }

    await browser.close();
    
    // Exit with appropriate code
    if (status >= 400 || consoleErrors.length > 0 || networkFailures.length > 0) {
        process.exit(1);
    }
    process.exit(0);
})();