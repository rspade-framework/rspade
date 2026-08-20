/**
 * rsx-lockd configuration: locate, parse, validate.
 *
 * Pure apart from reading the file it is told to read. It never guesses a path on its own
 * and never touches process.argv/process.env - the caller passes those in - so a test can
 * exercise the whole search order and every validation branch with plain strings.
 *
 * SEARCH ORDER (first hit wins):
 *   1. --config=<path>            explicit invocation intent
 *   2. LOCKD_CONFIG=<path>        environment
 *   3. <project>/lockd.conf       the operator's file
 *   4. <shipped>/lockd.conf       the copy beside the binary
 *
 * Step 3 is the one that matters inside RSpade: system/bin/ is a framework-owned zone that
 * `rsx:framework:pull` hard-syncs, so a downstream edit to the SHIPPED file is overwritten
 * on the next pull. The project-root file is the supported place to change settings.
 *
 * Validation is loud and total: an unknown key, a wrong type, or both listeners disabled
 * is an error with a message naming the key, never a silent default.
 */

const fs = require('fs');
const path = require('path');

const CONFIG_FILENAME = 'lockd.conf';

// The complete schema. Anything outside it is an error - a typo'd key that silently did
// nothing would be indistinguishable from a setting that does not work.
const SCHEMA = {
    unix: {
        enabled: 'boolean',
        path: 'string',
        mode: 'string',
    },
    tcp: {
        enabled: 'boolean',
        port: 'integer',
        bind: 'string',
    },
    auth: {
        hmac_key_env: 'string',
    },
    pidfile: 'string',
    logfile: 'string_or_null',
};

const DEFAULT_CONFIG = {
    unix: { enabled: false, path: '/var/run/rsx-lockd.sock', mode: '0660' },
    tcp: { enabled: true, port: 6210, bind: '0.0.0.0' },
    auth: { hmac_key_env: 'APP_KEY' },
    pidfile: '/var/run/rsx-lockd.pid',
    logfile: null,
};

/**
 * Resolve which config file to use. Returns { path, source } - source is one of
 * 'flag' | 'env' | 'project' | 'shipped' - or throws when an explicitly requested file
 * does not exist (an explicit request that silently falls through is a trap).
 */
function resolve_config_path(options) {
    const cli_path = options.cli_path || null;
    const env_path = options.env_path || null;
    const project_root = options.project_root || null;
    const shipped_dir = options.shipped_dir || null;

    if (cli_path) {
        if (!fs.existsSync(cli_path)) {
            throw new Error('Config file not found: ' + cli_path + ' (from --config=)');
        }
        return { path: path.resolve(cli_path), source: 'flag' };
    }

    if (env_path) {
        if (!fs.existsSync(env_path)) {
            throw new Error('Config file not found: ' + env_path + ' (from LOCKD_CONFIG)');
        }
        return { path: path.resolve(env_path), source: 'env' };
    }

    if (project_root) {
        const candidate = path.join(project_root, CONFIG_FILENAME);
        if (fs.existsSync(candidate)) {
            return { path: candidate, source: 'project' };
        }
    }

    if (shipped_dir) {
        const candidate = path.join(shipped_dir, CONFIG_FILENAME);
        if (fs.existsSync(candidate)) {
            return { path: candidate, source: 'shipped' };
        }
    }

    throw new Error(
        'No ' + CONFIG_FILENAME + ' found. Looked for --config=, LOCKD_CONFIG, '
        + (project_root ? path.join(project_root, CONFIG_FILENAME) : '<project>/' + CONFIG_FILENAME)
        + ' and the copy beside the binary.'
    );
}

/** Read and JSON-parse a config file. Throws with the file named on any failure. */
function read_config_file(file_path) {
    let raw;
    try {
        raw = fs.readFileSync(file_path, 'utf8');
    } catch (err) {
        throw new Error('Cannot read config ' + file_path + ': ' + err.message);
    }

    // Tolerate whole-line // comments so an operator can annotate the file. Nothing else
    // about the format deviates from JSON.
    const stripped = raw
        .split('\n')
        .filter((line) => !/^\s*\/\//.test(line))
        .join('\n');

    try {
        return JSON.parse(stripped);
    } catch (err) {
        throw new Error('Config ' + file_path + ' is not valid JSON: ' + err.message);
    }
}

/**
 * Validate a parsed config object against SCHEMA and fill in defaults for absent keys.
 * Returns the normalized config. Throws an Error listing EVERY problem found, not just
 * the first - fixing one typo at a time across three round trips is not a diagnostic.
 */
function validate_config(input) {
    const problems = [];

    if (input === null || typeof input !== 'object' || Array.isArray(input)) {
        throw new Error('Config must be a JSON object');
    }

    const out = JSON.parse(JSON.stringify(DEFAULT_CONFIG));

    for (const key of Object.keys(input)) {
        if (!Object.prototype.hasOwnProperty.call(SCHEMA, key)) {
            problems.push("unknown key '" + key + "'");
        }
    }

    for (const key of Object.keys(SCHEMA)) {
        const spec = SCHEMA[key];
        const value = input[key];

        if (typeof spec === 'object') {
            if (value === undefined) continue;
            if (value === null || typeof value !== 'object' || Array.isArray(value)) {
                problems.push("'" + key + "' must be an object");
                continue;
            }
            for (const sub_key of Object.keys(value)) {
                if (!Object.prototype.hasOwnProperty.call(spec, sub_key)) {
                    problems.push("unknown key '" + key + '.' + sub_key + "'");
                }
            }
            for (const sub_key of Object.keys(spec)) {
                if (value[sub_key] === undefined) continue;
                const problem = check_type(key + '.' + sub_key, value[sub_key], spec[sub_key]);
                if (problem) problems.push(problem);
                else out[key][sub_key] = value[sub_key];
            }
            continue;
        }

        if (value === undefined) continue;
        const problem = check_type(key, value, spec);
        if (problem) problems.push(problem);
        else out[key] = value;
    }

    if (!out.unix.enabled && !out.tcp.enabled) {
        problems.push('both listeners are disabled (unix.enabled and tcp.enabled are false) - nothing could connect');
    }
    if (out.tcp.enabled && (out.tcp.port < 1 || out.tcp.port > 65535)) {
        problems.push("'tcp.port' must be between 1 and 65535");
    }
    if (out.unix.enabled && !/^0?[0-7]{3,4}$/.test(out.unix.mode)) {
        problems.push("'unix.mode' must be an octal permission string such as \"0660\"");
    }
    if (!out.auth.hmac_key_env) {
        problems.push("'auth.hmac_key_env' must name the environment variable holding the shared key");
    }

    if (problems.length > 0) {
        throw new Error('Invalid config:\n  - ' + problems.join('\n  - '));
    }

    return out;
}

function check_type(key, value, type) {
    if (type === 'boolean') {
        return typeof value === 'boolean' ? null : "'" + key + "' must be true or false";
    }
    if (type === 'integer') {
        return Number.isInteger(value) ? null : "'" + key + "' must be an integer";
    }
    if (type === 'string') {
        return (typeof value === 'string' && value.length > 0)
            ? null
            : "'" + key + "' must be a non-empty string";
    }
    if (type === 'string_or_null') {
        if (value === null) return null;
        return (typeof value === 'string' && value.length > 0)
            ? null
            : "'" + key + "' must be a non-empty string or null";
    }
    return "'" + key + "' has an unhandled schema type";
}

/** Locate, read and validate in one call. Returns { config, path, source }. */
function load_config(options) {
    const located = resolve_config_path(options);
    const config = validate_config(read_config_file(located.path));
    return { config: config, path: located.path, source: located.source };
}

module.exports = {
    CONFIG_FILENAME,
    DEFAULT_CONFIG,
    SCHEMA,
    resolve_config_path,
    read_config_file,
    validate_config,
    load_config,
};
