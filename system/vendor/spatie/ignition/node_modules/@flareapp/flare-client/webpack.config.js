const webpack = require('../../node_modules/webpack');
const merge = require('../../node_modules/webpack-merge');
const path = require('path');

const baseConfig = require('../../webpack.config');

module.exports = merge(baseConfig, {
    output: {
        library: '@flareapp/flare-client',
        path: path.resolve(__dirname, 'dist'),
    },

    plugins: [
        new webpack.DefinePlugin({
            FLARE_JS_CLIENT_VERSION: JSON.stringify(require('./package.json').version),
        }),
    ],
});
