declare const FLARE_JS_KEY: string | undefined;
declare const FLARE_JS_CLIENT_VERSION: string | undefined;
declare const FLARE_SOURCEMAP_VERSION: string | undefined;

export default {
    flareJsKey: typeof FLARE_JS_KEY === 'undefined' ? '' : FLARE_JS_KEY,
    clientVersion: typeof FLARE_JS_CLIENT_VERSION === 'undefined' ? '?' : FLARE_JS_CLIENT_VERSION,
    sourcemapVersion: typeof FLARE_SOURCEMAP_VERSION === 'undefined' ? '' : FLARE_SOURCEMAP_VERSION,
};
