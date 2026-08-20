var __getOwnPropNames = Object.getOwnPropertyNames;
var __commonJS = (cb, mod) => function __require() {
  return mod || (0, cb[__getOwnPropNames(cb)[0]])((mod = { exports: {} }).exports, mod), mod.exports;
};

// node_modules/@babel/helper-plugin-utils/lib/index.js
var require_lib = __commonJS({
  "node_modules/@babel/helper-plugin-utils/lib/index.js"(exports2) {
    "use strict";
    Object.defineProperty(exports2, "__esModule", {
      value: true
    });
    exports2.declare = declare;
    exports2.declarePreset = void 0;
    var apiPolyfills = {
      assertVersion: (api) => (range) => {
        throwVersionError(range, api.version);
      }
    };
    Object.assign(apiPolyfills, {
      targets: () => () => {
        return {};
      },
      assumption: () => () => {
        return void 0;
      },
      addExternalDependency: () => () => {
      }
    });
    function declare(builder) {
      return (api, options, dirname) => {
        let clonedApi;
        for (const name of Object.keys(apiPolyfills)) {
          if (api[name]) continue;
          clonedApi != null ? clonedApi : clonedApi = copyApiObject(api);
          clonedApi[name] = apiPolyfills[name](clonedApi);
        }
        return builder(clonedApi != null ? clonedApi : api, options || {}, dirname);
      };
    }
    var declarePreset = exports2.declarePreset = declare;
    function copyApiObject(api) {
      let proto = null;
      if (typeof api.version === "string" && api.version.startsWith("7.")) {
        proto = Object.getPrototypeOf(api);
        if (proto && (!hasOwnProperty.call(proto, "version") || !hasOwnProperty.call(proto, "transform") || !hasOwnProperty.call(proto, "template") || !hasOwnProperty.call(proto, "types"))) {
          proto = null;
        }
      }
      return Object.assign({}, proto, api);
    }
    function throwVersionError(range, version) {
      if (typeof range === "number") {
        if (!Number.isInteger(range)) {
          throw new Error("Expected string or integer value.");
        }
        range = `^${range}.0.0-0`;
      }
      if (typeof range !== "string") {
        throw new Error("Expected string or integer value.");
      }
      const limit = Error.stackTraceLimit;
      if (typeof limit === "number" && limit < 25) {
        Error.stackTraceLimit = 25;
      }
      let err;
      if (version.startsWith("7.")) {
        err = new Error(`Requires Babel "^7.0.0-beta.41", but was loaded with "${version}". You'll need to update your @babel/core version.`);
      } else {
        err = new Error(`Requires Babel "${range}", but was loaded with "${version}". If you are sure you have a compatible version of @babel/core, it is likely that something in your build process is loading the wrong version. Inspect the stack trace of this error to look for the first entry that doesn't mention "@babel/core" or "babel-core" to see what is calling Babel.`);
      }
      if (typeof limit === "number") {
        Error.stackTraceLimit = limit;
      }
      throw Object.assign(err, {
        code: "BABEL_VERSION_UNSUPPORTED",
        version,
        range
      });
    }
  }
});

// node_modules/@babel/plugin-syntax-decorators/lib/index.js
var require_lib2 = __commonJS({
  "node_modules/@babel/plugin-syntax-decorators/lib/index.js"(exports2) {
    "use strict";
    Object.defineProperty(exports2, "__esModule", {
      value: true
    });
    exports2.default = void 0;
    var _helperPluginUtils = require_lib();
    var _default = exports2.default = (0, _helperPluginUtils.declare)((api, options) => {
      api.assertVersion("^7.0.0-0 || ^8.0.0-0 || >8.0.0-alpha <8.0.0-beta");
      let {
        version
      } = options;
      const {
        legacy
      } = options;
      if (legacy !== void 0) {
        if (typeof legacy !== "boolean") {
          throw new Error(".legacy must be a boolean.");
        }
        if (version !== void 0) {
          throw new Error("You can either use the .legacy or the .version option, not both.");
        }
      }
      if (version === void 0) {
        version = legacy ? "legacy" : "2018-09";
      } else if (version !== "2023-11" && version !== "2023-05" && version !== "2023-01" && version !== "2022-03" && version !== "2021-12" && version !== "2018-09" && version !== "legacy") {
        throw new Error("Unsupported decorators version: " + version);
      }
      var {
        decoratorsBeforeExport
      } = options;
      if (decoratorsBeforeExport === void 0) {
        if (version === "2021-12" || version === "2022-03") {
          decoratorsBeforeExport = false;
        } else if (version === "2018-09") {
          throw new Error("The decorators plugin, when .version is '2018-09' or not specified, requires a 'decoratorsBeforeExport' option, whose value must be a boolean.");
        }
      } else {
        if (version === "legacy" || version === "2022-03" || version === "2023-01") {
          throw new Error(`'decoratorsBeforeExport' can't be used with ${version} decorators.`);
        }
        if (typeof decoratorsBeforeExport !== "boolean") {
          throw new Error("'decoratorsBeforeExport' must be a boolean.");
        }
      }
      return {
        name: "syntax-decorators",
        manipulateOptions({
          generatorOpts
        }, parserOpts) {
          if (version === "legacy") {
            parserOpts.plugins.push("decorators-legacy");
          } else {
            if (version === "2023-01" || version === "2023-05" || version === "2023-11") {
              parserOpts.plugins.push(["decorators", {
                allowCallParenthesized: false
              }], "decoratorAutoAccessors");
            } else if (version === "2022-03") {
              parserOpts.plugins.push(["decorators", {
                decoratorsBeforeExport: false,
                allowCallParenthesized: false
              }], "decoratorAutoAccessors");
            } else if (version === "2021-12") {
              parserOpts.plugins.push(["decorators", {
                decoratorsBeforeExport
              }], "decoratorAutoAccessors");
              generatorOpts.decoratorsBeforeExport = decoratorsBeforeExport;
            } else if (version === "2018-09") {
              parserOpts.plugins.push(["decorators", {
                decoratorsBeforeExport
              }]);
              generatorOpts.decoratorsBeforeExport = decoratorsBeforeExport;
            }
          }
        }
      };
    });
  }
});

// node_modules/semver/semver.js
var require_semver = __commonJS({
  "node_modules/semver/semver.js"(exports2, module2) {
    exports2 = module2.exports = SemVer;
    var debug;
    if (typeof process === "object" && process.env && process.env.NODE_DEBUG && /\bsemver\b/i.test(process.env.NODE_DEBUG)) {
      debug = function() {
        var args = Array.prototype.slice.call(arguments, 0);
        args.unshift("SEMVER");
        console.log.apply(console, args);
      };
    } else {
      debug = function() {
      };
    }
    exports2.SEMVER_SPEC_VERSION = "2.0.0";
    var MAX_LENGTH = 256;
    var MAX_SAFE_INTEGER = Number.MAX_SAFE_INTEGER || /* istanbul ignore next */
    9007199254740991;
    var MAX_SAFE_COMPONENT_LENGTH = 16;
    var MAX_SAFE_BUILD_LENGTH = MAX_LENGTH - 6;
    var re = exports2.re = [];
    var safeRe = exports2.safeRe = [];
    var src = exports2.src = [];
    var t = exports2.tokens = {};
    var R = 0;
    function tok(n) {
      t[n] = R++;
    }
    var LETTERDASHNUMBER = "[a-zA-Z0-9-]";
    var safeRegexReplacements = [
      ["\\s", 1],
      ["\\d", MAX_LENGTH],
      [LETTERDASHNUMBER, MAX_SAFE_BUILD_LENGTH]
    ];
    function makeSafeRe(value) {
      for (var i2 = 0; i2 < safeRegexReplacements.length; i2++) {
        var token = safeRegexReplacements[i2][0];
        var max = safeRegexReplacements[i2][1];
        value = value.split(token + "*").join(token + "{0," + max + "}").split(token + "+").join(token + "{1," + max + "}");
      }
      return value;
    }
    tok("NUMERICIDENTIFIER");
    src[t.NUMERICIDENTIFIER] = "0|[1-9]\\d*";
    tok("NUMERICIDENTIFIERLOOSE");
    src[t.NUMERICIDENTIFIERLOOSE] = "\\d+";
    tok("NONNUMERICIDENTIFIER");
    src[t.NONNUMERICIDENTIFIER] = "\\d*[a-zA-Z-]" + LETTERDASHNUMBER + "*";
    tok("MAINVERSION");
    src[t.MAINVERSION] = "(" + src[t.NUMERICIDENTIFIER] + ")\\.(" + src[t.NUMERICIDENTIFIER] + ")\\.(" + src[t.NUMERICIDENTIFIER] + ")";
    tok("MAINVERSIONLOOSE");
    src[t.MAINVERSIONLOOSE] = "(" + src[t.NUMERICIDENTIFIERLOOSE] + ")\\.(" + src[t.NUMERICIDENTIFIERLOOSE] + ")\\.(" + src[t.NUMERICIDENTIFIERLOOSE] + ")";
    tok("PRERELEASEIDENTIFIER");
    src[t.PRERELEASEIDENTIFIER] = "(?:" + src[t.NUMERICIDENTIFIER] + "|" + src[t.NONNUMERICIDENTIFIER] + ")";
    tok("PRERELEASEIDENTIFIERLOOSE");
    src[t.PRERELEASEIDENTIFIERLOOSE] = "(?:" + src[t.NUMERICIDENTIFIERLOOSE] + "|" + src[t.NONNUMERICIDENTIFIER] + ")";
    tok("PRERELEASE");
    src[t.PRERELEASE] = "(?:-(" + src[t.PRERELEASEIDENTIFIER] + "(?:\\." + src[t.PRERELEASEIDENTIFIER] + ")*))";
    tok("PRERELEASELOOSE");
    src[t.PRERELEASELOOSE] = "(?:-?(" + src[t.PRERELEASEIDENTIFIERLOOSE] + "(?:\\." + src[t.PRERELEASEIDENTIFIERLOOSE] + ")*))";
    tok("BUILDIDENTIFIER");
    src[t.BUILDIDENTIFIER] = LETTERDASHNUMBER + "+";
    tok("BUILD");
    src[t.BUILD] = "(?:\\+(" + src[t.BUILDIDENTIFIER] + "(?:\\." + src[t.BUILDIDENTIFIER] + ")*))";
    tok("FULL");
    tok("FULLPLAIN");
    src[t.FULLPLAIN] = "v?" + src[t.MAINVERSION] + src[t.PRERELEASE] + "?" + src[t.BUILD] + "?";
    src[t.FULL] = "^" + src[t.FULLPLAIN] + "$";
    tok("LOOSEPLAIN");
    src[t.LOOSEPLAIN] = "[v=\\s]*" + src[t.MAINVERSIONLOOSE] + src[t.PRERELEASELOOSE] + "?" + src[t.BUILD] + "?";
    tok("LOOSE");
    src[t.LOOSE] = "^" + src[t.LOOSEPLAIN] + "$";
    tok("GTLT");
    src[t.GTLT] = "((?:<|>)?=?)";
    tok("XRANGEIDENTIFIERLOOSE");
    src[t.XRANGEIDENTIFIERLOOSE] = src[t.NUMERICIDENTIFIERLOOSE] + "|x|X|\\*";
    tok("XRANGEIDENTIFIER");
    src[t.XRANGEIDENTIFIER] = src[t.NUMERICIDENTIFIER] + "|x|X|\\*";
    tok("XRANGEPLAIN");
    src[t.XRANGEPLAIN] = "[v=\\s]*(" + src[t.XRANGEIDENTIFIER] + ")(?:\\.(" + src[t.XRANGEIDENTIFIER] + ")(?:\\.(" + src[t.XRANGEIDENTIFIER] + ")(?:" + src[t.PRERELEASE] + ")?" + src[t.BUILD] + "?)?)?";
    tok("XRANGEPLAINLOOSE");
    src[t.XRANGEPLAINLOOSE] = "[v=\\s]*(" + src[t.XRANGEIDENTIFIERLOOSE] + ")(?:\\.(" + src[t.XRANGEIDENTIFIERLOOSE] + ")(?:\\.(" + src[t.XRANGEIDENTIFIERLOOSE] + ")(?:" + src[t.PRERELEASELOOSE] + ")?" + src[t.BUILD] + "?)?)?";
    tok("XRANGE");
    src[t.XRANGE] = "^" + src[t.GTLT] + "\\s*" + src[t.XRANGEPLAIN] + "$";
    tok("XRANGELOOSE");
    src[t.XRANGELOOSE] = "^" + src[t.GTLT] + "\\s*" + src[t.XRANGEPLAINLOOSE] + "$";
    tok("COERCE");
    src[t.COERCE] = "(^|[^\\d])(\\d{1," + MAX_SAFE_COMPONENT_LENGTH + "})(?:\\.(\\d{1," + MAX_SAFE_COMPONENT_LENGTH + "}))?(?:\\.(\\d{1," + MAX_SAFE_COMPONENT_LENGTH + "}))?(?:$|[^\\d])";
    tok("COERCERTL");
    re[t.COERCERTL] = new RegExp(src[t.COERCE], "g");
    safeRe[t.COERCERTL] = new RegExp(makeSafeRe(src[t.COERCE]), "g");
    tok("LONETILDE");
    src[t.LONETILDE] = "(?:~>?)";
    tok("TILDETRIM");
    src[t.TILDETRIM] = "(\\s*)" + src[t.LONETILDE] + "\\s+";
    re[t.TILDETRIM] = new RegExp(src[t.TILDETRIM], "g");
    safeRe[t.TILDETRIM] = new RegExp(makeSafeRe(src[t.TILDETRIM]), "g");
    var tildeTrimReplace = "$1~";
    tok("TILDE");
    src[t.TILDE] = "^" + src[t.LONETILDE] + src[t.XRANGEPLAIN] + "$";
    tok("TILDELOOSE");
    src[t.TILDELOOSE] = "^" + src[t.LONETILDE] + src[t.XRANGEPLAINLOOSE] + "$";
    tok("LONECARET");
    src[t.LONECARET] = "(?:\\^)";
    tok("CARETTRIM");
    src[t.CARETTRIM] = "(\\s*)" + src[t.LONECARET] + "\\s+";
    re[t.CARETTRIM] = new RegExp(src[t.CARETTRIM], "g");
    safeRe[t.CARETTRIM] = new RegExp(makeSafeRe(src[t.CARETTRIM]), "g");
    var caretTrimReplace = "$1^";
    tok("CARET");
    src[t.CARET] = "^" + src[t.LONECARET] + src[t.XRANGEPLAIN] + "$";
    tok("CARETLOOSE");
    src[t.CARETLOOSE] = "^" + src[t.LONECARET] + src[t.XRANGEPLAINLOOSE] + "$";
    tok("COMPARATORLOOSE");
    src[t.COMPARATORLOOSE] = "^" + src[t.GTLT] + "\\s*(" + src[t.LOOSEPLAIN] + ")$|^$";
    tok("COMPARATOR");
    src[t.COMPARATOR] = "^" + src[t.GTLT] + "\\s*(" + src[t.FULLPLAIN] + ")$|^$";
    tok("COMPARATORTRIM");
    src[t.COMPARATORTRIM] = "(\\s*)" + src[t.GTLT] + "\\s*(" + src[t.LOOSEPLAIN] + "|" + src[t.XRANGEPLAIN] + ")";
    re[t.COMPARATORTRIM] = new RegExp(src[t.COMPARATORTRIM], "g");
    safeRe[t.COMPARATORTRIM] = new RegExp(makeSafeRe(src[t.COMPARATORTRIM]), "g");
    var comparatorTrimReplace = "$1$2$3";
    tok("HYPHENRANGE");
    src[t.HYPHENRANGE] = "^\\s*(" + src[t.XRANGEPLAIN] + ")\\s+-\\s+(" + src[t.XRANGEPLAIN] + ")\\s*$";
    tok("HYPHENRANGELOOSE");
    src[t.HYPHENRANGELOOSE] = "^\\s*(" + src[t.XRANGEPLAINLOOSE] + ")\\s+-\\s+(" + src[t.XRANGEPLAINLOOSE] + ")\\s*$";
    tok("STAR");
    src[t.STAR] = "(<|>)?=?\\s*\\*";
    for (i = 0; i < R; i++) {
      debug(i, src[i]);
      if (!re[i]) {
        re[i] = new RegExp(src[i]);
        safeRe[i] = new RegExp(makeSafeRe(src[i]));
      }
    }
    var i;
    exports2.parse = parse;
    function parse(version, options) {
      if (!options || typeof options !== "object") {
        options = {
          loose: !!options,
          includePrerelease: false
        };
      }
      if (version instanceof SemVer) {
        return version;
      }
      if (typeof version !== "string") {
        return null;
      }
      if (version.length > MAX_LENGTH) {
        return null;
      }
      var r = options.loose ? safeRe[t.LOOSE] : safeRe[t.FULL];
      if (!r.test(version)) {
        return null;
      }
      try {
        return new SemVer(version, options);
      } catch (er) {
        return null;
      }
    }
    exports2.valid = valid;
    function valid(version, options) {
      var v = parse(version, options);
      return v ? v.version : null;
    }
    exports2.clean = clean;
    function clean(version, options) {
      var s = parse(version.trim().replace(/^[=v]+/, ""), options);
      return s ? s.version : null;
    }
    exports2.SemVer = SemVer;
    function SemVer(version, options) {
      if (!options || typeof options !== "object") {
        options = {
          loose: !!options,
          includePrerelease: false
        };
      }
      if (version instanceof SemVer) {
        if (version.loose === options.loose) {
          return version;
        } else {
          version = version.version;
        }
      } else if (typeof version !== "string") {
        throw new TypeError("Invalid Version: " + version);
      }
      if (version.length > MAX_LENGTH) {
        throw new TypeError("version is longer than " + MAX_LENGTH + " characters");
      }
      if (!(this instanceof SemVer)) {
        return new SemVer(version, options);
      }
      debug("SemVer", version, options);
      this.options = options;
      this.loose = !!options.loose;
      var m = version.trim().match(options.loose ? safeRe[t.LOOSE] : safeRe[t.FULL]);
      if (!m) {
        throw new TypeError("Invalid Version: " + version);
      }
      this.raw = version;
      this.major = +m[1];
      this.minor = +m[2];
      this.patch = +m[3];
      if (this.major > MAX_SAFE_INTEGER || this.major < 0) {
        throw new TypeError("Invalid major version");
      }
      if (this.minor > MAX_SAFE_INTEGER || this.minor < 0) {
        throw new TypeError("Invalid minor version");
      }
      if (this.patch > MAX_SAFE_INTEGER || this.patch < 0) {
        throw new TypeError("Invalid patch version");
      }
      if (!m[4]) {
        this.prerelease = [];
      } else {
        this.prerelease = m[4].split(".").map(function(id) {
          if (/^[0-9]+$/.test(id)) {
            var num = +id;
            if (num >= 0 && num < MAX_SAFE_INTEGER) {
              return num;
            }
          }
          return id;
        });
      }
      this.build = m[5] ? m[5].split(".") : [];
      this.format();
    }
    SemVer.prototype.format = function() {
      this.version = this.major + "." + this.minor + "." + this.patch;
      if (this.prerelease.length) {
        this.version += "-" + this.prerelease.join(".");
      }
      return this.version;
    };
    SemVer.prototype.toString = function() {
      return this.version;
    };
    SemVer.prototype.compare = function(other) {
      debug("SemVer.compare", this.version, this.options, other);
      if (!(other instanceof SemVer)) {
        other = new SemVer(other, this.options);
      }
      return this.compareMain(other) || this.comparePre(other);
    };
    SemVer.prototype.compareMain = function(other) {
      if (!(other instanceof SemVer)) {
        other = new SemVer(other, this.options);
      }
      return compareIdentifiers(this.major, other.major) || compareIdentifiers(this.minor, other.minor) || compareIdentifiers(this.patch, other.patch);
    };
    SemVer.prototype.comparePre = function(other) {
      if (!(other instanceof SemVer)) {
        other = new SemVer(other, this.options);
      }
      if (this.prerelease.length && !other.prerelease.length) {
        return -1;
      } else if (!this.prerelease.length && other.prerelease.length) {
        return 1;
      } else if (!this.prerelease.length && !other.prerelease.length) {
        return 0;
      }
      var i2 = 0;
      do {
        var a = this.prerelease[i2];
        var b = other.prerelease[i2];
        debug("prerelease compare", i2, a, b);
        if (a === void 0 && b === void 0) {
          return 0;
        } else if (b === void 0) {
          return 1;
        } else if (a === void 0) {
          return -1;
        } else if (a === b) {
          continue;
        } else {
          return compareIdentifiers(a, b);
        }
      } while (++i2);
    };
    SemVer.prototype.compareBuild = function(other) {
      if (!(other instanceof SemVer)) {
        other = new SemVer(other, this.options);
      }
      var i2 = 0;
      do {
        var a = this.build[i2];
        var b = other.build[i2];
        debug("prerelease compare", i2, a, b);
        if (a === void 0 && b === void 0) {
          return 0;
        } else if (b === void 0) {
          return 1;
        } else if (a === void 0) {
          return -1;
        } else if (a === b) {
          continue;
        } else {
          return compareIdentifiers(a, b);
        }
      } while (++i2);
    };
    SemVer.prototype.inc = function(release, identifier) {
      switch (release) {
        case "premajor":
          this.prerelease.length = 0;
          this.patch = 0;
          this.minor = 0;
          this.major++;
          this.inc("pre", identifier);
          break;
        case "preminor":
          this.prerelease.length = 0;
          this.patch = 0;
          this.minor++;
          this.inc("pre", identifier);
          break;
        case "prepatch":
          this.prerelease.length = 0;
          this.inc("patch", identifier);
          this.inc("pre", identifier);
          break;
        // If the input is a non-prerelease version, this acts the same as
        // prepatch.
        case "prerelease":
          if (this.prerelease.length === 0) {
            this.inc("patch", identifier);
          }
          this.inc("pre", identifier);
          break;
        case "major":
          if (this.minor !== 0 || this.patch !== 0 || this.prerelease.length === 0) {
            this.major++;
          }
          this.minor = 0;
          this.patch = 0;
          this.prerelease = [];
          break;
        case "minor":
          if (this.patch !== 0 || this.prerelease.length === 0) {
            this.minor++;
          }
          this.patch = 0;
          this.prerelease = [];
          break;
        case "patch":
          if (this.prerelease.length === 0) {
            this.patch++;
          }
          this.prerelease = [];
          break;
        // This probably shouldn't be used publicly.
        // 1.0.0 "pre" would become 1.0.0-0 which is the wrong direction.
        case "pre":
          if (this.prerelease.length === 0) {
            this.prerelease = [0];
          } else {
            var i2 = this.prerelease.length;
            while (--i2 >= 0) {
              if (typeof this.prerelease[i2] === "number") {
                this.prerelease[i2]++;
                i2 = -2;
              }
            }
            if (i2 === -1) {
              this.prerelease.push(0);
            }
          }
          if (identifier) {
            if (this.prerelease[0] === identifier) {
              if (isNaN(this.prerelease[1])) {
                this.prerelease = [identifier, 0];
              }
            } else {
              this.prerelease = [identifier, 0];
            }
          }
          break;
        default:
          throw new Error("invalid increment argument: " + release);
      }
      this.format();
      this.raw = this.version;
      return this;
    };
    exports2.inc = inc;
    function inc(version, release, loose, identifier) {
      if (typeof loose === "string") {
        identifier = loose;
        loose = void 0;
      }
      try {
        return new SemVer(version, loose).inc(release, identifier).version;
      } catch (er) {
        return null;
      }
    }
    exports2.diff = diff;
    function diff(version1, version2) {
      if (eq(version1, version2)) {
        return null;
      } else {
        var v1 = parse(version1);
        var v2 = parse(version2);
        var prefix = "";
        if (v1.prerelease.length || v2.prerelease.length) {
          prefix = "pre";
          var defaultResult = "prerelease";
        }
        for (var key in v1) {
          if (key === "major" || key === "minor" || key === "patch") {
            if (v1[key] !== v2[key]) {
              return prefix + key;
            }
          }
        }
        return defaultResult;
      }
    }
    exports2.compareIdentifiers = compareIdentifiers;
    var numeric = /^[0-9]+$/;
    function compareIdentifiers(a, b) {
      var anum = numeric.test(a);
      var bnum = numeric.test(b);
      if (anum && bnum) {
        a = +a;
        b = +b;
      }
      return a === b ? 0 : anum && !bnum ? -1 : bnum && !anum ? 1 : a < b ? -1 : 1;
    }
    exports2.rcompareIdentifiers = rcompareIdentifiers;
    function rcompareIdentifiers(a, b) {
      return compareIdentifiers(b, a);
    }
    exports2.major = major;
    function major(a, loose) {
      return new SemVer(a, loose).major;
    }
    exports2.minor = minor;
    function minor(a, loose) {
      return new SemVer(a, loose).minor;
    }
    exports2.patch = patch;
    function patch(a, loose) {
      return new SemVer(a, loose).patch;
    }
    exports2.compare = compare;
    function compare(a, b, loose) {
      return new SemVer(a, loose).compare(new SemVer(b, loose));
    }
    exports2.compareLoose = compareLoose;
    function compareLoose(a, b) {
      return compare(a, b, true);
    }
    exports2.compareBuild = compareBuild;
    function compareBuild(a, b, loose) {
      var versionA = new SemVer(a, loose);
      var versionB = new SemVer(b, loose);
      return versionA.compare(versionB) || versionA.compareBuild(versionB);
    }
    exports2.rcompare = rcompare;
    function rcompare(a, b, loose) {
      return compare(b, a, loose);
    }
    exports2.sort = sort;
    function sort(list, loose) {
      return list.sort(function(a, b) {
        return exports2.compareBuild(a, b, loose);
      });
    }
    exports2.rsort = rsort;
    function rsort(list, loose) {
      return list.sort(function(a, b) {
        return exports2.compareBuild(b, a, loose);
      });
    }
    exports2.gt = gt;
    function gt(a, b, loose) {
      return compare(a, b, loose) > 0;
    }
    exports2.lt = lt;
    function lt(a, b, loose) {
      return compare(a, b, loose) < 0;
    }
    exports2.eq = eq;
    function eq(a, b, loose) {
      return compare(a, b, loose) === 0;
    }
    exports2.neq = neq;
    function neq(a, b, loose) {
      return compare(a, b, loose) !== 0;
    }
    exports2.gte = gte;
    function gte(a, b, loose) {
      return compare(a, b, loose) >= 0;
    }
    exports2.lte = lte;
    function lte(a, b, loose) {
      return compare(a, b, loose) <= 0;
    }
    exports2.cmp = cmp;
    function cmp(a, op, b, loose) {
      switch (op) {
        case "===":
          if (typeof a === "object")
            a = a.version;
          if (typeof b === "object")
            b = b.version;
          return a === b;
        case "!==":
          if (typeof a === "object")
            a = a.version;
          if (typeof b === "object")
            b = b.version;
          return a !== b;
        case "":
        case "=":
        case "==":
          return eq(a, b, loose);
        case "!=":
          return neq(a, b, loose);
        case ">":
          return gt(a, b, loose);
        case ">=":
          return gte(a, b, loose);
        case "<":
          return lt(a, b, loose);
        case "<=":
          return lte(a, b, loose);
        default:
          throw new TypeError("Invalid operator: " + op);
      }
    }
    exports2.Comparator = Comparator;
    function Comparator(comp, options) {
      if (!options || typeof options !== "object") {
        options = {
          loose: !!options,
          includePrerelease: false
        };
      }
      if (comp instanceof Comparator) {
        if (comp.loose === !!options.loose) {
          return comp;
        } else {
          comp = comp.value;
        }
      }
      if (!(this instanceof Comparator)) {
        return new Comparator(comp, options);
      }
      comp = comp.trim().split(/\s+/).join(" ");
      debug("comparator", comp, options);
      this.options = options;
      this.loose = !!options.loose;
      this.parse(comp);
      if (this.semver === ANY) {
        this.value = "";
      } else {
        this.value = this.operator + this.semver.version;
      }
      debug("comp", this);
    }
    var ANY = {};
    Comparator.prototype.parse = function(comp) {
      var r = this.options.loose ? safeRe[t.COMPARATORLOOSE] : safeRe[t.COMPARATOR];
      var m = comp.match(r);
      if (!m) {
        throw new TypeError("Invalid comparator: " + comp);
      }
      this.operator = m[1] !== void 0 ? m[1] : "";
      if (this.operator === "=") {
        this.operator = "";
      }
      if (!m[2]) {
        this.semver = ANY;
      } else {
        this.semver = new SemVer(m[2], this.options.loose);
      }
    };
    Comparator.prototype.toString = function() {
      return this.value;
    };
    Comparator.prototype.test = function(version) {
      debug("Comparator.test", version, this.options.loose);
      if (this.semver === ANY || version === ANY) {
        return true;
      }
      if (typeof version === "string") {
        try {
          version = new SemVer(version, this.options);
        } catch (er) {
          return false;
        }
      }
      return cmp(version, this.operator, this.semver, this.options);
    };
    Comparator.prototype.intersects = function(comp, options) {
      if (!(comp instanceof Comparator)) {
        throw new TypeError("a Comparator is required");
      }
      if (!options || typeof options !== "object") {
        options = {
          loose: !!options,
          includePrerelease: false
        };
      }
      var rangeTmp;
      if (this.operator === "") {
        if (this.value === "") {
          return true;
        }
        rangeTmp = new Range(comp.value, options);
        return satisfies(this.value, rangeTmp, options);
      } else if (comp.operator === "") {
        if (comp.value === "") {
          return true;
        }
        rangeTmp = new Range(this.value, options);
        return satisfies(comp.semver, rangeTmp, options);
      }
      var sameDirectionIncreasing = (this.operator === ">=" || this.operator === ">") && (comp.operator === ">=" || comp.operator === ">");
      var sameDirectionDecreasing = (this.operator === "<=" || this.operator === "<") && (comp.operator === "<=" || comp.operator === "<");
      var sameSemVer = this.semver.version === comp.semver.version;
      var differentDirectionsInclusive = (this.operator === ">=" || this.operator === "<=") && (comp.operator === ">=" || comp.operator === "<=");
      var oppositeDirectionsLessThan = cmp(this.semver, "<", comp.semver, options) && ((this.operator === ">=" || this.operator === ">") && (comp.operator === "<=" || comp.operator === "<"));
      var oppositeDirectionsGreaterThan = cmp(this.semver, ">", comp.semver, options) && ((this.operator === "<=" || this.operator === "<") && (comp.operator === ">=" || comp.operator === ">"));
      return sameDirectionIncreasing || sameDirectionDecreasing || sameSemVer && differentDirectionsInclusive || oppositeDirectionsLessThan || oppositeDirectionsGreaterThan;
    };
    exports2.Range = Range;
    function Range(range, options) {
      if (!options || typeof options !== "object") {
        options = {
          loose: !!options,
          includePrerelease: false
        };
      }
      if (range instanceof Range) {
        if (range.loose === !!options.loose && range.includePrerelease === !!options.includePrerelease) {
          return range;
        } else {
          return new Range(range.raw, options);
        }
      }
      if (range instanceof Comparator) {
        return new Range(range.value, options);
      }
      if (!(this instanceof Range)) {
        return new Range(range, options);
      }
      this.options = options;
      this.loose = !!options.loose;
      this.includePrerelease = !!options.includePrerelease;
      this.raw = range.trim().split(/\s+/).join(" ");
      this.set = this.raw.split("||").map(function(range2) {
        return this.parseRange(range2.trim());
      }, this).filter(function(c) {
        return c.length;
      });
      if (!this.set.length) {
        throw new TypeError("Invalid SemVer Range: " + this.raw);
      }
      this.format();
    }
    Range.prototype.format = function() {
      this.range = this.set.map(function(comps) {
        return comps.join(" ").trim();
      }).join("||").trim();
      return this.range;
    };
    Range.prototype.toString = function() {
      return this.range;
    };
    Range.prototype.parseRange = function(range) {
      var loose = this.options.loose;
      var hr = loose ? safeRe[t.HYPHENRANGELOOSE] : safeRe[t.HYPHENRANGE];
      range = range.replace(hr, hyphenReplace);
      debug("hyphen replace", range);
      range = range.replace(safeRe[t.COMPARATORTRIM], comparatorTrimReplace);
      debug("comparator trim", range, safeRe[t.COMPARATORTRIM]);
      range = range.replace(safeRe[t.TILDETRIM], tildeTrimReplace);
      range = range.replace(safeRe[t.CARETTRIM], caretTrimReplace);
      range = range.split(/\s+/).join(" ");
      var compRe = loose ? safeRe[t.COMPARATORLOOSE] : safeRe[t.COMPARATOR];
      var set = range.split(" ").map(function(comp) {
        return parseComparator(comp, this.options);
      }, this).join(" ").split(/\s+/);
      if (this.options.loose) {
        set = set.filter(function(comp) {
          return !!comp.match(compRe);
        });
      }
      set = set.map(function(comp) {
        return new Comparator(comp, this.options);
      }, this);
      return set;
    };
    Range.prototype.intersects = function(range, options) {
      if (!(range instanceof Range)) {
        throw new TypeError("a Range is required");
      }
      return this.set.some(function(thisComparators) {
        return isSatisfiable(thisComparators, options) && range.set.some(function(rangeComparators) {
          return isSatisfiable(rangeComparators, options) && thisComparators.every(function(thisComparator) {
            return rangeComparators.every(function(rangeComparator) {
              return thisComparator.intersects(rangeComparator, options);
            });
          });
        });
      });
    };
    function isSatisfiable(comparators, options) {
      var result = true;
      var remainingComparators = comparators.slice();
      var testComparator = remainingComparators.pop();
      while (result && remainingComparators.length) {
        result = remainingComparators.every(function(otherComparator) {
          return testComparator.intersects(otherComparator, options);
        });
        testComparator = remainingComparators.pop();
      }
      return result;
    }
    exports2.toComparators = toComparators;
    function toComparators(range, options) {
      return new Range(range, options).set.map(function(comp) {
        return comp.map(function(c) {
          return c.value;
        }).join(" ").trim().split(" ");
      });
    }
    function parseComparator(comp, options) {
      debug("comp", comp, options);
      comp = replaceCarets(comp, options);
      debug("caret", comp);
      comp = replaceTildes(comp, options);
      debug("tildes", comp);
      comp = replaceXRanges(comp, options);
      debug("xrange", comp);
      comp = replaceStars(comp, options);
      debug("stars", comp);
      return comp;
    }
    function isX(id) {
      return !id || id.toLowerCase() === "x" || id === "*";
    }
    function replaceTildes(comp, options) {
      return comp.trim().split(/\s+/).map(function(comp2) {
        return replaceTilde(comp2, options);
      }).join(" ");
    }
    function replaceTilde(comp, options) {
      var r = options.loose ? safeRe[t.TILDELOOSE] : safeRe[t.TILDE];
      return comp.replace(r, function(_, M, m, p, pr) {
        debug("tilde", comp, _, M, m, p, pr);
        var ret;
        if (isX(M)) {
          ret = "";
        } else if (isX(m)) {
          ret = ">=" + M + ".0.0 <" + (+M + 1) + ".0.0";
        } else if (isX(p)) {
          ret = ">=" + M + "." + m + ".0 <" + M + "." + (+m + 1) + ".0";
        } else if (pr) {
          debug("replaceTilde pr", pr);
          ret = ">=" + M + "." + m + "." + p + "-" + pr + " <" + M + "." + (+m + 1) + ".0";
        } else {
          ret = ">=" + M + "." + m + "." + p + " <" + M + "." + (+m + 1) + ".0";
        }
        debug("tilde return", ret);
        return ret;
      });
    }
    function replaceCarets(comp, options) {
      return comp.trim().split(/\s+/).map(function(comp2) {
        return replaceCaret(comp2, options);
      }).join(" ");
    }
    function replaceCaret(comp, options) {
      debug("caret", comp, options);
      var r = options.loose ? safeRe[t.CARETLOOSE] : safeRe[t.CARET];
      return comp.replace(r, function(_, M, m, p, pr) {
        debug("caret", comp, _, M, m, p, pr);
        var ret;
        if (isX(M)) {
          ret = "";
        } else if (isX(m)) {
          ret = ">=" + M + ".0.0 <" + (+M + 1) + ".0.0";
        } else if (isX(p)) {
          if (M === "0") {
            ret = ">=" + M + "." + m + ".0 <" + M + "." + (+m + 1) + ".0";
          } else {
            ret = ">=" + M + "." + m + ".0 <" + (+M + 1) + ".0.0";
          }
        } else if (pr) {
          debug("replaceCaret pr", pr);
          if (M === "0") {
            if (m === "0") {
              ret = ">=" + M + "." + m + "." + p + "-" + pr + " <" + M + "." + m + "." + (+p + 1);
            } else {
              ret = ">=" + M + "." + m + "." + p + "-" + pr + " <" + M + "." + (+m + 1) + ".0";
            }
          } else {
            ret = ">=" + M + "." + m + "." + p + "-" + pr + " <" + (+M + 1) + ".0.0";
          }
        } else {
          debug("no pr");
          if (M === "0") {
            if (m === "0") {
              ret = ">=" + M + "." + m + "." + p + " <" + M + "." + m + "." + (+p + 1);
            } else {
              ret = ">=" + M + "." + m + "." + p + " <" + M + "." + (+m + 1) + ".0";
            }
          } else {
            ret = ">=" + M + "." + m + "." + p + " <" + (+M + 1) + ".0.0";
          }
        }
        debug("caret return", ret);
        return ret;
      });
    }
    function replaceXRanges(comp, options) {
      debug("replaceXRanges", comp, options);
      return comp.split(/\s+/).map(function(comp2) {
        return replaceXRange(comp2, options);
      }).join(" ");
    }
    function replaceXRange(comp, options) {
      comp = comp.trim();
      var r = options.loose ? safeRe[t.XRANGELOOSE] : safeRe[t.XRANGE];
      return comp.replace(r, function(ret, gtlt, M, m, p, pr) {
        debug("xRange", comp, ret, gtlt, M, m, p, pr);
        var xM = isX(M);
        var xm = xM || isX(m);
        var xp = xm || isX(p);
        var anyX = xp;
        if (gtlt === "=" && anyX) {
          gtlt = "";
        }
        pr = options.includePrerelease ? "-0" : "";
        if (xM) {
          if (gtlt === ">" || gtlt === "<") {
            ret = "<0.0.0-0";
          } else {
            ret = "*";
          }
        } else if (gtlt && anyX) {
          if (xm) {
            m = 0;
          }
          p = 0;
          if (gtlt === ">") {
            gtlt = ">=";
            if (xm) {
              M = +M + 1;
              m = 0;
              p = 0;
            } else {
              m = +m + 1;
              p = 0;
            }
          } else if (gtlt === "<=") {
            gtlt = "<";
            if (xm) {
              M = +M + 1;
            } else {
              m = +m + 1;
            }
          }
          ret = gtlt + M + "." + m + "." + p + pr;
        } else if (xm) {
          ret = ">=" + M + ".0.0" + pr + " <" + (+M + 1) + ".0.0" + pr;
        } else if (xp) {
          ret = ">=" + M + "." + m + ".0" + pr + " <" + M + "." + (+m + 1) + ".0" + pr;
        }
        debug("xRange return", ret);
        return ret;
      });
    }
    function replaceStars(comp, options) {
      debug("replaceStars", comp, options);
      return comp.trim().replace(safeRe[t.STAR], "");
    }
    function hyphenReplace($0, from, fM, fm, fp, fpr, fb, to, tM, tm, tp, tpr, tb) {
      if (isX(fM)) {
        from = "";
      } else if (isX(fm)) {
        from = ">=" + fM + ".0.0";
      } else if (isX(fp)) {
        from = ">=" + fM + "." + fm + ".0";
      } else {
        from = ">=" + from;
      }
      if (isX(tM)) {
        to = "";
      } else if (isX(tm)) {
        to = "<" + (+tM + 1) + ".0.0";
      } else if (isX(tp)) {
        to = "<" + tM + "." + (+tm + 1) + ".0";
      } else if (tpr) {
        to = "<=" + tM + "." + tm + "." + tp + "-" + tpr;
      } else {
        to = "<=" + to;
      }
      return (from + " " + to).trim();
    }
    Range.prototype.test = function(version) {
      if (!version) {
        return false;
      }
      if (typeof version === "string") {
        try {
          version = new SemVer(version, this.options);
        } catch (er) {
          return false;
        }
      }
      for (var i2 = 0; i2 < this.set.length; i2++) {
        if (testSet(this.set[i2], version, this.options)) {
          return true;
        }
      }
      return false;
    };
    function testSet(set, version, options) {
      for (var i2 = 0; i2 < set.length; i2++) {
        if (!set[i2].test(version)) {
          return false;
        }
      }
      if (version.prerelease.length && !options.includePrerelease) {
        for (i2 = 0; i2 < set.length; i2++) {
          debug(set[i2].semver);
          if (set[i2].semver === ANY) {
            continue;
          }
          if (set[i2].semver.prerelease.length > 0) {
            var allowed = set[i2].semver;
            if (allowed.major === version.major && allowed.minor === version.minor && allowed.patch === version.patch) {
              return true;
            }
          }
        }
        return false;
      }
      return true;
    }
    exports2.satisfies = satisfies;
    function satisfies(version, range, options) {
      try {
        range = new Range(range, options);
      } catch (er) {
        return false;
      }
      return range.test(version);
    }
    exports2.maxSatisfying = maxSatisfying;
    function maxSatisfying(versions, range, options) {
      var max = null;
      var maxSV = null;
      try {
        var rangeObj = new Range(range, options);
      } catch (er) {
        return null;
      }
      versions.forEach(function(v) {
        if (rangeObj.test(v)) {
          if (!max || maxSV.compare(v) === -1) {
            max = v;
            maxSV = new SemVer(max, options);
          }
        }
      });
      return max;
    }
    exports2.minSatisfying = minSatisfying;
    function minSatisfying(versions, range, options) {
      var min = null;
      var minSV = null;
      try {
        var rangeObj = new Range(range, options);
      } catch (er) {
        return null;
      }
      versions.forEach(function(v) {
        if (rangeObj.test(v)) {
          if (!min || minSV.compare(v) === 1) {
            min = v;
            minSV = new SemVer(min, options);
          }
        }
      });
      return min;
    }
    exports2.minVersion = minVersion;
    function minVersion(range, loose) {
      range = new Range(range, loose);
      var minver = new SemVer("0.0.0");
      if (range.test(minver)) {
        return minver;
      }
      minver = new SemVer("0.0.0-0");
      if (range.test(minver)) {
        return minver;
      }
      minver = null;
      for (var i2 = 0; i2 < range.set.length; ++i2) {
        var comparators = range.set[i2];
        comparators.forEach(function(comparator) {
          var compver = new SemVer(comparator.semver.version);
          switch (comparator.operator) {
            case ">":
              if (compver.prerelease.length === 0) {
                compver.patch++;
              } else {
                compver.prerelease.push(0);
              }
              compver.raw = compver.format();
            /* fallthrough */
            case "":
            case ">=":
              if (!minver || gt(minver, compver)) {
                minver = compver;
              }
              break;
            case "<":
            case "<=":
              break;
            /* istanbul ignore next */
            default:
              throw new Error("Unexpected operation: " + comparator.operator);
          }
        });
      }
      if (minver && range.test(minver)) {
        return minver;
      }
      return null;
    }
    exports2.validRange = validRange;
    function validRange(range, options) {
      try {
        return new Range(range, options).range || "*";
      } catch (er) {
        return null;
      }
    }
    exports2.ltr = ltr;
    function ltr(version, range, options) {
      return outside(version, range, "<", options);
    }
    exports2.gtr = gtr;
    function gtr(version, range, options) {
      return outside(version, range, ">", options);
    }
    exports2.outside = outside;
    function outside(version, range, hilo, options) {
      version = new SemVer(version, options);
      range = new Range(range, options);
      var gtfn, ltefn, ltfn, comp, ecomp;
      switch (hilo) {
        case ">":
          gtfn = gt;
          ltefn = lte;
          ltfn = lt;
          comp = ">";
          ecomp = ">=";
          break;
        case "<":
          gtfn = lt;
          ltefn = gte;
          ltfn = gt;
          comp = "<";
          ecomp = "<=";
          break;
        default:
          throw new TypeError('Must provide a hilo val of "<" or ">"');
      }
      if (satisfies(version, range, options)) {
        return false;
      }
      for (var i2 = 0; i2 < range.set.length; ++i2) {
        var comparators = range.set[i2];
        var high = null;
        var low = null;
        comparators.forEach(function(comparator) {
          if (comparator.semver === ANY) {
            comparator = new Comparator(">=0.0.0");
          }
          high = high || comparator;
          low = low || comparator;
          if (gtfn(comparator.semver, high.semver, options)) {
            high = comparator;
          } else if (ltfn(comparator.semver, low.semver, options)) {
            low = comparator;
          }
        });
        if (high.operator === comp || high.operator === ecomp) {
          return false;
        }
        if ((!low.operator || low.operator === comp) && ltefn(version, low.semver)) {
          return false;
        } else if (low.operator === ecomp && ltfn(version, low.semver)) {
          return false;
        }
      }
      return true;
    }
    exports2.prerelease = prerelease;
    function prerelease(version, options) {
      var parsed = parse(version, options);
      return parsed && parsed.prerelease.length ? parsed.prerelease : null;
    }
    exports2.intersects = intersects;
    function intersects(r1, r2, options) {
      r1 = new Range(r1, options);
      r2 = new Range(r2, options);
      return r1.intersects(r2);
    }
    exports2.coerce = coerce;
    function coerce(version, options) {
      if (version instanceof SemVer) {
        return version;
      }
      if (typeof version === "number") {
        version = String(version);
      }
      if (typeof version !== "string") {
        return null;
      }
      options = options || {};
      var match = null;
      if (!options.rtl) {
        match = version.match(safeRe[t.COERCE]);
      } else {
        var next;
        while ((next = safeRe[t.COERCERTL].exec(version)) && (!match || match.index + match[0].length !== version.length)) {
          if (!match || next.index + next[0].length !== match.index + match[0].length) {
            match = next;
          }
          safeRe[t.COERCERTL].lastIndex = next.index + next[1].length + next[2].length;
        }
        safeRe[t.COERCERTL].lastIndex = -1;
      }
      if (match === null) {
        return null;
      }
      return parse(match[2] + "." + (match[3] || "0") + "." + (match[4] || "0"), options);
    }
  }
});

// node_modules/@babel/helper-member-expression-to-functions/lib/index.js
var require_lib3 = __commonJS({
  "node_modules/@babel/helper-member-expression-to-functions/lib/index.js"(exports2) {
    "use strict";
    Object.defineProperty(exports2, "__esModule", { value: true });
    var _t = require("@babel/types");
    function _interopNamespace(e) {
      if (e && e.__esModule) return e;
      var n = /* @__PURE__ */ Object.create(null);
      if (e) {
        Object.keys(e).forEach(function(k) {
          if (k !== "default") {
            var d = Object.getOwnPropertyDescriptor(e, k);
            Object.defineProperty(n, k, d.get ? d : {
              enumerable: true,
              get: function() {
                return e[k];
              }
            });
          }
        });
      }
      n.default = e;
      return Object.freeze(n);
    }
    var _t__namespace = /* @__PURE__ */ _interopNamespace(_t);
    function willPathCastToBoolean(path) {
      const maybeWrapped = path;
      const {
        node,
        parentPath
      } = maybeWrapped;
      if (parentPath.isLogicalExpression()) {
        const {
          operator,
          right
        } = parentPath.node;
        if (operator === "&&" || operator === "||" || operator === "??" && node === right) {
          return willPathCastToBoolean(parentPath);
        }
      }
      if (parentPath.isSequenceExpression()) {
        const {
          expressions
        } = parentPath.node;
        if (expressions[expressions.length - 1] === node) {
          return willPathCastToBoolean(parentPath);
        } else {
          return true;
        }
      }
      return parentPath.isConditional({
        test: node
      }) || parentPath.isUnaryExpression({
        operator: "!"
      }) || parentPath.isForStatement({
        test: node
      }) || parentPath.isWhile({
        test: node
      });
    }
    var {
      LOGICAL_OPERATORS,
      arrowFunctionExpression,
      assignmentExpression,
      binaryExpression,
      booleanLiteral,
      callExpression,
      cloneNode,
      conditionalExpression,
      identifier,
      isMemberExpression,
      isOptionalCallExpression,
      isOptionalMemberExpression,
      isUpdateExpression,
      logicalExpression,
      memberExpression,
      nullLiteral,
      optionalCallExpression,
      optionalMemberExpression,
      sequenceExpression,
      updateExpression
    } = _t__namespace;
    var AssignmentMemoiser = class {
      constructor() {
        this._map = void 0;
        this._map = /* @__PURE__ */ new WeakMap();
      }
      has(key) {
        return this._map.has(key);
      }
      get(key) {
        if (!this.has(key)) return;
        const record = this._map.get(key);
        const {
          value
        } = record;
        record.count--;
        if (record.count === 0) {
          return assignmentExpression("=", value, key);
        }
        return value;
      }
      set(key, value, count) {
        return this._map.set(key, {
          count,
          value
        });
      }
    };
    function toNonOptional(path, base) {
      const {
        node
      } = path;
      if (isOptionalMemberExpression(node)) {
        return memberExpression(base, node.property, node.computed);
      }
      if (path.isOptionalCallExpression()) {
        const callee = path.get("callee");
        if (path.node.optional && callee.isOptionalMemberExpression()) {
          const object = callee.node.object;
          const context = path.scope.maybeGenerateMemoised(object);
          callee.get("object").replaceWith(assignmentExpression("=", context, object));
          return callExpression(memberExpression(base, identifier("call")), [context, ...path.node.arguments]);
        }
        return callExpression(base, path.node.arguments);
      }
      return path.node;
    }
    function isInDetachedTree(path) {
      while (path) {
        if (path.isProgram()) break;
        const {
          parentPath,
          container,
          listKey
        } = path;
        const parentNode = parentPath.node;
        if (listKey) {
          if (container !== parentNode[listKey]) {
            return true;
          }
        } else {
          if (container !== parentNode) return true;
        }
        path = parentPath;
      }
      return false;
    }
    var handle = {
      memoise() {
      },
      handle(member, noDocumentAll) {
        const {
          node,
          parent,
          parentPath,
          scope
        } = member;
        if (member.isOptionalMemberExpression()) {
          if (isInDetachedTree(member)) return;
          const endPath = member.find(({
            node: node2,
            parent: parent2
          }) => {
            if (isOptionalMemberExpression(parent2)) {
              return parent2.optional || parent2.object !== node2;
            }
            if (isOptionalCallExpression(parent2)) {
              return node2 !== member.node && parent2.optional || parent2.callee !== node2;
            }
            return true;
          });
          if (scope.path.isPattern()) {
            endPath.replaceWith(callExpression(arrowFunctionExpression([], endPath.node), []));
            return;
          }
          const willEndPathCastToBoolean = willPathCastToBoolean(endPath);
          const rootParentPath = endPath.parentPath;
          if (rootParentPath.isUpdateExpression({
            argument: node
          })) {
            throw member.buildCodeFrameError(`can't handle update expression`);
          }
          const isAssignment = rootParentPath.isAssignmentExpression({
            left: endPath.node
          });
          const isDeleteOperation = rootParentPath.isUnaryExpression({
            operator: "delete"
          });
          if (isDeleteOperation && endPath.isOptionalMemberExpression() && endPath.get("property").isPrivateName()) {
            throw member.buildCodeFrameError(`can't delete a private class element`);
          }
          let startingOptional = member;
          for (; ; ) {
            if (startingOptional.isOptionalMemberExpression()) {
              if (startingOptional.node.optional) break;
              startingOptional = startingOptional.get("object");
              continue;
            } else if (startingOptional.isOptionalCallExpression()) {
              if (startingOptional.node.optional) break;
              startingOptional = startingOptional.get("callee");
              continue;
            }
            throw new Error(`Internal error: unexpected ${startingOptional.node.type}`);
          }
          const startingNode = startingOptional.isOptionalMemberExpression() ? startingOptional.node.object : startingOptional.node.callee;
          const baseNeedsMemoised = scope.maybeGenerateMemoised(startingNode);
          const baseRef = baseNeedsMemoised != null ? baseNeedsMemoised : startingNode;
          const parentIsOptionalCall = parentPath.isOptionalCallExpression({
            callee: node
          });
          const isOptionalCall = (parent2) => parentIsOptionalCall;
          const parentIsCall = parentPath.isCallExpression({
            callee: node
          });
          startingOptional.replaceWith(toNonOptional(startingOptional, baseRef));
          if (isOptionalCall()) {
            if (parent.optional) {
              parentPath.replaceWith(this.optionalCall(member, parent.arguments));
            } else {
              parentPath.replaceWith(this.call(member, parent.arguments));
            }
          } else if (parentIsCall) {
            member.replaceWith(this.boundGet(member));
          } else if (this.delete && parentPath.isUnaryExpression({
            operator: "delete"
          })) {
            parentPath.replaceWith(this.delete(member));
          } else if (parentPath.isAssignmentExpression()) {
            handleAssignment(this, member, parentPath);
          } else {
            member.replaceWith(this.get(member));
          }
          let regular = member.node;
          for (let current = member; current !== endPath; ) {
            const parentPath2 = current.parentPath;
            if (parentPath2 === endPath && isOptionalCall() && parent.optional) {
              regular = parentPath2.node;
              break;
            }
            regular = toNonOptional(parentPath2, regular);
            current = parentPath2;
          }
          let context;
          const endParentPath = endPath.parentPath;
          if (isMemberExpression(regular) && endParentPath.isOptionalCallExpression({
            callee: endPath.node,
            optional: true
          })) {
            const {
              object
            } = regular;
            context = member.scope.maybeGenerateMemoised(object);
            if (context) {
              regular.object = assignmentExpression("=", context, object);
            }
          }
          let replacementPath = endPath;
          if (isDeleteOperation || isAssignment) {
            replacementPath = endParentPath;
            regular = endParentPath.node;
          }
          const baseMemoised = baseNeedsMemoised ? assignmentExpression("=", cloneNode(baseRef), cloneNode(startingNode)) : cloneNode(baseRef);
          if (willEndPathCastToBoolean) {
            let nonNullishCheck;
            if (noDocumentAll) {
              nonNullishCheck = binaryExpression("!=", baseMemoised, nullLiteral());
            } else {
              nonNullishCheck = logicalExpression("&&", binaryExpression("!==", baseMemoised, nullLiteral()), binaryExpression("!==", cloneNode(baseRef), scope.buildUndefinedNode()));
            }
            replacementPath.replaceWith(logicalExpression("&&", nonNullishCheck, regular));
          } else {
            let nullishCheck;
            if (noDocumentAll) {
              nullishCheck = binaryExpression("==", baseMemoised, nullLiteral());
            } else {
              nullishCheck = logicalExpression("||", binaryExpression("===", baseMemoised, nullLiteral()), binaryExpression("===", cloneNode(baseRef), scope.buildUndefinedNode()));
            }
            replacementPath.replaceWith(conditionalExpression(nullishCheck, isDeleteOperation ? booleanLiteral(true) : scope.buildUndefinedNode(), regular));
          }
          if (context) {
            const endParent = endParentPath.node;
            endParentPath.replaceWith(optionalCallExpression(optionalMemberExpression(endParent.callee, identifier("call"), false, true), [cloneNode(context), ...endParent.arguments], false));
          }
          return;
        }
        if (isUpdateExpression(parent, {
          argument: node
        })) {
          if (this.simpleSet) {
            member.replaceWith(this.simpleSet(member));
            return;
          }
          const {
            operator,
            prefix
          } = parent;
          this.memoise(member, 2);
          const ref = scope.generateUidIdentifierBasedOnNode(node);
          scope.push({
            id: ref
          });
          const seq = [assignmentExpression("=", cloneNode(ref), this.get(member))];
          if (prefix) {
            seq.push(updateExpression(operator, cloneNode(ref), prefix));
            const value = sequenceExpression(seq);
            parentPath.replaceWith(this.set(member, value));
            return;
          } else {
            const ref2 = scope.generateUidIdentifierBasedOnNode(node);
            scope.push({
              id: ref2
            });
            seq.push(assignmentExpression("=", cloneNode(ref2), updateExpression(operator, cloneNode(ref), prefix)), cloneNode(ref));
            const value = sequenceExpression(seq);
            parentPath.replaceWith(sequenceExpression([this.set(member, value), cloneNode(ref2)]));
            return;
          }
        }
        if (parentPath.isAssignmentExpression({
          left: node
        })) {
          handleAssignment(this, member, parentPath);
          return;
        }
        if (parentPath.isCallExpression({
          callee: node
        })) {
          parentPath.replaceWith(this.call(member, parentPath.node.arguments));
          return;
        }
        if (parentPath.isOptionalCallExpression({
          callee: node
        })) {
          if (scope.path.isPattern()) {
            parentPath.replaceWith(callExpression(arrowFunctionExpression([], parentPath.node), []));
            return;
          }
          parentPath.replaceWith(this.optionalCall(member, parentPath.node.arguments));
          return;
        }
        if (this.delete && parentPath.isUnaryExpression({
          operator: "delete"
        })) {
          parentPath.replaceWith(this.delete(member));
          return;
        }
        if (parentPath.isForXStatement({
          left: node
        }) || parentPath.isObjectProperty({
          value: node
        }) && parentPath.parentPath.isObjectPattern() || parentPath.isAssignmentPattern({
          left: node
        }) && parentPath.parentPath.isObjectProperty({
          value: parent
        }) && parentPath.parentPath.parentPath.isObjectPattern() || parentPath.isArrayPattern() || parentPath.isAssignmentPattern({
          left: node
        }) && parentPath.parentPath.isArrayPattern() || parentPath.isRestElement()) {
          member.replaceWith(this.destructureSet(member));
          return;
        }
        if (parentPath.isTaggedTemplateExpression()) {
          member.replaceWith(this.boundGet(member));
        } else {
          member.replaceWith(this.get(member));
        }
      }
    };
    function handleAssignment(state, member, parentPath) {
      if (state.simpleSet) {
        member.replaceWith(state.simpleSet(member));
        return;
      }
      const {
        operator,
        right: value
      } = parentPath.node;
      if (operator === "=") {
        parentPath.replaceWith(state.set(member, value));
      } else {
        const operatorTrunc = operator.slice(0, -1);
        if (LOGICAL_OPERATORS.includes(operatorTrunc)) {
          state.memoise(member, 1);
          parentPath.replaceWith(logicalExpression(operatorTrunc, state.get(member), state.set(member, value)));
        } else {
          state.memoise(member, 2);
          parentPath.replaceWith(state.set(member, binaryExpression(operatorTrunc, state.get(member), value)));
        }
      }
    }
    function memberExpressionToFunctions(path, visitor, state) {
      path.traverse(visitor, Object.assign({}, handle, state, {
        memoiser: new AssignmentMemoiser()
      }));
    }
    exports2.default = memberExpressionToFunctions;
  }
});

// node_modules/@babel/helper-optimise-call-expression/lib/index.js
var require_lib4 = __commonJS({
  "node_modules/@babel/helper-optimise-call-expression/lib/index.js"(exports2) {
    "use strict";
    Object.defineProperty(exports2, "__esModule", {
      value: true
    });
    exports2.default = optimiseCallExpression;
    var _t = require("@babel/types");
    var {
      callExpression,
      identifier,
      isIdentifier,
      isSpreadElement,
      memberExpression,
      optionalCallExpression,
      optionalMemberExpression
    } = _t;
    function optimiseCallExpression(callee, thisNode, args, optional) {
      if (args.length === 1 && isSpreadElement(args[0]) && isIdentifier(args[0].argument, {
        name: "arguments"
      })) {
        if (optional) {
          return optionalCallExpression(optionalMemberExpression(callee, identifier("apply"), false, true), [thisNode, args[0].argument], false);
        }
        return callExpression(memberExpression(callee, identifier("apply")), [thisNode, args[0].argument]);
      } else {
        if (optional) {
          return optionalCallExpression(optionalMemberExpression(callee, identifier("call"), false, true), [thisNode, ...args], false);
        }
        return callExpression(memberExpression(callee, identifier("call")), [thisNode, ...args]);
      }
    }
  }
});

// node_modules/@babel/helper-replace-supers/lib/index.js
var require_lib5 = __commonJS({
  "node_modules/@babel/helper-replace-supers/lib/index.js"(exports2) {
    "use strict";
    Object.defineProperty(exports2, "__esModule", {
      value: true
    });
    exports2.default = void 0;
    var _helperMemberExpressionToFunctions = require_lib3();
    var _helperOptimiseCallExpression = require_lib4();
    var _core = require("@babel/core");
    var _traverse = require("@babel/traverse");
    var {
      assignmentExpression,
      callExpression,
      cloneNode,
      identifier,
      memberExpression,
      sequenceExpression,
      stringLiteral,
      thisExpression
    } = _core.types;
    exports2.environmentVisitor = _traverse.visitors.environmentVisitor({});
    exports2.skipAllButComputedKey = function skipAllButComputedKey(path) {
      path.skip();
      if (path.node.computed) {
        path.context.maybeQueue(path.get("key"));
      }
    };
    var visitor = _traverse.visitors.environmentVisitor({
      Super(path, state) {
        const {
          node,
          parentPath
        } = path;
        if (!parentPath.isMemberExpression({
          object: node
        })) return;
        state.handle(parentPath);
      }
    });
    var unshadowSuperBindingVisitor = _traverse.visitors.environmentVisitor({
      Scopable(path, {
        refName
      }) {
        const binding = path.scope.getOwnBinding(refName);
        if ((binding == null ? void 0 : binding.identifier.name) === refName) {
          path.scope.rename(refName);
        }
      }
    });
    var specHandlers = {
      memoise(superMember, count) {
        const {
          scope,
          node
        } = superMember;
        const {
          computed,
          property
        } = node;
        if (!computed) {
          return;
        }
        const memo = scope.maybeGenerateMemoised(property);
        if (!memo) {
          return;
        }
        this.memoiser.set(property, memo, count);
      },
      prop(superMember) {
        const {
          computed,
          property
        } = superMember.node;
        if (this.memoiser.has(property)) {
          return cloneNode(this.memoiser.get(property));
        }
        if (computed) {
          return cloneNode(property);
        }
        return stringLiteral(property.name);
      },
      _getPrototypeOfExpression() {
        const objectRef = cloneNode(this.getObjectRef());
        const targetRef = this.isStatic || this.isPrivateMethod ? objectRef : memberExpression(objectRef, identifier("prototype"));
        return callExpression(this.file.addHelper("getPrototypeOf"), [targetRef]);
      },
      get(superMember) {
        const objectRef = cloneNode(this.getObjectRef());
        return callExpression(this.file.addHelper("superPropGet"), [this.isDerivedConstructor ? sequenceExpression([thisExpression(), objectRef]) : objectRef, this.prop(superMember), thisExpression(), ...this.isStatic || this.isPrivateMethod ? [] : [_core.types.numericLiteral(1)]]);
      },
      _call(superMember, args, optional) {
        const objectRef = cloneNode(this.getObjectRef());
        let argsNode;
        if (args.length === 1 && _core.types.isSpreadElement(args[0]) && (_core.types.isIdentifier(args[0].argument) || _core.types.isArrayExpression(args[0].argument))) {
          argsNode = args[0].argument;
        } else {
          argsNode = _core.types.arrayExpression(args);
        }
        const call = _core.types.callExpression(this.file.addHelper("superPropGet"), [this.isDerivedConstructor ? sequenceExpression([thisExpression(), objectRef]) : objectRef, this.prop(superMember), thisExpression(), _core.types.numericLiteral(2 | (this.isStatic || this.isPrivateMethod ? 0 : 1))]);
        if (optional) {
          return _core.types.optionalCallExpression(call, [argsNode], true);
        }
        return callExpression(call, [argsNode]);
      },
      set(superMember, value) {
        const objectRef = cloneNode(this.getObjectRef());
        return callExpression(this.file.addHelper("superPropSet"), [this.isDerivedConstructor ? sequenceExpression([thisExpression(), objectRef]) : objectRef, this.prop(superMember), value, thisExpression(), _core.types.numericLiteral(superMember.isInStrictMode() ? 1 : 0), ...this.isStatic || this.isPrivateMethod ? [] : [_core.types.numericLiteral(1)]]);
      },
      destructureSet(superMember) {
        throw superMember.buildCodeFrameError(`Destructuring to a super field is not supported yet.`);
      },
      call(superMember, args) {
        return this._call(superMember, args, false);
      },
      optionalCall(superMember, args) {
        return this._call(superMember, args, true);
      },
      delete(superMember) {
        if (superMember.node.computed) {
          return sequenceExpression([callExpression(this.file.addHelper("toPropertyKey"), [cloneNode(superMember.node.property)]), _core.template.expression.ast`
          function () { throw new ReferenceError("'delete super[expr]' is invalid"); }()
        `]);
        } else {
          return _core.template.expression.ast`
        function () { throw new ReferenceError("'delete super.prop' is invalid"); }()
      `;
        }
      }
    };
    var specHandlers_old = {
      memoise(superMember, count) {
        const {
          scope,
          node
        } = superMember;
        const {
          computed,
          property
        } = node;
        if (!computed) {
          return;
        }
        const memo = scope.maybeGenerateMemoised(property);
        if (!memo) {
          return;
        }
        this.memoiser.set(property, memo, count);
      },
      prop(superMember) {
        const {
          computed,
          property
        } = superMember.node;
        if (this.memoiser.has(property)) {
          return cloneNode(this.memoiser.get(property));
        }
        if (computed) {
          return cloneNode(property);
        }
        return stringLiteral(property.name);
      },
      _getPrototypeOfExpression() {
        const objectRef = cloneNode(this.getObjectRef());
        const targetRef = this.isStatic || this.isPrivateMethod ? objectRef : memberExpression(objectRef, identifier("prototype"));
        return callExpression(this.file.addHelper("getPrototypeOf"), [targetRef]);
      },
      get(superMember) {
        return this._get(superMember);
      },
      _get(superMember) {
        const proto = this._getPrototypeOfExpression();
        return callExpression(this.file.addHelper("get"), [this.isDerivedConstructor ? sequenceExpression([thisExpression(), proto]) : proto, this.prop(superMember), thisExpression()]);
      },
      set(superMember, value) {
        const proto = this._getPrototypeOfExpression();
        return callExpression(this.file.addHelper("set"), [this.isDerivedConstructor ? sequenceExpression([thisExpression(), proto]) : proto, this.prop(superMember), value, thisExpression(), _core.types.booleanLiteral(superMember.isInStrictMode())]);
      },
      destructureSet(superMember) {
        throw superMember.buildCodeFrameError(`Destructuring to a super field is not supported yet.`);
      },
      call(superMember, args) {
        return (0, _helperOptimiseCallExpression.default)(this._get(superMember), thisExpression(), args, false);
      },
      optionalCall(superMember, args) {
        return (0, _helperOptimiseCallExpression.default)(this._get(superMember), cloneNode(thisExpression()), args, true);
      },
      delete(superMember) {
        if (superMember.node.computed) {
          return sequenceExpression([callExpression(this.file.addHelper("toPropertyKey"), [cloneNode(superMember.node.property)]), _core.template.expression.ast`
          function () { throw new ReferenceError("'delete super[expr]' is invalid"); }()
        `]);
        } else {
          return _core.template.expression.ast`
        function () { throw new ReferenceError("'delete super.prop' is invalid"); }()
      `;
        }
      }
    };
    var looseHandlers = Object.assign({}, specHandlers, {
      prop(superMember) {
        const {
          property
        } = superMember.node;
        if (this.memoiser.has(property)) {
          return cloneNode(this.memoiser.get(property));
        }
        return cloneNode(property);
      },
      get(superMember) {
        const {
          isStatic,
          getSuperRef
        } = this;
        const {
          computed
        } = superMember.node;
        const prop = this.prop(superMember);
        let object;
        if (isStatic) {
          var _getSuperRef;
          object = (_getSuperRef = getSuperRef()) != null ? _getSuperRef : memberExpression(identifier("Function"), identifier("prototype"));
        } else {
          var _getSuperRef2;
          object = memberExpression((_getSuperRef2 = getSuperRef()) != null ? _getSuperRef2 : identifier("Object"), identifier("prototype"));
        }
        return memberExpression(object, prop, computed);
      },
      set(superMember, value) {
        const {
          computed
        } = superMember.node;
        const prop = this.prop(superMember);
        return assignmentExpression("=", memberExpression(thisExpression(), prop, computed), value);
      },
      destructureSet(superMember) {
        const {
          computed
        } = superMember.node;
        const prop = this.prop(superMember);
        return memberExpression(thisExpression(), prop, computed);
      },
      call(superMember, args) {
        return (0, _helperOptimiseCallExpression.default)(this.get(superMember), thisExpression(), args, false);
      },
      optionalCall(superMember, args) {
        return (0, _helperOptimiseCallExpression.default)(this.get(superMember), thisExpression(), args, true);
      }
    });
    var ReplaceSupers = class {
      constructor(opts) {
        var _opts$constantSuper;
        const path = opts.methodPath;
        this.methodPath = path;
        this.isDerivedConstructor = path.isClassMethod({
          kind: "constructor"
        }) && !!opts.superRef;
        this.isStatic = path.isObjectMethod() || path.node.static || (path.isStaticBlock == null ? void 0 : path.isStaticBlock());
        this.isPrivateMethod = path.isPrivate() && path.isMethod();
        this.file = opts.file;
        this.constantSuper = (_opts$constantSuper = opts.constantSuper) != null ? _opts$constantSuper : opts.isLoose;
        this.opts = opts;
      }
      getObjectRef() {
        return cloneNode(this.opts.objectRef || this.opts.getObjectRef());
      }
      getSuperRef() {
        if (this.opts.superRef) return cloneNode(this.opts.superRef);
        if (this.opts.getSuperRef) {
          return cloneNode(this.opts.getSuperRef());
        }
      }
      replace() {
        const {
          methodPath
        } = this;
        if (this.opts.refToPreserve) {
          methodPath.traverse(unshadowSuperBindingVisitor, {
            refName: this.opts.refToPreserve.name
          });
        }
        const handler = this.constantSuper ? looseHandlers : this.file.availableHelper("superPropSet") ? specHandlers : specHandlers_old;
        visitor.shouldSkip = (path) => {
          if (path.parentPath === methodPath) {
            if (path.parentKey === "decorators" || path.parentKey === "key") {
              return true;
            }
          }
        };
        (0, _helperMemberExpressionToFunctions.default)(methodPath, visitor, Object.assign({
          file: this.file,
          scope: this.methodPath.scope,
          isDerivedConstructor: this.isDerivedConstructor,
          isStatic: this.isStatic,
          isPrivateMethod: this.isPrivateMethod,
          getObjectRef: this.getObjectRef.bind(this),
          getSuperRef: this.getSuperRef.bind(this),
          boundGet: handler.get
        }, handler));
      }
    };
    exports2.default = ReplaceSupers;
  }
});

// node_modules/@babel/helper-annotate-as-pure/lib/index.js
var require_lib6 = __commonJS({
  "node_modules/@babel/helper-annotate-as-pure/lib/index.js"(exports2) {
    "use strict";
    Object.defineProperty(exports2, "__esModule", {
      value: true
    });
    exports2.default = annotateAsPure;
    var _t = require("@babel/types");
    var {
      addComment
    } = _t;
    var PURE_ANNOTATION = "#__PURE__";
    var isPureAnnotated = ({
      leadingComments
    }) => !!leadingComments && leadingComments.some((comment) => /[@#]__PURE__/.test(comment.value));
    function annotateAsPure(pathOrNode) {
      const node = pathOrNode.node || pathOrNode;
      if (isPureAnnotated(node)) {
        return;
      }
      addComment(node, "leading", PURE_ANNOTATION);
    }
  }
});

// node_modules/@babel/helper-skip-transparent-expression-wrappers/lib/index.js
var require_lib7 = __commonJS({
  "node_modules/@babel/helper-skip-transparent-expression-wrappers/lib/index.js"(exports2) {
    "use strict";
    Object.defineProperty(exports2, "__esModule", {
      value: true
    });
    exports2.isTransparentExprWrapper = isTransparentExprWrapper;
    exports2.skipTransparentExprWrapperNodes = skipTransparentExprWrapperNodes;
    exports2.skipTransparentExprWrappers = skipTransparentExprWrappers;
    var _t = require("@babel/types");
    var {
      isParenthesizedExpression,
      isTSAsExpression,
      isTSNonNullExpression,
      isTSSatisfiesExpression,
      isTSTypeAssertion,
      isTypeCastExpression
    } = _t;
    function isTransparentExprWrapper(node) {
      return isTSAsExpression(node) || isTSSatisfiesExpression(node) || isTSTypeAssertion(node) || isTSNonNullExpression(node) || isTypeCastExpression(node) || isParenthesizedExpression(node);
    }
    function skipTransparentExprWrappers(path) {
      while (isTransparentExprWrapper(path.node)) {
        path = path.get("expression");
      }
      return path;
    }
    function skipTransparentExprWrapperNodes(node) {
      while (isTransparentExprWrapper(node)) {
        node = node.expression;
      }
      return node;
    }
  }
});

// node_modules/@babel/helper-create-class-features-plugin/lib/typescript.js
var require_typescript = __commonJS({
  "node_modules/@babel/helper-create-class-features-plugin/lib/typescript.js"(exports2) {
    "use strict";
    Object.defineProperty(exports2, "__esModule", {
      value: true
    });
    exports2.assertFieldTransformed = assertFieldTransformed;
    function assertFieldTransformed(path) {
      if (path.node.declare || false) {
        throw path.buildCodeFrameError(`TypeScript 'declare' fields must first be transformed by @babel/plugin-transform-typescript.
If you have already enabled that plugin (or '@babel/preset-typescript'), make sure that it runs before any plugin related to additional class features:
 - @babel/plugin-transform-class-properties
 - @babel/plugin-transform-private-methods
 - @babel/plugin-proposal-decorators`);
      }
    }
  }
});

// node_modules/@babel/helper-create-class-features-plugin/lib/fields.js
var require_fields = __commonJS({
  "node_modules/@babel/helper-create-class-features-plugin/lib/fields.js"(exports2) {
    "use strict";
    Object.defineProperty(exports2, "__esModule", {
      value: true
    });
    exports2.buildCheckInRHS = buildCheckInRHS;
    exports2.buildFieldsInitNodes = buildFieldsInitNodes;
    exports2.buildPrivateNamesMap = buildPrivateNamesMap;
    exports2.buildPrivateNamesNodes = buildPrivateNamesNodes;
    exports2.privateNameVisitorFactory = privateNameVisitorFactory;
    exports2.transformPrivateNamesUsage = transformPrivateNamesUsage;
    var _core = require("@babel/core");
    var _traverse = require("@babel/traverse");
    var _helperReplaceSupers = require_lib5();
    var _helperMemberExpressionToFunctions = require_lib3();
    var _helperOptimiseCallExpression = require_lib4();
    var _helperAnnotateAsPure = require_lib6();
    var _helperSkipTransparentExpressionWrappers = require_lib7();
    var ts = require_typescript();
    var newHelpers = (file) => {
      return file.availableHelper("classPrivateFieldGet2");
    };
    function buildPrivateNamesMap(className, privateFieldsAsSymbolsOrProperties, props, file) {
      const privateNamesMap = /* @__PURE__ */ new Map();
      let classBrandId;
      for (const prop of props) {
        if (prop.isPrivate()) {
          const {
            name
          } = prop.node.key.id;
          let update = privateNamesMap.get(name);
          if (!update) {
            const isMethod = !prop.isProperty();
            const isStatic = prop.node.static;
            let initAdded = false;
            let id;
            if (!privateFieldsAsSymbolsOrProperties && newHelpers(file) && isMethod && !isStatic) {
              initAdded = !!classBrandId;
              classBrandId != null ? classBrandId : classBrandId = prop.scope.generateUidIdentifier(`${className}_brand`);
              id = classBrandId;
            } else {
              id = prop.scope.generateUidIdentifier(name);
            }
            update = {
              id,
              static: isStatic,
              method: isMethod,
              initAdded
            };
            privateNamesMap.set(name, update);
          }
          if (prop.isClassPrivateMethod()) {
            if (prop.node.kind === "get") {
              const {
                body
              } = prop.node.body;
              let $;
              if (body.length === 1 && _core.types.isReturnStatement($ = body[0]) && _core.types.isCallExpression($ = $.argument) && $.arguments.length === 1 && _core.types.isThisExpression($.arguments[0]) && _core.types.isIdentifier($ = $.callee)) {
                update.getId = _core.types.cloneNode($);
                update.getterDeclared = true;
              } else {
                update.getId = prop.scope.generateUidIdentifier(`get_${name}`);
              }
            } else if (prop.node.kind === "set") {
              const {
                params
              } = prop.node;
              const {
                body
              } = prop.node.body;
              let $;
              if (body.length === 1 && _core.types.isExpressionStatement($ = body[0]) && _core.types.isCallExpression($ = $.expression) && $.arguments.length === 2 && _core.types.isThisExpression($.arguments[0]) && _core.types.isIdentifier($.arguments[1], {
                name: params[0].name
              }) && _core.types.isIdentifier($ = $.callee)) {
                update.setId = _core.types.cloneNode($);
                update.setterDeclared = true;
              } else {
                update.setId = prop.scope.generateUidIdentifier(`set_${name}`);
              }
            } else if (prop.node.kind === "method") {
              update.methodId = prop.scope.generateUidIdentifier(name);
            }
          }
          privateNamesMap.set(name, update);
        }
      }
      return privateNamesMap;
    }
    function buildPrivateNamesNodes(privateNamesMap, privateFieldsAsProperties, privateFieldsAsSymbols, state) {
      const initNodes = [];
      const injectedIds = /* @__PURE__ */ new Set();
      for (const [name, value] of privateNamesMap) {
        const {
          static: isStatic,
          method: isMethod,
          getId,
          setId
        } = value;
        const isGetterOrSetter = getId || setId;
        const id = _core.types.cloneNode(value.id);
        let init;
        if (privateFieldsAsProperties) {
          init = _core.types.callExpression(state.addHelper("classPrivateFieldLooseKey"), [_core.types.stringLiteral(name)]);
        } else if (privateFieldsAsSymbols) {
          init = _core.types.callExpression(_core.types.identifier("Symbol"), [_core.types.stringLiteral(name)]);
        } else if (!isStatic) {
          if (injectedIds.has(id.name)) continue;
          injectedIds.add(id.name);
          init = _core.types.newExpression(_core.types.identifier(isMethod && (!isGetterOrSetter || newHelpers(state)) ? "WeakSet" : "WeakMap"), []);
        }
        if (init) {
          if (!privateFieldsAsSymbols) {
            (0, _helperAnnotateAsPure.default)(init);
          }
          initNodes.push(_core.template.statement.ast`var ${id} = ${init}`);
        }
      }
      return initNodes;
    }
    function privateNameVisitorFactory(visitor) {
      const nestedVisitor = _traverse.visitors.environmentVisitor(Object.assign({}, visitor));
      const privateNameVisitor2 = Object.assign({}, visitor, {
        Class(path) {
          const {
            privateNamesMap
          } = this;
          const body = path.get("body.body");
          const visiblePrivateNames = new Map(privateNamesMap);
          const redeclared = [];
          for (const prop of body) {
            if (!prop.isPrivate()) continue;
            const {
              name
            } = prop.node.key.id;
            visiblePrivateNames.delete(name);
            redeclared.push(name);
          }
          if (!redeclared.length) {
            return;
          }
          path.get("body").traverse(nestedVisitor, Object.assign({}, this, {
            redeclared
          }));
          path.traverse(privateNameVisitor2, Object.assign({}, this, {
            privateNamesMap: visiblePrivateNames
          }));
          path.skipKey("body");
        }
      });
      return privateNameVisitor2;
    }
    var privateNameVisitor = privateNameVisitorFactory({
      PrivateName(path, {
        noDocumentAll
      }) {
        const {
          privateNamesMap,
          redeclared
        } = this;
        const {
          node,
          parentPath
        } = path;
        if (!parentPath.isMemberExpression({
          property: node
        }) && !parentPath.isOptionalMemberExpression({
          property: node
        })) {
          return;
        }
        const {
          name
        } = node.id;
        if (!privateNamesMap.has(name)) return;
        if (redeclared != null && redeclared.includes(name)) return;
        this.handle(parentPath, noDocumentAll);
      }
    });
    function unshadow(name, scope, innerBinding) {
      while ((_scope = scope) != null && _scope.hasBinding(name) && !scope.bindingIdentifierEquals(name, innerBinding)) {
        var _scope;
        scope.rename(name);
        scope = scope.parent;
      }
    }
    function buildCheckInRHS(rhs, file, inRHSIsObject) {
      if (inRHSIsObject || !(file.availableHelper != null && file.availableHelper("checkInRHS"))) return rhs;
      return _core.types.callExpression(file.addHelper("checkInRHS"), [rhs]);
    }
    var privateInVisitor = privateNameVisitorFactory({
      BinaryExpression(path, {
        file
      }) {
        const {
          operator,
          left,
          right
        } = path.node;
        if (operator !== "in") return;
        if (!_core.types.isPrivateName(left)) return;
        const {
          privateFieldsAsProperties,
          privateNamesMap,
          redeclared
        } = this;
        const {
          name
        } = left.id;
        if (!privateNamesMap.has(name)) return;
        if (redeclared != null && redeclared.includes(name)) return;
        unshadow(this.classRef.name, path.scope, this.innerBinding);
        if (privateFieldsAsProperties) {
          const {
            id: id2
          } = privateNamesMap.get(name);
          path.replaceWith(_core.template.expression.ast`
        Object.prototype.hasOwnProperty.call(${buildCheckInRHS(right, file)}, ${_core.types.cloneNode(id2)})
      `);
          return;
        }
        const {
          id,
          static: isStatic
        } = privateNamesMap.get(name);
        if (isStatic) {
          path.replaceWith(_core.template.expression.ast`${buildCheckInRHS(right, file)} === ${_core.types.cloneNode(this.classRef)}`);
          return;
        }
        path.replaceWith(_core.template.expression.ast`${_core.types.cloneNode(id)}.has(${buildCheckInRHS(right, file)})`);
      }
    });
    function readOnlyError(file, name) {
      return _core.types.callExpression(file.addHelper("readOnlyError"), [_core.types.stringLiteral(`#${name}`)]);
    }
    function writeOnlyError(file, name) {
      if (!file.availableHelper("writeOnlyError")) {
        console.warn(`@babel/helpers is outdated, update it to silence this warning.`);
        return _core.types.buildUndefinedNode();
      }
      return _core.types.callExpression(file.addHelper("writeOnlyError"), [_core.types.stringLiteral(`#${name}`)]);
    }
    function buildStaticPrivateFieldAccess(expr, noUninitializedPrivateFieldAccess) {
      if (noUninitializedPrivateFieldAccess) return expr;
      return _core.types.memberExpression(expr, _core.types.identifier("_"));
    }
    function autoInherits(fn) {
      return function(member) {
        return _core.types.inherits(fn.apply(this, arguments), member.node);
      };
    }
    var privateNameHandlerSpec = {
      memoise(member, count) {
        const {
          scope
        } = member;
        const {
          object
        } = member.node;
        const memo = scope.maybeGenerateMemoised(object);
        if (!memo) {
          return;
        }
        this.memoiser.set(object, memo, count);
      },
      receiver(member) {
        const {
          object
        } = member.node;
        if (this.memoiser.has(object)) {
          return _core.types.cloneNode(this.memoiser.get(object));
        }
        return _core.types.cloneNode(object);
      },
      get: autoInherits(function(member) {
        const {
          classRef,
          privateNamesMap,
          file,
          innerBinding,
          noUninitializedPrivateFieldAccess
        } = this;
        const privateName = member.node.property;
        const {
          name
        } = privateName.id;
        const {
          id,
          static: isStatic,
          method: isMethod,
          methodId,
          getId,
          setId
        } = privateNamesMap.get(name);
        const isGetterOrSetter = getId || setId;
        const cloneId = (id2) => _core.types.inherits(_core.types.cloneNode(id2), privateName);
        if (isStatic) {
          unshadow(classRef.name, member.scope, innerBinding);
          if (!newHelpers(file)) {
            const helperName = isMethod && !isGetterOrSetter ? "classStaticPrivateMethodGet" : "classStaticPrivateFieldSpecGet";
            return _core.types.callExpression(file.addHelper(helperName), [this.receiver(member), _core.types.cloneNode(classRef), cloneId(id)]);
          }
          const receiver = this.receiver(member);
          const skipCheck = _core.types.isIdentifier(receiver) && receiver.name === classRef.name;
          if (!isMethod) {
            if (skipCheck) {
              return buildStaticPrivateFieldAccess(cloneId(id), noUninitializedPrivateFieldAccess);
            }
            return buildStaticPrivateFieldAccess(_core.types.callExpression(file.addHelper("assertClassBrand"), [_core.types.cloneNode(classRef), receiver, cloneId(id)]), noUninitializedPrivateFieldAccess);
          }
          if (getId) {
            if (skipCheck) {
              return _core.types.callExpression(cloneId(getId), [receiver]);
            }
            return _core.types.callExpression(file.addHelper("classPrivateGetter"), [_core.types.cloneNode(classRef), receiver, cloneId(getId)]);
          }
          if (setId) {
            const err = _core.types.buildUndefinedNode();
            if (skipCheck) return err;
            return _core.types.sequenceExpression([_core.types.callExpression(file.addHelper("assertClassBrand"), [_core.types.cloneNode(classRef), receiver]), err]);
          }
          if (skipCheck) return cloneId(id);
          return _core.types.callExpression(file.addHelper("assertClassBrand"), [_core.types.cloneNode(classRef), receiver, cloneId(id)]);
        }
        if (isMethod) {
          if (isGetterOrSetter) {
            if (!getId) {
              return _core.types.sequenceExpression([this.receiver(member), writeOnlyError(file, name)]);
            }
            if (!newHelpers(file)) {
              return _core.types.callExpression(file.addHelper("classPrivateFieldGet"), [this.receiver(member), cloneId(id)]);
            }
            return _core.types.callExpression(file.addHelper("classPrivateGetter"), [_core.types.cloneNode(id), this.receiver(member), cloneId(getId)]);
          }
          if (!newHelpers(file)) {
            return _core.types.callExpression(file.addHelper("classPrivateMethodGet"), [this.receiver(member), _core.types.cloneNode(id), cloneId(methodId)]);
          }
          return _core.types.callExpression(file.addHelper("assertClassBrand"), [_core.types.cloneNode(id), this.receiver(member), cloneId(methodId)]);
        }
        if (newHelpers(file)) {
          return _core.types.callExpression(file.addHelper("classPrivateFieldGet2"), [cloneId(id), this.receiver(member)]);
        }
        return _core.types.callExpression(file.addHelper("classPrivateFieldGet"), [this.receiver(member), cloneId(id)]);
      }),
      boundGet(member) {
        this.memoise(member, 1);
        return _core.types.callExpression(_core.types.memberExpression(this.get(member), _core.types.identifier("bind")), [this.receiver(member)]);
      },
      set: autoInherits(function(member, value) {
        const {
          classRef,
          privateNamesMap,
          file,
          noUninitializedPrivateFieldAccess
        } = this;
        const privateName = member.node.property;
        const {
          name
        } = privateName.id;
        const {
          id,
          static: isStatic,
          method: isMethod,
          setId,
          getId
        } = privateNamesMap.get(name);
        const isGetterOrSetter = getId || setId;
        const cloneId = (id2) => _core.types.inherits(_core.types.cloneNode(id2), privateName);
        if (isStatic) {
          if (!newHelpers(file)) {
            const helperName = isMethod && !isGetterOrSetter ? "classStaticPrivateMethodSet" : "classStaticPrivateFieldSpecSet";
            return _core.types.callExpression(file.addHelper(helperName), [this.receiver(member), _core.types.cloneNode(classRef), cloneId(id), value]);
          }
          const receiver = this.receiver(member);
          const skipCheck = _core.types.isIdentifier(receiver) && receiver.name === classRef.name;
          if (isMethod && !setId) {
            const err = readOnlyError(file, name);
            if (skipCheck) return _core.types.sequenceExpression([value, err]);
            return _core.types.sequenceExpression([value, _core.types.callExpression(file.addHelper("assertClassBrand"), [_core.types.cloneNode(classRef), receiver]), readOnlyError(file, name)]);
          }
          if (setId) {
            if (skipCheck) {
              return _core.types.callExpression(_core.types.cloneNode(setId), [receiver, value]);
            }
            return _core.types.callExpression(file.addHelper("classPrivateSetter"), [_core.types.cloneNode(classRef), cloneId(setId), receiver, value]);
          }
          return _core.types.assignmentExpression("=", buildStaticPrivateFieldAccess(cloneId(id), noUninitializedPrivateFieldAccess), skipCheck ? value : _core.types.callExpression(file.addHelper("assertClassBrand"), [_core.types.cloneNode(classRef), receiver, value]));
        }
        if (isMethod) {
          if (setId) {
            if (!newHelpers(file)) {
              return _core.types.callExpression(file.addHelper("classPrivateFieldSet"), [this.receiver(member), cloneId(id), value]);
            }
            return _core.types.callExpression(file.addHelper("classPrivateSetter"), [_core.types.cloneNode(id), cloneId(setId), this.receiver(member), value]);
          }
          return _core.types.sequenceExpression([this.receiver(member), value, readOnlyError(file, name)]);
        }
        if (newHelpers(file)) {
          return _core.types.callExpression(file.addHelper("classPrivateFieldSet2"), [cloneId(id), this.receiver(member), value]);
        }
        return _core.types.callExpression(file.addHelper("classPrivateFieldSet"), [this.receiver(member), cloneId(id), value]);
      }),
      destructureSet(member) {
        const {
          classRef,
          privateNamesMap,
          file,
          noUninitializedPrivateFieldAccess
        } = this;
        const privateName = member.node.property;
        const {
          name
        } = privateName.id;
        const {
          id,
          static: isStatic,
          method: isMethod,
          setId
        } = privateNamesMap.get(name);
        const cloneId = (id2) => _core.types.inherits(_core.types.cloneNode(id2), privateName);
        if (!newHelpers(file)) {
          if (isStatic) {
            try {
              var helper = file.addHelper("classStaticPrivateFieldDestructureSet");
            } catch (_unused) {
              throw new Error("Babel can not transpile `[C.#p] = [0]` with @babel/helpers < 7.13.10, \nplease update @babel/helpers to the latest version.");
            }
            return _core.types.memberExpression(_core.types.callExpression(helper, [this.receiver(member), _core.types.cloneNode(classRef), cloneId(id)]), _core.types.identifier("value"));
          }
          return _core.types.memberExpression(_core.types.callExpression(file.addHelper("classPrivateFieldDestructureSet"), [this.receiver(member), cloneId(id)]), _core.types.identifier("value"));
        }
        if (isMethod && !setId) {
          return _core.types.memberExpression(_core.types.sequenceExpression([member.node.object, readOnlyError(file, name)]), _core.types.identifier("_"));
        }
        if (isStatic && !isMethod) {
          const getCall = this.get(member);
          if (!noUninitializedPrivateFieldAccess || !_core.types.isCallExpression(getCall)) {
            return getCall;
          }
          const ref = getCall.arguments.pop();
          getCall.arguments.push(_core.template.expression.ast`(_) => ${ref} = _`);
          return _core.types.memberExpression(_core.types.callExpression(file.addHelper("toSetter"), [getCall]), _core.types.identifier("_"));
        }
        const setCall = this.set(member, _core.types.identifier("_"));
        if (!_core.types.isCallExpression(setCall) || !_core.types.isIdentifier(setCall.arguments[setCall.arguments.length - 1], {
          name: "_"
        })) {
          throw member.buildCodeFrameError("Internal Babel error while compiling this code. This is a Babel bug. Please report it at https://github.com/babel/babel/issues.");
        }
        let args;
        if (_core.types.isMemberExpression(setCall.callee, {
          computed: false
        }) && _core.types.isIdentifier(setCall.callee.property) && setCall.callee.property.name === "call") {
          args = [setCall.callee.object, _core.types.arrayExpression(setCall.arguments.slice(1, -1)), setCall.arguments[0]];
        } else {
          args = [setCall.callee, _core.types.arrayExpression(setCall.arguments.slice(0, -1))];
        }
        return _core.types.memberExpression(_core.types.callExpression(file.addHelper("toSetter"), args), _core.types.identifier("_"));
      },
      call(member, args) {
        this.memoise(member, 1);
        return (0, _helperOptimiseCallExpression.default)(this.get(member), this.receiver(member), args, false);
      },
      optionalCall(member, args) {
        this.memoise(member, 1);
        return (0, _helperOptimiseCallExpression.default)(this.get(member), this.receiver(member), args, true);
      },
      delete() {
        throw new Error("Internal Babel error: deleting private elements is a parsing error.");
      }
    };
    var privateNameHandlerLoose = {
      get(member) {
        const {
          privateNamesMap,
          file
        } = this;
        const {
          object
        } = member.node;
        const {
          name
        } = member.node.property.id;
        return _core.template.expression`BASE(REF, PROP)[PROP]`({
          BASE: file.addHelper("classPrivateFieldLooseBase"),
          REF: _core.types.cloneNode(object),
          PROP: _core.types.cloneNode(privateNamesMap.get(name).id)
        });
      },
      set() {
        throw new Error("private name handler with loose = true don't need set()");
      },
      boundGet(member) {
        return _core.types.callExpression(_core.types.memberExpression(this.get(member), _core.types.identifier("bind")), [_core.types.cloneNode(member.node.object)]);
      },
      simpleSet(member) {
        return this.get(member);
      },
      destructureSet(member) {
        return this.get(member);
      },
      call(member, args) {
        return _core.types.callExpression(this.get(member), args);
      },
      optionalCall(member, args) {
        return _core.types.optionalCallExpression(this.get(member), args, true);
      },
      delete() {
        throw new Error("Internal Babel error: deleting private elements is a parsing error.");
      }
    };
    function transformPrivateNamesUsage(ref, path, privateNamesMap, {
      privateFieldsAsProperties,
      noUninitializedPrivateFieldAccess,
      noDocumentAll,
      innerBinding
    }, state) {
      if (!privateNamesMap.size) return;
      const body = path.get("body");
      const handler = privateFieldsAsProperties ? privateNameHandlerLoose : privateNameHandlerSpec;
      (0, _helperMemberExpressionToFunctions.default)(body, privateNameVisitor, Object.assign({
        privateNamesMap,
        classRef: ref,
        file: state
      }, handler, {
        noDocumentAll,
        noUninitializedPrivateFieldAccess,
        innerBinding
      }));
      body.traverse(privateInVisitor, {
        privateNamesMap,
        classRef: ref,
        file: state,
        privateFieldsAsProperties,
        innerBinding
      });
    }
    function buildPrivateFieldInitLoose(ref, prop, privateNamesMap) {
      const {
        id
      } = privateNamesMap.get(prop.node.key.id.name);
      const value = prop.node.value || prop.scope.buildUndefinedNode();
      return inheritPropComments(_core.template.statement.ast`
      Object.defineProperty(${ref}, ${_core.types.cloneNode(id)}, {
        // configurable is false by default
        // enumerable is false by default
        writable: true,
        value: ${value}
      });
    `, prop);
    }
    function buildPrivateInstanceFieldInitSpec(ref, prop, privateNamesMap, state) {
      const {
        id
      } = privateNamesMap.get(prop.node.key.id.name);
      const value = prop.node.value || prop.scope.buildUndefinedNode();
      if (!state.availableHelper("classPrivateFieldInitSpec")) {
        return inheritPropComments(_core.template.statement.ast`${_core.types.cloneNode(id)}.set(${ref}, {
          // configurable is always false for private elements
          // enumerable is always false for private elements
          writable: true,
          value: ${value},
        })`, prop);
      }
      const helper = state.addHelper("classPrivateFieldInitSpec");
      return inheritLoc(inheritPropComments(_core.types.expressionStatement(_core.types.callExpression(helper, [_core.types.thisExpression(), inheritLoc(_core.types.cloneNode(id), prop.node.key), newHelpers(state) ? value : _core.template.expression.ast`{ writable: true, value: ${value} }`])), prop), prop.node);
    }
    function buildPrivateStaticFieldInitSpec(prop, privateNamesMap, noUninitializedPrivateFieldAccess) {
      const privateName = privateNamesMap.get(prop.node.key.id.name);
      const value = noUninitializedPrivateFieldAccess ? prop.node.value : _core.template.expression.ast`{
        _: ${prop.node.value || _core.types.buildUndefinedNode()}
      }`;
      return inheritPropComments(_core.types.variableDeclaration("var", [_core.types.variableDeclarator(_core.types.cloneNode(privateName.id), value)]), prop);
    }
    var buildPrivateStaticFieldInitSpecOld = function(prop, privateNamesMap) {
      const privateName = privateNamesMap.get(prop.node.key.id.name);
      const {
        id,
        getId,
        setId,
        initAdded
      } = privateName;
      const isGetterOrSetter = getId || setId;
      if (!prop.isProperty() && (initAdded || !isGetterOrSetter)) return;
      if (isGetterOrSetter) {
        privateNamesMap.set(prop.node.key.id.name, Object.assign({}, privateName, {
          initAdded: true
        }));
        return inheritPropComments(_core.template.statement.ast`
          var ${_core.types.cloneNode(id)} = {
            // configurable is false by default
            // enumerable is false by default
            // writable is false by default
            get: ${getId ? getId.name : prop.scope.buildUndefinedNode()},
            set: ${setId ? setId.name : prop.scope.buildUndefinedNode()}
          }
        `, prop);
      }
      const value = prop.node.value || prop.scope.buildUndefinedNode();
      return inheritPropComments(_core.template.statement.ast`
        var ${_core.types.cloneNode(id)} = {
          // configurable is false by default
          // enumerable is false by default
          writable: true,
          value: ${value}
        };
      `, prop);
    };
    function buildPrivateMethodInitLoose(ref, prop, privateNamesMap) {
      const privateName = privateNamesMap.get(prop.node.key.id.name);
      const {
        methodId,
        id,
        getId,
        setId,
        initAdded
      } = privateName;
      if (initAdded) return;
      if (methodId) {
        return inheritPropComments(_core.template.statement.ast`
        Object.defineProperty(${ref}, ${id}, {
          // configurable is false by default
          // enumerable is false by default
          // writable is false by default
          value: ${methodId.name}
        });
      `, prop);
      }
      const isGetterOrSetter = getId || setId;
      if (isGetterOrSetter) {
        privateNamesMap.set(prop.node.key.id.name, Object.assign({}, privateName, {
          initAdded: true
        }));
        return inheritPropComments(_core.template.statement.ast`
        Object.defineProperty(${ref}, ${id}, {
          // configurable is false by default
          // enumerable is false by default
          // writable is false by default
          get: ${getId ? getId.name : prop.scope.buildUndefinedNode()},
          set: ${setId ? setId.name : prop.scope.buildUndefinedNode()}
        });
      `, prop);
      }
    }
    function buildPrivateInstanceMethodInitSpec(ref, prop, privateNamesMap, state) {
      const privateName = privateNamesMap.get(prop.node.key.id.name);
      if (privateName.initAdded) return;
      if (!newHelpers(state)) {
        const isGetterOrSetter = privateName.getId || privateName.setId;
        if (isGetterOrSetter) {
          return buildPrivateAccessorInitialization(ref, prop, privateNamesMap, state);
        }
      }
      return buildPrivateInstanceMethodInitialization(ref, prop, privateNamesMap, state);
    }
    function buildPrivateAccessorInitialization(ref, prop, privateNamesMap, state) {
      const privateName = privateNamesMap.get(prop.node.key.id.name);
      const {
        id,
        getId,
        setId
      } = privateName;
      privateNamesMap.set(prop.node.key.id.name, Object.assign({}, privateName, {
        initAdded: true
      }));
      if (!state.availableHelper("classPrivateFieldInitSpec")) {
        return inheritPropComments(_core.template.statement.ast`
          ${id}.set(${ref}, {
            get: ${getId ? getId.name : prop.scope.buildUndefinedNode()},
            set: ${setId ? setId.name : prop.scope.buildUndefinedNode()}
          });
        `, prop);
      }
      const helper = state.addHelper("classPrivateFieldInitSpec");
      return inheritLoc(inheritPropComments(_core.template.statement.ast`${helper}(
      ${_core.types.thisExpression()},
      ${_core.types.cloneNode(id)},
      {
        get: ${getId ? getId.name : prop.scope.buildUndefinedNode()},
        set: ${setId ? setId.name : prop.scope.buildUndefinedNode()}
      },
    )`, prop), prop.node);
    }
    function buildPrivateInstanceMethodInitialization(ref, prop, privateNamesMap, state) {
      const privateName = privateNamesMap.get(prop.node.key.id.name);
      const {
        id
      } = privateName;
      if (!state.availableHelper("classPrivateMethodInitSpec")) {
        return inheritPropComments(_core.template.statement.ast`${id}.add(${ref})`, prop);
      }
      const helper = state.addHelper("classPrivateMethodInitSpec");
      return inheritPropComments(_core.template.statement.ast`${helper}(
      ${_core.types.thisExpression()},
      ${_core.types.cloneNode(id)}
    )`, prop);
    }
    function buildPublicFieldInitLoose(ref, prop) {
      const {
        key,
        computed
      } = prop.node;
      const value = prop.node.value || prop.scope.buildUndefinedNode();
      return inheritPropComments(_core.types.expressionStatement(_core.types.assignmentExpression("=", _core.types.memberExpression(ref, key, computed || _core.types.isLiteral(key)), value)), prop);
    }
    function buildPublicFieldInitSpec(ref, prop, state) {
      const {
        key,
        computed
      } = prop.node;
      const value = prop.node.value || prop.scope.buildUndefinedNode();
      return inheritPropComments(_core.types.expressionStatement(_core.types.callExpression(state.addHelper("defineProperty"), [ref, computed || _core.types.isLiteral(key) ? key : _core.types.stringLiteral(key.name), value])), prop);
    }
    function buildPrivateStaticMethodInitLoose(ref, prop, state, privateNamesMap) {
      const privateName = privateNamesMap.get(prop.node.key.id.name);
      const {
        id,
        methodId,
        getId,
        setId,
        initAdded
      } = privateName;
      if (initAdded) return;
      const isGetterOrSetter = getId || setId;
      if (isGetterOrSetter) {
        privateNamesMap.set(prop.node.key.id.name, Object.assign({}, privateName, {
          initAdded: true
        }));
        return inheritPropComments(_core.template.statement.ast`
        Object.defineProperty(${ref}, ${id}, {
          // configurable is false by default
          // enumerable is false by default
          // writable is false by default
          get: ${getId ? getId.name : prop.scope.buildUndefinedNode()},
          set: ${setId ? setId.name : prop.scope.buildUndefinedNode()}
        })
      `, prop);
      }
      return inheritPropComments(_core.template.statement.ast`
      Object.defineProperty(${ref}, ${id}, {
        // configurable is false by default
        // enumerable is false by default
        // writable is false by default
        value: ${methodId.name}
      });
    `, prop);
    }
    function buildPrivateMethodDeclaration(file, prop, privateNamesMap, privateFieldsAsSymbolsOrProperties = false) {
      const privateName = privateNamesMap.get(prop.node.key.id.name);
      const {
        id,
        methodId,
        getId,
        setId,
        getterDeclared,
        setterDeclared,
        static: isStatic
      } = privateName;
      const {
        params,
        body,
        generator,
        async
      } = prop.node;
      const isGetter = getId && params.length === 0;
      const isSetter = setId && params.length > 0;
      if (isGetter && getterDeclared || isSetter && setterDeclared) {
        privateNamesMap.set(prop.node.key.id.name, Object.assign({}, privateName, {
          initAdded: true
        }));
        return null;
      }
      if (newHelpers(file) && (isGetter || isSetter) && !privateFieldsAsSymbolsOrProperties) {
        const scope = prop.get("body").scope;
        const thisArg = scope.generateUidIdentifier("this");
        const state = {
          thisRef: thisArg,
          argumentsPath: []
        };
        prop.traverse(thisContextVisitor, state);
        if (state.argumentsPath.length) {
          const argumentsId = scope.generateUidIdentifier("arguments");
          scope.push({
            id: argumentsId,
            init: _core.template.expression.ast`[].slice.call(arguments, 1)`
          });
          for (const path of state.argumentsPath) {
            path.replaceWith(_core.types.cloneNode(argumentsId));
          }
        }
        params.unshift(_core.types.cloneNode(thisArg));
      }
      let declId = methodId;
      if (isGetter) {
        privateNamesMap.set(prop.node.key.id.name, Object.assign({}, privateName, {
          getterDeclared: true,
          initAdded: true
        }));
        declId = getId;
      } else if (isSetter) {
        privateNamesMap.set(prop.node.key.id.name, Object.assign({}, privateName, {
          setterDeclared: true,
          initAdded: true
        }));
        declId = setId;
      } else if (isStatic && !privateFieldsAsSymbolsOrProperties) {
        declId = id;
      }
      return inheritPropComments(_core.types.functionDeclaration(_core.types.cloneNode(declId), params, body, generator, async), prop);
    }
    var thisContextVisitor = _traverse.visitors.environmentVisitor({
      Identifier(path, state) {
        if (state.argumentsPath && path.node.name === "arguments") {
          state.argumentsPath.push(path);
        }
      },
      UnaryExpression(path) {
        const {
          node
        } = path;
        if (node.operator === "delete") {
          const argument = (0, _helperSkipTransparentExpressionWrappers.skipTransparentExprWrapperNodes)(node.argument);
          if (_core.types.isThisExpression(argument)) {
            path.replaceWith(_core.types.booleanLiteral(true));
          }
        }
      },
      ThisExpression(path, state) {
        state.needsClassRef = true;
        path.replaceWith(_core.types.cloneNode(state.thisRef));
      },
      MetaProperty(path) {
        const {
          node,
          scope
        } = path;
        if (node.meta.name === "new" && node.property.name === "target") {
          path.replaceWith(scope.buildUndefinedNode());
        }
      }
    });
    var innerReferencesVisitor = {
      ReferencedIdentifier(path, state) {
        if (path.scope.bindingIdentifierEquals(path.node.name, state.innerBinding)) {
          state.needsClassRef = true;
          path.node.name = state.thisRef.name;
        }
      }
    };
    function replaceThisContext(path, ref, innerBindingRef) {
      var _state$thisRef;
      const state = {
        thisRef: ref,
        needsClassRef: false,
        innerBinding: innerBindingRef
      };
      if (!path.isMethod()) {
        path.traverse(thisContextVisitor, state);
      }
      if (innerBindingRef != null && (_state$thisRef = state.thisRef) != null && _state$thisRef.name && state.thisRef.name !== innerBindingRef.name) {
        path.traverse(innerReferencesVisitor, state);
      }
      return state.needsClassRef;
    }
    function isNameOrLength({
      key,
      computed
    }) {
      if (key.type === "Identifier") {
        return !computed && (key.name === "name" || key.name === "length");
      }
      if (key.type === "StringLiteral") {
        return key.value === "name" || key.value === "length";
      }
      return false;
    }
    function inheritPropComments(node, prop) {
      _core.types.inheritLeadingComments(node, prop.node);
      _core.types.inheritInnerComments(node, prop.node);
      return node;
    }
    function inheritLoc(node, original) {
      node.start = original.start;
      node.end = original.end;
      node.loc = original.loc;
      return node;
    }
    function buildFieldsInitNodes(ref, superRef, props, privateNamesMap, file, setPublicClassFields, privateFieldsAsSymbolsOrProperties, noUninitializedPrivateFieldAccess, constantSuper, innerBindingRef) {
      let classRefFlags = 0;
      let injectSuperRef;
      const staticNodes = [];
      const instanceNodes = [];
      let lastInstanceNodeReturnsThis = false;
      const pureStaticNodes = [];
      let classBindingNode = null;
      const getSuperRef = _core.types.isIdentifier(superRef) ? () => superRef : () => {
        injectSuperRef != null ? injectSuperRef : injectSuperRef = props[0].scope.generateUidIdentifierBasedOnNode(superRef);
        return injectSuperRef;
      };
      const classRefForInnerBinding = ref != null ? ref : props[0].scope.generateUidIdentifier((innerBindingRef == null ? void 0 : innerBindingRef.name) || "Class");
      ref != null ? ref : ref = _core.types.cloneNode(innerBindingRef);
      for (const prop of props) {
        if (prop.isClassProperty()) {
          ts.assertFieldTransformed(prop);
        }
        const isStatic = !(_core.types.isStaticBlock != null && _core.types.isStaticBlock(prop.node)) && prop.node.static;
        const isInstance = !isStatic;
        const isPrivate = prop.isPrivate();
        const isPublic = !isPrivate;
        const isField = prop.isProperty();
        const isMethod = !isField;
        const isStaticBlock = prop.isStaticBlock == null ? void 0 : prop.isStaticBlock();
        if (isStatic) classRefFlags |= 1;
        if (isStatic || isMethod && isPrivate || isStaticBlock) {
          new _helperReplaceSupers.default({
            methodPath: prop,
            constantSuper,
            file,
            refToPreserve: innerBindingRef,
            getSuperRef,
            getObjectRef() {
              classRefFlags |= 2;
              if (isStatic || isStaticBlock) {
                return classRefForInnerBinding;
              } else {
                return _core.types.memberExpression(classRefForInnerBinding, _core.types.identifier("prototype"));
              }
            }
          }).replace();
          const replaced = replaceThisContext(prop, classRefForInnerBinding, innerBindingRef);
          if (replaced) {
            classRefFlags |= 2;
          }
        }
        lastInstanceNodeReturnsThis = false;
        switch (true) {
          case isStaticBlock: {
            const blockBody = prop.node.body;
            if (blockBody.length === 1 && _core.types.isExpressionStatement(blockBody[0])) {
              staticNodes.push(inheritPropComments(blockBody[0], prop));
            } else {
              staticNodes.push(_core.types.inheritsComments(_core.template.statement.ast`(() => { ${blockBody} })()`, prop.node));
            }
            break;
          }
          case (isStatic && isPrivate && isField && privateFieldsAsSymbolsOrProperties):
            staticNodes.push(buildPrivateFieldInitLoose(_core.types.cloneNode(ref), prop, privateNamesMap));
            break;
          case (isStatic && isPrivate && isField && !privateFieldsAsSymbolsOrProperties):
            if (!newHelpers(file)) {
              staticNodes.push(buildPrivateStaticFieldInitSpecOld(prop, privateNamesMap));
            } else {
              staticNodes.push(buildPrivateStaticFieldInitSpec(prop, privateNamesMap, noUninitializedPrivateFieldAccess));
            }
            break;
          case (isStatic && isPublic && isField && setPublicClassFields):
            if (!isNameOrLength(prop.node)) {
              staticNodes.push(buildPublicFieldInitLoose(_core.types.cloneNode(ref), prop));
              break;
            }
          case (isStatic && isPublic && isField && !setPublicClassFields):
            staticNodes.push(buildPublicFieldInitSpec(_core.types.cloneNode(ref), prop, file));
            break;
          case (isInstance && isPrivate && isField && privateFieldsAsSymbolsOrProperties):
            instanceNodes.push(buildPrivateFieldInitLoose(_core.types.thisExpression(), prop, privateNamesMap));
            break;
          case (isInstance && isPrivate && isField && !privateFieldsAsSymbolsOrProperties):
            instanceNodes.push(buildPrivateInstanceFieldInitSpec(_core.types.thisExpression(), prop, privateNamesMap, file));
            break;
          case (isInstance && isPrivate && isMethod && privateFieldsAsSymbolsOrProperties):
            instanceNodes.unshift(buildPrivateMethodInitLoose(_core.types.thisExpression(), prop, privateNamesMap));
            pureStaticNodes.push(buildPrivateMethodDeclaration(file, prop, privateNamesMap, privateFieldsAsSymbolsOrProperties));
            break;
          case (isInstance && isPrivate && isMethod && !privateFieldsAsSymbolsOrProperties):
            instanceNodes.unshift(buildPrivateInstanceMethodInitSpec(_core.types.thisExpression(), prop, privateNamesMap, file));
            pureStaticNodes.push(buildPrivateMethodDeclaration(file, prop, privateNamesMap, privateFieldsAsSymbolsOrProperties));
            break;
          case (isStatic && isPrivate && isMethod && !privateFieldsAsSymbolsOrProperties):
            if (!newHelpers(file)) {
              staticNodes.unshift(buildPrivateStaticFieldInitSpecOld(prop, privateNamesMap));
            }
            pureStaticNodes.push(buildPrivateMethodDeclaration(file, prop, privateNamesMap, privateFieldsAsSymbolsOrProperties));
            break;
          case (isStatic && isPrivate && isMethod && privateFieldsAsSymbolsOrProperties):
            staticNodes.unshift(buildPrivateStaticMethodInitLoose(_core.types.cloneNode(ref), prop, file, privateNamesMap));
            pureStaticNodes.push(buildPrivateMethodDeclaration(file, prop, privateNamesMap, privateFieldsAsSymbolsOrProperties));
            break;
          case (isInstance && isPublic && isField && setPublicClassFields):
            instanceNodes.push(buildPublicFieldInitLoose(_core.types.thisExpression(), prop));
            break;
          case (isInstance && isPublic && isField && !setPublicClassFields):
            lastInstanceNodeReturnsThis = true;
            instanceNodes.push(buildPublicFieldInitSpec(_core.types.thisExpression(), prop, file));
            break;
          default:
            throw new Error("Unreachable.");
        }
      }
      if (classRefFlags & 2 && innerBindingRef != null) {
        classBindingNode = _core.types.expressionStatement(_core.types.assignmentExpression("=", _core.types.cloneNode(classRefForInnerBinding), _core.types.cloneNode(innerBindingRef)));
      }
      return {
        staticNodes: staticNodes.filter(Boolean),
        instanceNodes: instanceNodes.filter(Boolean),
        lastInstanceNodeReturnsThis,
        pureStaticNodes: pureStaticNodes.filter(Boolean),
        classBindingNode,
        wrapClass(path) {
          for (const prop of props) {
            prop.node.leadingComments = null;
            prop.remove();
          }
          if (injectSuperRef) {
            path.scope.push({
              id: _core.types.cloneNode(injectSuperRef)
            });
            path.set("superClass", _core.types.assignmentExpression("=", injectSuperRef, path.node.superClass));
          }
          if (classRefFlags !== 0) {
            if (path.isClassExpression()) {
              path.scope.push({
                id: ref
              });
              path.replaceWith(_core.types.assignmentExpression("=", _core.types.cloneNode(ref), path.node));
            } else {
              if (innerBindingRef == null) {
                path.node.id = ref;
              }
              if (classBindingNode != null) {
                path.scope.push({
                  id: classRefForInnerBinding
                });
              }
            }
          }
          return path;
        }
      };
    }
  }
});

// node_modules/@babel/helper-create-class-features-plugin/lib/misc.js
var require_misc = __commonJS({
  "node_modules/@babel/helper-create-class-features-plugin/lib/misc.js"(exports2) {
    "use strict";
    Object.defineProperty(exports2, "__esModule", {
      value: true
    });
    exports2.extractComputedKeys = extractComputedKeys;
    exports2.injectInitialization = injectInitialization;
    exports2.memoiseComputedKey = memoiseComputedKey;
    var _core = require("@babel/core");
    var _traverse = require("@babel/traverse");
    var findBareSupers = _traverse.visitors.environmentVisitor({
      Super(path) {
        const {
          node,
          parentPath
        } = path;
        if (parentPath.isCallExpression({
          callee: node
        })) {
          this.push(parentPath);
        }
      }
    });
    var referenceVisitor = {
      "TSTypeAnnotation|TypeAnnotation"(path) {
        path.skip();
      },
      ReferencedIdentifier(path, {
        scope
      }) {
        if (scope.hasOwnBinding(path.node.name)) {
          scope.rename(path.node.name);
          path.skip();
        }
      }
    };
    function handleClassTDZ(path, state) {
      if (state.classBinding && state.classBinding === path.scope.getBinding(path.node.name)) {
        const classNameTDZError = state.file.addHelper("classNameTDZError");
        const throwNode = _core.types.callExpression(classNameTDZError, [_core.types.stringLiteral(path.node.name)]);
        path.replaceWith(_core.types.sequenceExpression([throwNode, path.node]));
        path.skip();
      }
    }
    var classFieldDefinitionEvaluationTDZVisitor = {
      ReferencedIdentifier: handleClassTDZ,
      "TSTypeAnnotation|TypeAnnotation"(path) {
        path.skip();
      }
    };
    function injectInitialization(path, constructor, nodes, renamer, lastReturnsThis) {
      if (!nodes.length) return;
      const isDerived = !!path.node.superClass;
      if (!constructor) {
        const newConstructor = _core.types.classMethod("constructor", _core.types.identifier("constructor"), [], _core.types.blockStatement([]));
        if (isDerived) {
          newConstructor.params = [_core.types.restElement(_core.types.identifier("args"))];
          newConstructor.body.body.push(_core.template.statement.ast`super(...args)`);
        }
        [constructor] = path.get("body").unshiftContainer("body", newConstructor);
      }
      if (renamer) {
        renamer(referenceVisitor, {
          scope: constructor.scope
        });
      }
      if (isDerived) {
        const bareSupers = [];
        constructor.traverse(findBareSupers, bareSupers);
        let isFirst = true;
        for (const bareSuper of bareSupers) {
          if (isFirst) {
            isFirst = false;
          } else {
            nodes = nodes.map((n) => _core.types.cloneNode(n));
          }
          if (!bareSuper.parentPath.isExpressionStatement()) {
            const allNodes = [bareSuper.node, ...nodes.map((n) => _core.types.toExpression(n))];
            if (!lastReturnsThis) allNodes.push(_core.types.thisExpression());
            bareSuper.replaceWith(_core.types.sequenceExpression(allNodes));
          } else {
            bareSuper.insertAfter(nodes);
          }
        }
      } else {
        constructor.get("body").unshiftContainer("body", nodes);
      }
    }
    function memoiseComputedKey(keyNode, scope, hint) {
      const isUidReference = _core.types.isIdentifier(keyNode) && scope.hasUid(keyNode.name);
      if (isUidReference) {
        return;
      }
      const isMemoiseAssignment = _core.types.isAssignmentExpression(keyNode, {
        operator: "="
      }) && _core.types.isIdentifier(keyNode.left) && scope.hasUid(keyNode.left.name);
      if (isMemoiseAssignment) {
        return _core.types.cloneNode(keyNode);
      } else {
        const ident = _core.types.identifier(hint);
        scope.push({
          id: ident,
          kind: "let"
        });
        return _core.types.assignmentExpression("=", _core.types.cloneNode(ident), keyNode);
      }
    }
    function extractComputedKeys(path, computedPaths, file) {
      const {
        scope
      } = path;
      const declarations = [];
      const state = {
        classBinding: path.node.id && scope.getBinding(path.node.id.name),
        file
      };
      for (const computedPath of computedPaths) {
        const computedKey = computedPath.get("key");
        if (computedKey.isReferencedIdentifier()) {
          handleClassTDZ(computedKey, state);
        } else {
          computedKey.traverse(classFieldDefinitionEvaluationTDZVisitor, state);
        }
        const computedNode = computedPath.node;
        if (!computedKey.isConstantExpression()) {
          const assignment = memoiseComputedKey(computedKey.node, scope, scope.generateUidBasedOnNode(computedKey.node));
          if (assignment) {
            declarations.push(_core.types.expressionStatement(assignment));
            computedNode.key = _core.types.cloneNode(assignment.left);
          }
        }
      }
      return declarations;
    }
  }
});

// node_modules/@babel/helper-create-class-features-plugin/lib/decorators.js
var require_decorators = __commonJS({
  "node_modules/@babel/helper-create-class-features-plugin/lib/decorators.js"(exports2) {
    "use strict";
    Object.defineProperty(exports2, "__esModule", {
      value: true
    });
    exports2.buildNamedEvaluationVisitor = buildNamedEvaluationVisitor;
    exports2.default = _default;
    exports2.hasDecorators = hasDecorators;
    exports2.hasOwnDecorators = hasOwnDecorators;
    var _core = require("@babel/core");
    var _helperReplaceSupers = require_lib5();
    var _helperSkipTransparentExpressionWrappers = require_lib7();
    var _fields = require_fields();
    var _misc = require_misc();
    function hasOwnDecorators(node) {
      var _node$decorators;
      return !!((_node$decorators = node.decorators) != null && _node$decorators.length);
    }
    function hasDecorators(node) {
      return hasOwnDecorators(node) || node.body.body.some(hasOwnDecorators);
    }
    function incrementId(id, idx = id.length - 1) {
      if (idx === -1) {
        id.unshift(65);
        return;
      }
      const current = id[idx];
      if (current === 90) {
        id[idx] = 97;
      } else if (current === 122) {
        id[idx] = 65;
        incrementId(id, idx - 1);
      } else {
        id[idx] = current + 1;
      }
    }
    function createPrivateUidGeneratorForClass(classPath) {
      const currentPrivateId = [];
      const privateNames = /* @__PURE__ */ new Set();
      _core.types.traverseFast(classPath.node, (node) => {
        if (_core.types.isPrivateName(node)) {
          privateNames.add(node.id.name);
        }
      });
      return () => {
        let reifiedId;
        do {
          incrementId(currentPrivateId);
          reifiedId = String.fromCharCode(...currentPrivateId);
        } while (privateNames.has(reifiedId));
        return _core.types.privateName(_core.types.identifier(reifiedId));
      };
    }
    function createLazyPrivateUidGeneratorForClass(classPath) {
      let generator;
      return () => {
        if (!generator) {
          generator = createPrivateUidGeneratorForClass(classPath);
        }
        return generator();
      };
    }
    function replaceClassWithVar(path, className) {
      const id = path.node.id;
      const scope = path.scope;
      if (path.type === "ClassDeclaration") {
        const className2 = id.name;
        const varId = scope.generateUidIdentifierBasedOnNode(id);
        const classId = _core.types.identifier(className2);
        scope.rename(className2, varId.name);
        path.get("id").replaceWith(classId);
        return {
          id: _core.types.cloneNode(varId),
          path
        };
      } else {
        let varId;
        if (id) {
          className = id.name;
          varId = generateLetUidIdentifier(scope.parent, className);
          scope.rename(className, varId.name);
        } else {
          varId = generateLetUidIdentifier(scope.parent, typeof className === "string" ? className : "decorated_class");
        }
        const newClassExpr = _core.types.classExpression(typeof className === "string" ? _core.types.identifier(className) : null, path.node.superClass, path.node.body);
        const [newPath] = path.replaceWith(_core.types.sequenceExpression([newClassExpr, varId]));
        return {
          id: _core.types.cloneNode(varId),
          path: newPath.get("expressions.0")
        };
      }
    }
    function generateClassProperty(key, value, isStatic) {
      if (key.type === "PrivateName") {
        return _core.types.classPrivateProperty(key, value, void 0, isStatic);
      } else {
        return _core.types.classProperty(key, value, void 0, void 0, isStatic);
      }
    }
    function assignIdForAnonymousClass(path, className) {
      if (!path.node.id) {
        path.node.id = typeof className === "string" ? _core.types.identifier(className) : path.scope.generateUidIdentifier("Class");
      }
    }
    function addProxyAccessorsFor(className, element, getterKey, setterKey, targetKey, isComputed, isStatic, version) {
      const thisArg = (version === "2023-11" || version === "2023-05") && isStatic ? className : _core.types.thisExpression();
      const getterBody = _core.types.blockStatement([_core.types.returnStatement(_core.types.memberExpression(_core.types.cloneNode(thisArg), _core.types.cloneNode(targetKey)))]);
      const setterBody = _core.types.blockStatement([_core.types.expressionStatement(_core.types.assignmentExpression("=", _core.types.memberExpression(_core.types.cloneNode(thisArg), _core.types.cloneNode(targetKey)), _core.types.identifier("v")))]);
      let getter, setter;
      if (getterKey.type === "PrivateName") {
        getter = _core.types.classPrivateMethod("get", getterKey, [], getterBody, isStatic);
        setter = _core.types.classPrivateMethod("set", setterKey, [_core.types.identifier("v")], setterBody, isStatic);
      } else {
        getter = _core.types.classMethod("get", getterKey, [], getterBody, isComputed, isStatic);
        setter = _core.types.classMethod("set", setterKey, [_core.types.identifier("v")], setterBody, isComputed, isStatic);
      }
      element.insertAfter(setter);
      element.insertAfter(getter);
    }
    function extractProxyAccessorsFor(targetKey, version) {
      if (version !== "2023-11" && version !== "2023-05" && version !== "2023-01") {
        return [_core.template.expression.ast`
        function () {
          return this.${_core.types.cloneNode(targetKey)};
        }
      `, _core.template.expression.ast`
        function (value) {
          this.${_core.types.cloneNode(targetKey)} = value;
        }
      `];
      }
      return [_core.template.expression.ast`
      o => o.${_core.types.cloneNode(targetKey)}
    `, _core.template.expression.ast`
      (o, v) => o.${_core.types.cloneNode(targetKey)} = v
    `];
    }
    function getComputedKeyLastElement(path) {
      path = (0, _helperSkipTransparentExpressionWrappers.skipTransparentExprWrappers)(path);
      if (path.isSequenceExpression()) {
        const expressions = path.get("expressions");
        return getComputedKeyLastElement(expressions[expressions.length - 1]);
      }
      return path;
    }
    function getComputedKeyMemoiser(path) {
      const element = getComputedKeyLastElement(path);
      if (element.isConstantExpression()) {
        return _core.types.cloneNode(path.node);
      } else if (element.isIdentifier() && path.scope.hasUid(element.node.name)) {
        return _core.types.cloneNode(path.node);
      } else if (element.isAssignmentExpression() && element.get("left").isIdentifier()) {
        return _core.types.cloneNode(element.node.left);
      } else {
        throw new Error(`Internal Error: the computed key ${path.toString()} has not yet been memoised.`);
      }
    }
    function prependExpressionsToComputedKey(expressions, fieldPath) {
      const key = fieldPath.get("key");
      if (key.isSequenceExpression()) {
        expressions.push(...key.node.expressions);
      } else {
        expressions.push(key.node);
      }
      key.replaceWith(maybeSequenceExpression(expressions));
    }
    function appendExpressionsToComputedKey(expressions, fieldPath) {
      const key = fieldPath.get("key");
      const completion = getComputedKeyLastElement(key);
      if (completion.isConstantExpression()) {
        prependExpressionsToComputedKey(expressions, fieldPath);
      } else {
        const scopeParent = key.scope.parent;
        const maybeAssignment = (0, _misc.memoiseComputedKey)(completion.node, scopeParent, scopeParent.generateUid("computedKey"));
        if (!maybeAssignment) {
          prependExpressionsToComputedKey(expressions, fieldPath);
        } else {
          const expressionSequence = [...expressions, _core.types.cloneNode(maybeAssignment.left)];
          const completionParent = completion.parentPath;
          if (completionParent.isSequenceExpression()) {
            completionParent.pushContainer("expressions", expressionSequence);
          } else {
            completion.replaceWith(maybeSequenceExpression([_core.types.cloneNode(maybeAssignment), ...expressionSequence]));
          }
        }
      }
    }
    function prependExpressionsToFieldInitializer(expressions, fieldPath) {
      const initializer = fieldPath.get("value");
      if (initializer.node) {
        expressions.push(initializer.node);
      } else if (expressions.length > 0) {
        expressions[expressions.length - 1] = _core.types.unaryExpression("void", expressions[expressions.length - 1]);
      }
      initializer.replaceWith(maybeSequenceExpression(expressions));
    }
    function prependExpressionsToStaticBlock(expressions, blockPath) {
      blockPath.unshiftContainer("body", _core.types.expressionStatement(maybeSequenceExpression(expressions)));
    }
    function prependExpressionsToConstructor(expressions, constructorPath) {
      constructorPath.node.body.body.unshift(_core.types.expressionStatement(maybeSequenceExpression(expressions)));
    }
    function isProtoInitCallExpression(expression, protoInitCall) {
      return _core.types.isCallExpression(expression) && _core.types.isIdentifier(expression.callee, {
        name: protoInitCall.name
      });
    }
    function optimizeSuperCallAndExpressions(expressions, protoInitLocal) {
      if (protoInitLocal) {
        if (expressions.length >= 2 && isProtoInitCallExpression(expressions[1], protoInitLocal)) {
          const mergedSuperCall = _core.types.callExpression(_core.types.cloneNode(protoInitLocal), [expressions[0]]);
          expressions.splice(0, 2, mergedSuperCall);
        }
        if (expressions.length >= 2 && _core.types.isThisExpression(expressions[expressions.length - 1]) && isProtoInitCallExpression(expressions[expressions.length - 2], protoInitLocal)) {
          expressions.splice(expressions.length - 1, 1);
        }
      }
      return maybeSequenceExpression(expressions);
    }
    function insertExpressionsAfterSuperCallAndOptimize(expressions, constructorPath, protoInitLocal) {
      constructorPath.traverse({
        CallExpression: {
          exit(path) {
            if (!path.get("callee").isSuper()) return;
            const newNodes = [path.node, ...expressions.map((expr) => _core.types.cloneNode(expr))];
            if (path.isCompletionRecord()) {
              newNodes.push(_core.types.thisExpression());
            }
            path.replaceWith(optimizeSuperCallAndExpressions(newNodes, protoInitLocal));
            path.skip();
          }
        },
        ClassMethod(path) {
          if (path.node.kind === "constructor") {
            path.skip();
          }
        }
      });
    }
    function createConstructorFromExpressions(expressions, isDerivedClass) {
      const body = [_core.types.expressionStatement(maybeSequenceExpression(expressions))];
      if (isDerivedClass) {
        body.unshift(_core.types.expressionStatement(_core.types.callExpression(_core.types.super(), [_core.types.spreadElement(_core.types.identifier("args"))])));
      }
      return _core.types.classMethod("constructor", _core.types.identifier("constructor"), isDerivedClass ? [_core.types.restElement(_core.types.identifier("args"))] : [], _core.types.blockStatement(body));
    }
    function createStaticBlockFromExpressions(expressions) {
      return _core.types.staticBlock([_core.types.expressionStatement(maybeSequenceExpression(expressions))]);
    }
    var FIELD = 0;
    var ACCESSOR = 1;
    var METHOD = 2;
    var GETTER = 3;
    var SETTER = 4;
    var STATIC_OLD_VERSION = 5;
    var STATIC = 8;
    var DECORATORS_HAVE_THIS = 16;
    function getElementKind(element) {
      switch (element.node.type) {
        case "ClassProperty":
        case "ClassPrivateProperty":
          return FIELD;
        case "ClassAccessorProperty":
          return ACCESSOR;
        case "ClassMethod":
        case "ClassPrivateMethod":
          if (element.node.kind === "get") {
            return GETTER;
          } else if (element.node.kind === "set") {
            return SETTER;
          } else {
            return METHOD;
          }
      }
    }
    function toSortedDecoratorInfo(info) {
      return [...info.filter((el) => el.isStatic && el.kind >= ACCESSOR && el.kind <= SETTER), ...info.filter((el) => !el.isStatic && el.kind >= ACCESSOR && el.kind <= SETTER), ...info.filter((el) => el.isStatic && el.kind === FIELD), ...info.filter((el) => !el.isStatic && el.kind === FIELD)];
    }
    function generateDecorationList(decorators, decoratorsThis, version) {
      const decsCount = decorators.length;
      const haveOneThis = decoratorsThis.some(Boolean);
      const decs = [];
      for (let i = 0; i < decsCount; i++) {
        if ((version === "2023-11" || version === "2023-05") && haveOneThis) {
          decs.push(decoratorsThis[i] || _core.types.unaryExpression("void", _core.types.numericLiteral(0)));
        }
        decs.push(decorators[i].expression);
      }
      return {
        haveThis: haveOneThis,
        decs
      };
    }
    function generateDecorationExprs(decorationInfo, version) {
      return _core.types.arrayExpression(decorationInfo.map((el) => {
        let flag = el.kind;
        if (el.isStatic) {
          flag += version === "2023-11" || version === "2023-05" ? STATIC : STATIC_OLD_VERSION;
        }
        if (el.decoratorsHaveThis) flag += DECORATORS_HAVE_THIS;
        return _core.types.arrayExpression([el.decoratorsArray, _core.types.numericLiteral(flag), el.name, ...el.privateMethods || []]);
      }));
    }
    function extractElementLocalAssignments(decorationInfo) {
      const localIds = [];
      for (const el of decorationInfo) {
        const {
          locals
        } = el;
        if (Array.isArray(locals)) {
          localIds.push(...locals);
        } else if (locals !== void 0) {
          localIds.push(locals);
        }
      }
      return localIds;
    }
    function addCallAccessorsFor(version, element, key, getId, setId, isStatic) {
      element.insertAfter(_core.types.classPrivateMethod("get", _core.types.cloneNode(key), [], _core.types.blockStatement([_core.types.returnStatement(_core.types.callExpression(_core.types.cloneNode(getId), version === "2023-11" && isStatic ? [] : [_core.types.thisExpression()]))]), isStatic));
      element.insertAfter(_core.types.classPrivateMethod("set", _core.types.cloneNode(key), [_core.types.identifier("v")], _core.types.blockStatement([_core.types.expressionStatement(_core.types.callExpression(_core.types.cloneNode(setId), version === "2023-11" && isStatic ? [_core.types.identifier("v")] : [_core.types.thisExpression(), _core.types.identifier("v")]))]), isStatic));
    }
    function movePrivateAccessor(element, key, methodLocalVar, isStatic) {
      let params;
      let block;
      if (element.node.kind === "set") {
        params = [_core.types.identifier("v")];
        block = [_core.types.expressionStatement(_core.types.callExpression(methodLocalVar, [_core.types.thisExpression(), _core.types.identifier("v")]))];
      } else {
        params = [];
        block = [_core.types.returnStatement(_core.types.callExpression(methodLocalVar, [_core.types.thisExpression()]))];
      }
      element.replaceWith(_core.types.classPrivateMethod(element.node.kind, _core.types.cloneNode(key), params, _core.types.blockStatement(block), isStatic));
    }
    function isClassDecoratableElementPath(path) {
      const {
        type
      } = path;
      return type !== "TSDeclareMethod" && type !== "TSIndexSignature" && type !== "StaticBlock";
    }
    function staticBlockToIIFE(block) {
      return _core.types.callExpression(_core.types.arrowFunctionExpression([], _core.types.blockStatement(block.body)), []);
    }
    function staticBlockToFunctionClosure(block) {
      return _core.types.functionExpression(null, [], _core.types.blockStatement(block.body));
    }
    function fieldInitializerToClosure(value) {
      return _core.types.functionExpression(null, [], _core.types.blockStatement([_core.types.returnStatement(value)]));
    }
    function maybeSequenceExpression(exprs) {
      if (exprs.length === 0) return _core.types.unaryExpression("void", _core.types.numericLiteral(0));
      if (exprs.length === 1) return exprs[0];
      return _core.types.sequenceExpression(exprs);
    }
    function createFunctionExpressionFromPrivateMethod(node) {
      const {
        params,
        body,
        generator: isGenerator,
        async: isAsync
      } = node;
      return _core.types.functionExpression(void 0, params, body, isGenerator, isAsync);
    }
    function createSetFunctionNameCall(state, className) {
      return _core.types.callExpression(state.addHelper("setFunctionName"), [_core.types.thisExpression(), className]);
    }
    function createToPropertyKeyCall(state, propertyKey) {
      return _core.types.callExpression(state.addHelper("toPropertyKey"), [propertyKey]);
    }
    function createPrivateBrandCheckClosure(brandName) {
      return _core.types.arrowFunctionExpression([_core.types.identifier("_")], _core.types.binaryExpression("in", _core.types.cloneNode(brandName), _core.types.identifier("_")));
    }
    function usesPrivateField(expression) {
      try {
        _core.types.traverseFast(expression, (node) => {
          if (_core.types.isPrivateName(node)) {
            throw null;
          }
        });
        return false;
      } catch (_unused) {
        return true;
      }
    }
    function convertToComputedKey(path) {
      const {
        node
      } = path;
      node.computed = true;
      if (_core.types.isIdentifier(node.key)) {
        node.key = _core.types.stringLiteral(node.key.name);
      }
    }
    function hasInstancePrivateAccess(path, privateNames) {
      let containsInstancePrivateAccess = false;
      if (privateNames.length > 0) {
        const privateNameVisitor = (0, _fields.privateNameVisitorFactory)({
          PrivateName(path2, state) {
            if (state.privateNamesMap.has(path2.node.id.name)) {
              containsInstancePrivateAccess = true;
              path2.stop();
            }
          }
        });
        const privateNamesMap = /* @__PURE__ */ new Map();
        for (const name of privateNames) {
          privateNamesMap.set(name, null);
        }
        path.traverse(privateNameVisitor, {
          privateNamesMap
        });
      }
      return containsInstancePrivateAccess;
    }
    function checkPrivateMethodUpdateError(path, decoratedPrivateMethods) {
      const privateNameVisitor = (0, _fields.privateNameVisitorFactory)({
        PrivateName(path2, state) {
          if (!state.privateNamesMap.has(path2.node.id.name)) return;
          const parentPath = path2.parentPath;
          const parentParentPath = parentPath.parentPath;
          if (parentParentPath.node.type === "AssignmentExpression" && parentParentPath.node.left === parentPath.node || parentParentPath.node.type === "UpdateExpression" || parentParentPath.node.type === "RestElement" || parentParentPath.node.type === "ArrayPattern" || parentParentPath.node.type === "ObjectProperty" && parentParentPath.node.value === parentPath.node && parentParentPath.parentPath.type === "ObjectPattern" || parentParentPath.node.type === "ForOfStatement" && parentParentPath.node.left === parentPath.node) {
            throw path2.buildCodeFrameError(`Decorated private methods are read-only, but "#${path2.node.id.name}" is updated via this expression.`);
          }
        }
      });
      const privateNamesMap = /* @__PURE__ */ new Map();
      for (const name of decoratedPrivateMethods) {
        privateNamesMap.set(name, null);
      }
      path.traverse(privateNameVisitor, {
        privateNamesMap
      });
    }
    function transformClass(path, state, constantSuper, ignoreFunctionLength, className, propertyVisitor, version) {
      var _path$node$id;
      const body = path.get("body.body");
      const classDecorators = path.node.decorators;
      let hasElementDecorators = false;
      let hasComputedKeysSideEffects = false;
      let elemDecsUseFnContext = false;
      const generateClassPrivateUid = createLazyPrivateUidGeneratorForClass(path);
      const classAssignments = [];
      const scopeParent = path.scope.parent;
      const memoiseExpression = (expression, hint, assignments) => {
        const localEvaluatedId = generateLetUidIdentifier(scopeParent, hint);
        assignments.push(_core.types.assignmentExpression("=", localEvaluatedId, expression));
        return _core.types.cloneNode(localEvaluatedId);
      };
      let protoInitLocal;
      let staticInitLocal;
      const classIdName = (_path$node$id = path.node.id) == null ? void 0 : _path$node$id.name;
      const setClassName = typeof className === "object" ? className : void 0;
      const usesFunctionContextOrYieldAwait = (decorator) => {
        try {
          _core.types.traverseFast(decorator, (node) => {
            if (_core.types.isThisExpression(node) || _core.types.isSuper(node) || _core.types.isYieldExpression(node) || _core.types.isAwaitExpression(node) || _core.types.isIdentifier(node, {
              name: "arguments"
            }) || classIdName && _core.types.isIdentifier(node, {
              name: classIdName
            }) || _core.types.isMetaProperty(node) && node.meta.name !== "import") {
              throw null;
            }
          });
          return false;
        } catch (_unused2) {
          return true;
        }
      };
      const instancePrivateNames = [];
      for (const element of body) {
        if (!isClassDecoratableElementPath(element)) {
          continue;
        }
        const elementNode = element.node;
        if (!elementNode.static && _core.types.isPrivateName(elementNode.key)) {
          instancePrivateNames.push(elementNode.key.id.name);
        }
        if (isDecorated(elementNode)) {
          switch (elementNode.type) {
            case "ClassProperty":
              propertyVisitor.ClassProperty(element, state);
              break;
            case "ClassPrivateProperty":
              propertyVisitor.ClassPrivateProperty(element, state);
              break;
            case "ClassAccessorProperty":
              propertyVisitor.ClassAccessorProperty(element, state);
              if (version === "2023-11") {
                break;
              }
            default:
              if (elementNode.static) {
                staticInitLocal != null ? staticInitLocal : staticInitLocal = generateLetUidIdentifier(scopeParent, "initStatic");
              } else {
                protoInitLocal != null ? protoInitLocal : protoInitLocal = generateLetUidIdentifier(scopeParent, "initProto");
              }
              break;
          }
          hasElementDecorators = true;
          elemDecsUseFnContext || (elemDecsUseFnContext = elementNode.decorators.some(usesFunctionContextOrYieldAwait));
        } else if (elementNode.type === "ClassAccessorProperty") {
          propertyVisitor.ClassAccessorProperty(element, state);
          const {
            key,
            value,
            static: isStatic,
            computed
          } = elementNode;
          const newId = generateClassPrivateUid();
          const newField = generateClassProperty(newId, value, isStatic);
          const keyPath = element.get("key");
          const [newPath] = element.replaceWith(newField);
          let getterKey, setterKey;
          if (computed && !keyPath.isConstantExpression()) {
            getterKey = (0, _misc.memoiseComputedKey)(createToPropertyKeyCall(state, key), scopeParent, scopeParent.generateUid("computedKey"));
            setterKey = _core.types.cloneNode(getterKey.left);
          } else {
            getterKey = _core.types.cloneNode(key);
            setterKey = _core.types.cloneNode(key);
          }
          assignIdForAnonymousClass(path, className);
          addProxyAccessorsFor(path.node.id, newPath, getterKey, setterKey, newId, computed, isStatic, version);
        }
        if ("computed" in element.node && element.node.computed) {
          hasComputedKeysSideEffects || (hasComputedKeysSideEffects = !scopeParent.isStatic(element.node.key));
        }
      }
      if (!classDecorators && !hasElementDecorators) {
        if (!path.node.id && typeof className === "string") {
          path.node.id = _core.types.identifier(className);
        }
        if (setClassName) {
          path.node.body.body.unshift(createStaticBlockFromExpressions([createSetFunctionNameCall(state, setClassName)]));
        }
        return;
      }
      const elementDecoratorInfo = [];
      let constructorPath;
      const decoratedPrivateMethods = /* @__PURE__ */ new Set();
      let classInitLocal, classIdLocal;
      let decoratorReceiverId = null;
      function handleDecorators(decorators) {
        let hasSideEffects = false;
        let usesFnContext = false;
        const decoratorsThis = [];
        for (const decorator of decorators) {
          const {
            expression
          } = decorator;
          let object;
          if ((version === "2023-11" || version === "2023-05") && _core.types.isMemberExpression(expression)) {
            if (_core.types.isSuper(expression.object)) {
              object = _core.types.thisExpression();
            } else if (scopeParent.isStatic(expression.object)) {
              object = _core.types.cloneNode(expression.object);
            } else {
              decoratorReceiverId != null ? decoratorReceiverId : decoratorReceiverId = generateLetUidIdentifier(scopeParent, "obj");
              object = _core.types.assignmentExpression("=", _core.types.cloneNode(decoratorReceiverId), expression.object);
              expression.object = _core.types.cloneNode(decoratorReceiverId);
            }
          }
          decoratorsThis.push(object);
          hasSideEffects || (hasSideEffects = !scopeParent.isStatic(expression));
          usesFnContext || (usesFnContext = usesFunctionContextOrYieldAwait(decorator));
        }
        return {
          hasSideEffects,
          usesFnContext,
          decoratorsThis
        };
      }
      const willExtractSomeElemDecs = hasComputedKeysSideEffects || elemDecsUseFnContext || version !== "2023-11";
      let needsDeclarationForClassBinding = false;
      let classDecorationsFlag = 0;
      let classDecorations = [];
      let classDecorationsId;
      let computedKeyAssignments = [];
      if (classDecorators) {
        classInitLocal = generateLetUidIdentifier(scopeParent, "initClass");
        needsDeclarationForClassBinding = path.isClassDeclaration();
        ({
          id: classIdLocal,
          path
        } = replaceClassWithVar(path, className));
        path.node.decorators = null;
        const classDecsUsePrivateName = classDecorators.some(usesPrivateField);
        const {
          hasSideEffects,
          usesFnContext,
          decoratorsThis
        } = handleDecorators(classDecorators);
        const {
          haveThis,
          decs
        } = generateDecorationList(classDecorators, decoratorsThis, version);
        classDecorationsFlag = haveThis ? 1 : 0;
        classDecorations = decs;
        if (usesFnContext || hasSideEffects && willExtractSomeElemDecs || classDecsUsePrivateName) {
          classDecorationsId = memoiseExpression(_core.types.arrayExpression(classDecorations), "classDecs", classAssignments);
        }
        if (!hasElementDecorators) {
          for (const element of path.get("body.body")) {
            const {
              node
            } = element;
            const isComputed = "computed" in node && node.computed;
            if (isComputed) {
              if (element.isClassProperty({
                static: true
              })) {
                if (!element.get("key").isConstantExpression()) {
                  const key = node.key;
                  const maybeAssignment = (0, _misc.memoiseComputedKey)(key, scopeParent, scopeParent.generateUid("computedKey"));
                  if (maybeAssignment != null) {
                    node.key = _core.types.cloneNode(maybeAssignment.left);
                    computedKeyAssignments.push(maybeAssignment);
                  }
                }
              } else if (computedKeyAssignments.length > 0) {
                prependExpressionsToComputedKey(computedKeyAssignments, element);
                computedKeyAssignments = [];
              }
            }
          }
        }
      } else {
        assignIdForAnonymousClass(path, className);
        classIdLocal = _core.types.cloneNode(path.node.id);
      }
      let lastInstancePrivateName;
      let needsInstancePrivateBrandCheck = false;
      let fieldInitializerExpressions = [];
      let staticFieldInitializerExpressions = [];
      if (hasElementDecorators) {
        if (protoInitLocal) {
          const protoInitCall = _core.types.callExpression(_core.types.cloneNode(protoInitLocal), [_core.types.thisExpression()]);
          fieldInitializerExpressions.push(protoInitCall);
        }
        for (const element of body) {
          if (!isClassDecoratableElementPath(element)) {
            if (staticFieldInitializerExpressions.length > 0 && element.isStaticBlock()) {
              prependExpressionsToStaticBlock(staticFieldInitializerExpressions, element);
              staticFieldInitializerExpressions = [];
            }
            continue;
          }
          const {
            node
          } = element;
          const decorators = node.decorators;
          const hasDecorators2 = !!(decorators != null && decorators.length);
          const isComputed = "computed" in node && node.computed;
          let name = "computedKey";
          if (node.key.type === "PrivateName") {
            name = node.key.id.name;
          } else if (!isComputed && node.key.type === "Identifier") {
            name = node.key.name;
          }
          let decoratorsArray;
          let decoratorsHaveThis;
          if (hasDecorators2) {
            const {
              hasSideEffects,
              usesFnContext,
              decoratorsThis
            } = handleDecorators(decorators);
            const {
              decs,
              haveThis
            } = generateDecorationList(decorators, decoratorsThis, version);
            decoratorsHaveThis = haveThis;
            decoratorsArray = decs.length === 1 ? decs[0] : _core.types.arrayExpression(decs);
            if (usesFnContext || hasSideEffects && willExtractSomeElemDecs) {
              decoratorsArray = memoiseExpression(decoratorsArray, name + "Decs", computedKeyAssignments);
            }
          }
          if (isComputed) {
            if (!element.get("key").isConstantExpression()) {
              const key2 = node.key;
              const maybeAssignment = (0, _misc.memoiseComputedKey)(hasDecorators2 ? createToPropertyKeyCall(state, key2) : key2, scopeParent, scopeParent.generateUid("computedKey"));
              if (maybeAssignment != null) {
                if (classDecorators && element.isClassProperty({
                  static: true
                })) {
                  node.key = _core.types.cloneNode(maybeAssignment.left);
                  computedKeyAssignments.push(maybeAssignment);
                } else {
                  node.key = maybeAssignment;
                }
              }
            }
          }
          const {
            key,
            static: isStatic
          } = node;
          const isPrivate = key.type === "PrivateName";
          const kind = getElementKind(element);
          if (isPrivate && !isStatic) {
            if (hasDecorators2) {
              needsInstancePrivateBrandCheck = true;
            }
            if (_core.types.isClassPrivateProperty(node) || !lastInstancePrivateName) {
              lastInstancePrivateName = key;
            }
          }
          if (element.isClassMethod({
            kind: "constructor"
          })) {
            constructorPath = element;
          }
          let locals;
          if (hasDecorators2) {
            let privateMethods;
            let nameExpr;
            if (isComputed) {
              nameExpr = getComputedKeyMemoiser(element.get("key"));
            } else if (key.type === "PrivateName") {
              nameExpr = _core.types.stringLiteral(key.id.name);
            } else if (key.type === "Identifier") {
              nameExpr = _core.types.stringLiteral(key.name);
            } else {
              nameExpr = _core.types.cloneNode(key);
            }
            if (kind === ACCESSOR) {
              const {
                value
              } = element.node;
              const params = version === "2023-11" && isStatic ? [] : [_core.types.thisExpression()];
              if (value) {
                params.push(_core.types.cloneNode(value));
              }
              const newId = generateClassPrivateUid();
              const newFieldInitId = generateLetUidIdentifier(scopeParent, `init_${name}`);
              const newValue = _core.types.callExpression(_core.types.cloneNode(newFieldInitId), params);
              const newField = generateClassProperty(newId, newValue, isStatic);
              const [newPath] = element.replaceWith(newField);
              if (isPrivate) {
                privateMethods = extractProxyAccessorsFor(newId, version);
                const getId = generateLetUidIdentifier(scopeParent, `get_${name}`);
                const setId = generateLetUidIdentifier(scopeParent, `set_${name}`);
                addCallAccessorsFor(version, newPath, key, getId, setId, isStatic);
                locals = [newFieldInitId, getId, setId];
              } else {
                assignIdForAnonymousClass(path, className);
                addProxyAccessorsFor(path.node.id, newPath, _core.types.cloneNode(key), _core.types.isAssignmentExpression(key) ? _core.types.cloneNode(key.left) : _core.types.cloneNode(key), newId, isComputed, isStatic, version);
                locals = [newFieldInitId];
              }
            } else if (kind === FIELD) {
              const initId = generateLetUidIdentifier(scopeParent, `init_${name}`);
              const valuePath = element.get("value");
              const args = version === "2023-11" && isStatic ? [] : [_core.types.thisExpression()];
              if (valuePath.node) args.push(valuePath.node);
              valuePath.replaceWith(_core.types.callExpression(_core.types.cloneNode(initId), args));
              locals = [initId];
              if (isPrivate) {
                privateMethods = extractProxyAccessorsFor(key, version);
              }
            } else if (isPrivate) {
              const callId = generateLetUidIdentifier(scopeParent, `call_${name}`);
              locals = [callId];
              const replaceSupers = new _helperReplaceSupers.default({
                constantSuper,
                methodPath: element,
                objectRef: classIdLocal,
                superRef: path.node.superClass,
                file: state.file,
                refToPreserve: classIdLocal
              });
              replaceSupers.replace();
              privateMethods = [createFunctionExpressionFromPrivateMethod(element.node)];
              if (kind === GETTER || kind === SETTER) {
                movePrivateAccessor(element, _core.types.cloneNode(key), _core.types.cloneNode(callId), isStatic);
              } else {
                const node2 = element.node;
                path.node.body.body.unshift(_core.types.classPrivateProperty(key, _core.types.cloneNode(callId), [], node2.static));
                decoratedPrivateMethods.add(key.id.name);
                element.remove();
              }
            }
            elementDecoratorInfo.push({
              kind,
              decoratorsArray,
              decoratorsHaveThis,
              name: nameExpr,
              isStatic,
              privateMethods,
              locals
            });
            if (element.node) {
              element.node.decorators = null;
            }
          }
          if (isComputed && computedKeyAssignments.length > 0) {
            if (classDecorators && element.isClassProperty({
              static: true
            })) {
            } else {
              prependExpressionsToComputedKey(computedKeyAssignments, kind === ACCESSOR ? element.getNextSibling() : element);
              computedKeyAssignments = [];
            }
          }
          if (fieldInitializerExpressions.length > 0 && !isStatic && (kind === FIELD || kind === ACCESSOR)) {
            prependExpressionsToFieldInitializer(fieldInitializerExpressions, element);
            fieldInitializerExpressions = [];
          }
          if (staticFieldInitializerExpressions.length > 0 && isStatic && (kind === FIELD || kind === ACCESSOR)) {
            prependExpressionsToFieldInitializer(staticFieldInitializerExpressions, element);
            staticFieldInitializerExpressions = [];
          }
          if (hasDecorators2 && version === "2023-11") {
            if (kind === FIELD || kind === ACCESSOR) {
              const initExtraId = generateLetUidIdentifier(scopeParent, `init_extra_${name}`);
              locals.push(initExtraId);
              const initExtraCall = _core.types.callExpression(_core.types.cloneNode(initExtraId), isStatic ? [] : [_core.types.thisExpression()]);
              if (!isStatic) {
                fieldInitializerExpressions.push(initExtraCall);
              } else {
                staticFieldInitializerExpressions.push(initExtraCall);
              }
            }
          }
        }
      }
      if (computedKeyAssignments.length > 0) {
        const elements = path.get("body.body");
        let lastComputedElement;
        for (let i = elements.length - 1; i >= 0; i--) {
          const path2 = elements[i];
          const node = path2.node;
          if (node.computed) {
            if (classDecorators && _core.types.isClassProperty(node, {
              static: true
            })) {
              continue;
            }
            lastComputedElement = path2;
            break;
          }
        }
        if (lastComputedElement != null) {
          appendExpressionsToComputedKey(computedKeyAssignments, lastComputedElement);
          computedKeyAssignments = [];
        } else {
        }
      }
      if (fieldInitializerExpressions.length > 0) {
        const isDerivedClass = !!path.node.superClass;
        if (constructorPath) {
          if (isDerivedClass) {
            insertExpressionsAfterSuperCallAndOptimize(fieldInitializerExpressions, constructorPath, protoInitLocal);
          } else {
            prependExpressionsToConstructor(fieldInitializerExpressions, constructorPath);
          }
        } else {
          path.node.body.body.unshift(createConstructorFromExpressions(fieldInitializerExpressions, isDerivedClass));
        }
        fieldInitializerExpressions = [];
      }
      if (staticFieldInitializerExpressions.length > 0) {
        path.node.body.body.push(createStaticBlockFromExpressions(staticFieldInitializerExpressions));
        staticFieldInitializerExpressions = [];
      }
      const sortedElementDecoratorInfo = toSortedDecoratorInfo(elementDecoratorInfo);
      const elementDecorations = generateDecorationExprs(version === "2023-11" ? elementDecoratorInfo : sortedElementDecoratorInfo, version);
      const elementLocals = extractElementLocalAssignments(sortedElementDecoratorInfo);
      if (protoInitLocal) {
        elementLocals.push(protoInitLocal);
      }
      if (staticInitLocal) {
        elementLocals.push(staticInitLocal);
      }
      const classLocals = [];
      let classInitInjected = false;
      const classInitCall = classInitLocal && _core.types.callExpression(_core.types.cloneNode(classInitLocal), []);
      let originalClassPath = path;
      const originalClass = path.node;
      const staticClosures = [];
      if (classDecorators) {
        classLocals.push(classIdLocal, classInitLocal);
        const statics = [];
        path.get("body.body").forEach((element) => {
          if (element.isStaticBlock() || !element.isClassMethod() && element.node.static) {
            const replaceSupers = new _helperReplaceSupers.default({
              constantSuper,
              methodPath: element,
              objectRef: classIdLocal,
              superRef: path.node.superClass,
              file: state.file,
              refToPreserve: classIdLocal
            });
            replaceSupers.replace();
          }
          if (element.isStaticBlock()) {
            if (hasInstancePrivateAccess(element, instancePrivateNames)) {
              const staticBlockClosureId = memoiseExpression(staticBlockToFunctionClosure(element.node), "staticBlock", staticClosures);
              staticFieldInitializerExpressions.push(_core.types.callExpression(_core.types.memberExpression(staticBlockClosureId, _core.types.identifier("call")), [_core.types.thisExpression()]));
            } else {
              staticFieldInitializerExpressions.push(staticBlockToIIFE(element.node));
            }
            element.remove();
            return;
          }
          if ((element.isClassProperty() || element.isClassPrivateProperty()) && element.node.static) {
            const valuePath = element.get("value");
            if (hasInstancePrivateAccess(valuePath, instancePrivateNames)) {
              const fieldValueClosureId = memoiseExpression(fieldInitializerToClosure(valuePath.node), "fieldValue", staticClosures);
              valuePath.replaceWith(_core.types.callExpression(_core.types.memberExpression(fieldValueClosureId, _core.types.identifier("call")), [_core.types.thisExpression()]));
            }
            if (staticFieldInitializerExpressions.length > 0) {
              prependExpressionsToFieldInitializer(staticFieldInitializerExpressions, element);
              staticFieldInitializerExpressions = [];
            }
            element.node.static = false;
            statics.push(element.node);
            element.remove();
          } else if (element.isClassPrivateMethod({
            static: true
          })) {
            if (hasInstancePrivateAccess(element, instancePrivateNames)) {
              const privateMethodDelegateId = memoiseExpression(createFunctionExpressionFromPrivateMethod(element.node), element.get("key.id").node.name, staticClosures);
              if (ignoreFunctionLength) {
                element.node.params = [_core.types.restElement(_core.types.identifier("arg"))];
                element.node.body = _core.types.blockStatement([_core.types.returnStatement(_core.types.callExpression(_core.types.memberExpression(privateMethodDelegateId, _core.types.identifier("apply")), [_core.types.thisExpression(), _core.types.identifier("arg")]))]);
              } else {
                element.node.params = element.node.params.map((p, i) => {
                  if (_core.types.isRestElement(p)) {
                    return _core.types.restElement(_core.types.identifier("arg"));
                  } else {
                    return _core.types.identifier("_" + i);
                  }
                });
                element.node.body = _core.types.blockStatement([_core.types.returnStatement(_core.types.callExpression(_core.types.memberExpression(privateMethodDelegateId, _core.types.identifier("apply")), [_core.types.thisExpression(), _core.types.identifier("arguments")]))]);
              }
            }
            element.node.static = false;
            statics.push(element.node);
            element.remove();
          }
        });
        if (statics.length > 0 || staticFieldInitializerExpressions.length > 0) {
          const staticsClass = _core.template.expression.ast`
        class extends ${state.addHelper("identity")} {}
      `;
          staticsClass.body.body = [_core.types.classProperty(_core.types.toExpression(originalClass), void 0, void 0, void 0, true, true), ...statics];
          const constructorBody = [];
          const newExpr = _core.types.newExpression(staticsClass, []);
          if (staticFieldInitializerExpressions.length > 0) {
            constructorBody.push(...staticFieldInitializerExpressions);
          }
          if (classInitCall) {
            classInitInjected = true;
            constructorBody.push(classInitCall);
          }
          if (constructorBody.length > 0) {
            constructorBody.unshift(_core.types.callExpression(_core.types.super(), [_core.types.cloneNode(classIdLocal)]));
            staticsClass.body.body.push(createConstructorFromExpressions(constructorBody, false));
          } else {
            newExpr.arguments.push(_core.types.cloneNode(classIdLocal));
          }
          const [newPath] = path.replaceWith(newExpr);
          originalClassPath = newPath.get("callee").get("body").get("body.0.key");
          if (needsDeclarationForClassBinding && originalClass.id) {
            const bindingStmt = newPath.getStatementParent();
            if (bindingStmt) {
              bindingStmt.insertAfter(_core.types.variableDeclaration("var", [_core.types.variableDeclarator(_core.types.identifier(originalClass.id.name), _core.types.cloneNode(classIdLocal))]));
            }
          }
        }
      }
      if (!classInitInjected && classInitCall) {
        path.node.body.body.push(_core.types.staticBlock([_core.types.expressionStatement(classInitCall)]));
      }
      let {
        superClass
      } = originalClass;
      if (superClass && (version === "2023-11" || version === "2023-05")) {
        const id = path.scope.maybeGenerateMemoised(superClass);
        if (id) {
          originalClass.superClass = _core.types.assignmentExpression("=", id, superClass);
          superClass = id;
        }
      }
      const applyDecoratorWrapper = _core.types.staticBlock([]);
      originalClass.body.body.unshift(applyDecoratorWrapper);
      const applyDecsBody = applyDecoratorWrapper.body;
      if (computedKeyAssignments.length > 0) {
        const elements = originalClassPath.get("body.body");
        let firstPublicElement;
        for (const path2 of elements) {
          if ((path2.isClassProperty() || path2.isClassMethod()) && path2.node.kind !== "constructor") {
            firstPublicElement = path2;
            break;
          }
        }
        if (firstPublicElement != null) {
          convertToComputedKey(firstPublicElement);
          prependExpressionsToComputedKey(computedKeyAssignments, firstPublicElement);
        } else {
          originalClass.body.body.unshift(_core.types.classProperty(_core.types.sequenceExpression([...computedKeyAssignments, _core.types.stringLiteral("_")]), void 0, void 0, void 0, true, true));
          applyDecsBody.push(_core.types.expressionStatement(_core.types.unaryExpression("delete", _core.types.memberExpression(_core.types.thisExpression(), _core.types.identifier("_")))));
        }
        computedKeyAssignments = [];
      }
      applyDecsBody.push(_core.types.expressionStatement(createLocalsAssignment(elementLocals, classLocals, elementDecorations, classDecorationsId != null ? classDecorationsId : _core.types.arrayExpression(classDecorations), _core.types.numericLiteral(classDecorationsFlag), needsInstancePrivateBrandCheck ? lastInstancePrivateName : null, setClassName, _core.types.cloneNode(superClass), state, version)));
      if (staticInitLocal) {
        applyDecsBody.push(_core.types.expressionStatement(_core.types.callExpression(_core.types.cloneNode(staticInitLocal), [_core.types.thisExpression()])));
      }
      if (staticClosures.length > 0) {
        applyDecsBody.push(...staticClosures.map((expr) => _core.types.expressionStatement(expr)));
      }
      path.insertBefore(classAssignments.map((expr) => _core.types.expressionStatement(expr)));
      if (needsDeclarationForClassBinding) {
        const classBindingInfo = scopeParent.getBinding(classIdLocal.name);
        if (!classBindingInfo.constantViolations.length) {
          path.insertBefore(_core.types.variableDeclaration("let", [_core.types.variableDeclarator(_core.types.cloneNode(classIdLocal))]));
        } else {
          const classOuterBindingDelegateLocal = scopeParent.generateUidIdentifier("t" + classIdLocal.name);
          const classOuterBindingLocal = classIdLocal;
          path.replaceWithMultiple([_core.types.variableDeclaration("let", [_core.types.variableDeclarator(_core.types.cloneNode(classOuterBindingLocal)), _core.types.variableDeclarator(classOuterBindingDelegateLocal)]), _core.types.blockStatement([_core.types.variableDeclaration("let", [_core.types.variableDeclarator(_core.types.cloneNode(classIdLocal))]), path.node, _core.types.expressionStatement(_core.types.assignmentExpression("=", _core.types.cloneNode(classOuterBindingDelegateLocal), _core.types.cloneNode(classIdLocal)))]), _core.types.expressionStatement(_core.types.assignmentExpression("=", _core.types.cloneNode(classOuterBindingLocal), _core.types.cloneNode(classOuterBindingDelegateLocal)))]);
        }
      }
      if (decoratedPrivateMethods.size > 0) {
        checkPrivateMethodUpdateError(path, decoratedPrivateMethods);
      }
      path.scope.crawl();
      return path;
    }
    function createLocalsAssignment(elementLocals, classLocals, elementDecorations, classDecorations, classDecorationsFlag, maybePrivateBrandName, setClassName, superClass, state, version) {
      let lhs, rhs;
      const args = [setClassName ? createSetFunctionNameCall(state, setClassName) : _core.types.thisExpression(), classDecorations, elementDecorations];
      if (version !== "2023-11") {
        args.splice(1, 2, elementDecorations, classDecorations);
      }
      if (version === "2021-12" || version === "2022-03" && !state.availableHelper("applyDecs2203R")) {
        lhs = _core.types.arrayPattern([...elementLocals, ...classLocals]);
        rhs = _core.types.callExpression(state.addHelper(version === "2021-12" ? "applyDecs" : "applyDecs2203"), args);
        return _core.types.assignmentExpression("=", lhs, rhs);
      } else if (version === "2022-03") {
        rhs = _core.types.callExpression(state.addHelper("applyDecs2203R"), args);
      } else if (version === "2023-01") {
        if (maybePrivateBrandName) {
          args.push(createPrivateBrandCheckClosure(maybePrivateBrandName));
        }
        rhs = _core.types.callExpression(state.addHelper("applyDecs2301"), args);
      } else if (version === "2023-05") {
        if (maybePrivateBrandName || superClass || classDecorationsFlag.value !== 0) {
          args.push(classDecorationsFlag);
        }
        if (maybePrivateBrandName) {
          args.push(createPrivateBrandCheckClosure(maybePrivateBrandName));
        } else if (superClass) {
          args.push(_core.types.unaryExpression("void", _core.types.numericLiteral(0)));
        }
        if (superClass) args.push(superClass);
        rhs = _core.types.callExpression(state.addHelper("applyDecs2305"), args);
      }
      if (version === "2023-11") {
        if (maybePrivateBrandName || superClass || classDecorationsFlag.value !== 0) {
          args.push(classDecorationsFlag);
        }
        if (maybePrivateBrandName) {
          args.push(createPrivateBrandCheckClosure(maybePrivateBrandName));
        } else if (superClass) {
          args.push(_core.types.unaryExpression("void", _core.types.numericLiteral(0)));
        }
        if (superClass) args.push(superClass);
        rhs = _core.types.callExpression(state.addHelper("applyDecs2311"), args);
      }
      if (elementLocals.length > 0) {
        if (classLocals.length > 0) {
          lhs = _core.types.objectPattern([_core.types.objectProperty(_core.types.identifier("e"), _core.types.arrayPattern(elementLocals)), _core.types.objectProperty(_core.types.identifier("c"), _core.types.arrayPattern(classLocals))]);
        } else {
          lhs = _core.types.arrayPattern(elementLocals);
          rhs = _core.types.memberExpression(rhs, _core.types.identifier("e"), false, false);
        }
      } else {
        lhs = _core.types.arrayPattern(classLocals);
        rhs = _core.types.memberExpression(rhs, _core.types.identifier("c"), false, false);
      }
      return _core.types.assignmentExpression("=", lhs, rhs);
    }
    function isProtoKey(node) {
      return node.type === "Identifier" ? node.name === "__proto__" : node.value === "__proto__";
    }
    function isDecorated(node) {
      return node.decorators && node.decorators.length > 0;
    }
    function shouldTransformElement(node) {
      switch (node.type) {
        case "ClassAccessorProperty":
          return true;
        case "ClassMethod":
        case "ClassProperty":
        case "ClassPrivateMethod":
        case "ClassPrivateProperty":
          return isDecorated(node);
        default:
          return false;
      }
    }
    function shouldTransformClass(node) {
      return isDecorated(node) || node.body.body.some(shouldTransformElement);
    }
    function buildNamedEvaluationVisitor(needsName, visitor) {
      function handleComputedProperty(propertyPath, key, state) {
        switch (key.type) {
          case "StringLiteral":
            return _core.types.stringLiteral(key.value);
          case "NumericLiteral":
          case "BigIntLiteral": {
            const keyValue = key.value + "";
            propertyPath.get("key").replaceWith(_core.types.stringLiteral(keyValue));
            return _core.types.stringLiteral(keyValue);
          }
          default: {
            const ref = propertyPath.scope.maybeGenerateMemoised(key);
            propertyPath.get("key").replaceWith(_core.types.assignmentExpression("=", ref, createToPropertyKeyCall(state, key)));
            return _core.types.cloneNode(ref);
          }
        }
      }
      return {
        VariableDeclarator(path, state) {
          const id = path.node.id;
          if (id.type === "Identifier") {
            const initializer = (0, _helperSkipTransparentExpressionWrappers.skipTransparentExprWrappers)(path.get("init"));
            if (needsName(initializer)) {
              const name = id.name;
              visitor(initializer, state, name);
            }
          }
        },
        AssignmentExpression(path, state) {
          const id = path.node.left;
          if (id.type === "Identifier") {
            const initializer = (0, _helperSkipTransparentExpressionWrappers.skipTransparentExprWrappers)(path.get("right"));
            if (needsName(initializer)) {
              switch (path.node.operator) {
                case "=":
                case "&&=":
                case "||=":
                case "??=":
                  visitor(initializer, state, id.name);
              }
            }
          }
        },
        AssignmentPattern(path, state) {
          const id = path.node.left;
          if (id.type === "Identifier") {
            const initializer = (0, _helperSkipTransparentExpressionWrappers.skipTransparentExprWrappers)(path.get("right"));
            if (needsName(initializer)) {
              const name = id.name;
              visitor(initializer, state, name);
            }
          }
        },
        ObjectExpression(path, state) {
          for (const propertyPath of path.get("properties")) {
            if (!propertyPath.isObjectProperty()) continue;
            const {
              node
            } = propertyPath;
            const id = node.key;
            const initializer = (0, _helperSkipTransparentExpressionWrappers.skipTransparentExprWrappers)(propertyPath.get("value"));
            if (needsName(initializer)) {
              if (!node.computed) {
                if (!isProtoKey(id)) {
                  if (id.type === "Identifier") {
                    visitor(initializer, state, id.name);
                  } else {
                    const className = _core.types.stringLiteral(id.value + "");
                    visitor(initializer, state, className);
                  }
                }
              } else {
                const ref = handleComputedProperty(propertyPath, id, state);
                visitor(initializer, state, ref);
              }
            }
          }
        },
        ClassPrivateProperty(path, state) {
          const {
            node
          } = path;
          const initializer = (0, _helperSkipTransparentExpressionWrappers.skipTransparentExprWrappers)(path.get("value"));
          if (needsName(initializer)) {
            const className = _core.types.stringLiteral("#" + node.key.id.name);
            visitor(initializer, state, className);
          }
        },
        ClassAccessorProperty(path, state) {
          const {
            node
          } = path;
          const id = node.key;
          const initializer = (0, _helperSkipTransparentExpressionWrappers.skipTransparentExprWrappers)(path.get("value"));
          if (needsName(initializer)) {
            if (!node.computed) {
              if (id.type === "Identifier") {
                visitor(initializer, state, id.name);
              } else if (id.type === "PrivateName") {
                const className = _core.types.stringLiteral("#" + id.id.name);
                visitor(initializer, state, className);
              } else {
                const className = _core.types.stringLiteral(id.value + "");
                visitor(initializer, state, className);
              }
            } else {
              const ref = handleComputedProperty(path, id, state);
              visitor(initializer, state, ref);
            }
          }
        },
        ClassProperty(path, state) {
          const {
            node
          } = path;
          const id = node.key;
          const initializer = (0, _helperSkipTransparentExpressionWrappers.skipTransparentExprWrappers)(path.get("value"));
          if (needsName(initializer)) {
            if (!node.computed) {
              if (id.type === "Identifier") {
                visitor(initializer, state, id.name);
              } else {
                const className = _core.types.stringLiteral(id.value + "");
                visitor(initializer, state, className);
              }
            } else {
              const ref = handleComputedProperty(path, id, state);
              visitor(initializer, state, ref);
            }
          }
        }
      };
    }
    function isDecoratedAnonymousClassExpression(path) {
      return path.isClassExpression({
        id: null
      }) && shouldTransformClass(path.node);
    }
    function generateLetUidIdentifier(scope, name) {
      const id = scope.generateUidIdentifier(name);
      scope.push({
        id,
        kind: "let"
      });
      return _core.types.cloneNode(id);
    }
    function _default({
      assertVersion,
      assumption
    }, {
      loose
    }, version, inherits) {
      var _assumption, _assumption2;
      if (version === "2023-11" || version === "2023-05" || version === "2023-01") {
        assertVersion("^7.21.0");
      } else if (version === "2021-12") {
        assertVersion("^7.16.0");
      } else {
        assertVersion("^7.19.0");
      }
      const VISITED = /* @__PURE__ */ new WeakSet();
      const constantSuper = (_assumption = assumption("constantSuper")) != null ? _assumption : loose;
      const ignoreFunctionLength = (_assumption2 = assumption("ignoreFunctionLength")) != null ? _assumption2 : loose;
      const namedEvaluationVisitor = buildNamedEvaluationVisitor(isDecoratedAnonymousClassExpression, visitClass);
      function visitClass(path, state, className) {
        var _node$id;
        if (VISITED.has(path)) return;
        const {
          node
        } = path;
        className != null ? className : className = (_node$id = node.id) == null ? void 0 : _node$id.name;
        const newPath = transformClass(path, state, constantSuper, ignoreFunctionLength, className, namedEvaluationVisitor, version);
        if (newPath) {
          VISITED.add(newPath);
          return;
        }
        VISITED.add(path);
      }
      return {
        name: "proposal-decorators",
        inherits,
        visitor: Object.assign({
          ExportDefaultDeclaration(path, state) {
            const {
              declaration
            } = path.node;
            if ((declaration == null ? void 0 : declaration.type) === "ClassDeclaration" && isDecorated(declaration)) {
              var _path$splitExportDecl;
              const isAnonymous = !declaration.id;
              (_path$splitExportDecl = path.splitExportDeclaration) != null ? _path$splitExportDecl : path.splitExportDeclaration = require("@babel/traverse").NodePath.prototype.splitExportDeclaration;
              const updatedVarDeclarationPath = path.splitExportDeclaration();
              if (isAnonymous) {
                visitClass(updatedVarDeclarationPath, state, _core.types.stringLiteral("default"));
              }
            }
          },
          ExportNamedDeclaration(path) {
            const {
              declaration
            } = path.node;
            if ((declaration == null ? void 0 : declaration.type) === "ClassDeclaration" && isDecorated(declaration)) {
              var _path$splitExportDecl2;
              (_path$splitExportDecl2 = path.splitExportDeclaration) != null ? _path$splitExportDecl2 : path.splitExportDeclaration = require("@babel/traverse").NodePath.prototype.splitExportDeclaration;
              path.splitExportDeclaration();
            }
          },
          Class(path, state) {
            visitClass(path, state, void 0);
          }
        }, namedEvaluationVisitor)
      };
    }
  }
});

// node_modules/@babel/helper-create-class-features-plugin/lib/decorators-2018-09.js
var require_decorators_2018_09 = __commonJS({
  "node_modules/@babel/helper-create-class-features-plugin/lib/decorators-2018-09.js"(exports2) {
    "use strict";
    Object.defineProperty(exports2, "__esModule", {
      value: true
    });
    exports2.buildDecoratedClass = buildDecoratedClass;
    var _core = require("@babel/core");
    var _helperReplaceSupers = require_lib5();
    function prop(key, value) {
      if (!value) return null;
      return _core.types.objectProperty(_core.types.identifier(key), value);
    }
    function method(key, body) {
      return _core.types.objectMethod("method", _core.types.identifier(key), [], _core.types.blockStatement(body));
    }
    function takeDecorators(node) {
      let result;
      if (node.decorators && node.decorators.length > 0) {
        result = _core.types.arrayExpression(node.decorators.map((decorator) => decorator.expression));
      }
      node.decorators = void 0;
      return result;
    }
    function getKey(node) {
      if (node.computed) {
        return node.key;
      } else if (_core.types.isIdentifier(node.key)) {
        return _core.types.stringLiteral(node.key.name);
      } else {
        return _core.types.stringLiteral(String(node.key.value));
      }
    }
    function extractElementDescriptor(file, classRef, superRef, path) {
      const isMethod = path.isClassMethod();
      if (path.isPrivate()) {
        throw path.buildCodeFrameError(`Private ${isMethod ? "methods" : "fields"} in decorated classes are not supported yet.`);
      }
      if (path.node.type === "ClassAccessorProperty") {
        throw path.buildCodeFrameError(`Accessor properties are not supported in 2018-09 decorator transform, please specify { "version": "2021-12" } instead.`);
      }
      if (path.node.type === "StaticBlock") {
        throw path.buildCodeFrameError(`Static blocks are not supported in 2018-09 decorator transform, please specify { "version": "2021-12" } instead.`);
      }
      const {
        node,
        scope
      } = path;
      if (!path.isTSDeclareMethod()) {
        new _helperReplaceSupers.default({
          methodPath: path,
          objectRef: classRef,
          superRef,
          file,
          refToPreserve: classRef
        }).replace();
      }
      const properties = [prop("kind", _core.types.stringLiteral(_core.types.isClassMethod(node) ? node.kind : "field")), prop("decorators", takeDecorators(node)), prop("static", node.static && _core.types.booleanLiteral(true)), prop("key", getKey(node))].filter(Boolean);
      if (isMethod) {
        var _path$ensureFunctionN;
        (_path$ensureFunctionN = path.ensureFunctionName) != null ? _path$ensureFunctionN : path.ensureFunctionName = require("@babel/traverse").NodePath.prototype.ensureFunctionName;
        path.ensureFunctionName(false);
        properties.push(prop("value", _core.types.toExpression(path.node)));
      } else if (_core.types.isClassProperty(node) && node.value) {
        properties.push(method("value", _core.template.statements.ast`return ${node.value}`));
      } else {
        properties.push(prop("value", scope.buildUndefinedNode()));
      }
      path.remove();
      return _core.types.objectExpression(properties);
    }
    function addDecorateHelper(file) {
      return file.addHelper("decorate");
    }
    function buildDecoratedClass(ref, path, elements, file) {
      const {
        node,
        scope
      } = path;
      const initializeId = scope.generateUidIdentifier("initialize");
      const isDeclaration = node.id && path.isDeclaration();
      const isStrict = path.isInStrictMode();
      const {
        superClass
      } = node;
      node.type = "ClassDeclaration";
      if (!node.id) node.id = _core.types.cloneNode(ref);
      let superId;
      if (superClass) {
        superId = scope.generateUidIdentifierBasedOnNode(node.superClass, "super");
        node.superClass = superId;
      }
      const classDecorators = takeDecorators(node);
      const definitions = _core.types.arrayExpression(elements.filter((element) => !element.node.abstract && element.node.type !== "TSIndexSignature").map((path2) => extractElementDescriptor(file, node.id, superId, path2)));
      const wrapperCall = _core.template.expression.ast`
    ${addDecorateHelper(file)}(
      ${classDecorators || _core.types.nullLiteral()},
      function (${initializeId}, ${superClass ? _core.types.cloneNode(superId) : null}) {
        ${node}
        return { F: ${_core.types.cloneNode(node.id)}, d: ${definitions} };
      },
      ${superClass}
    )
  `;
      if (!isStrict) {
        wrapperCall.arguments[1].body.directives.push(_core.types.directive(_core.types.directiveLiteral("use strict")));
      }
      let replacement = wrapperCall;
      let classPathDesc = "arguments.1.body.body.0";
      if (isDeclaration) {
        replacement = _core.template.statement.ast`let ${ref} = ${wrapperCall}`;
        classPathDesc = "declarations.0.init." + classPathDesc;
      }
      return {
        instanceNodes: [_core.template.statement.ast`
        ${_core.types.cloneNode(initializeId)}(this)
      `],
        wrapClass(path2) {
          path2.replaceWith(replacement);
          return path2.get(classPathDesc);
        }
      };
    }
  }
});

// node_modules/@babel/helper-create-class-features-plugin/lib/features.js
var require_features = __commonJS({
  "node_modules/@babel/helper-create-class-features-plugin/lib/features.js"(exports2) {
    "use strict";
    Object.defineProperty(exports2, "__esModule", {
      value: true
    });
    exports2.FEATURES = void 0;
    exports2.enableFeature = enableFeature;
    exports2.isLoose = isLoose;
    exports2.shouldTransform = shouldTransform;
    var _decorators = require_decorators();
    var FEATURES = exports2.FEATURES = Object.freeze({
      fields: 1 << 1,
      privateMethods: 1 << 2,
      decorators: 1 << 3,
      privateIn: 1 << 4,
      staticBlocks: 1 << 5
    });
    var featuresSameLoose = /* @__PURE__ */ new Map([[FEATURES.fields, "@babel/plugin-transform-class-properties"], [FEATURES.privateMethods, "@babel/plugin-transform-private-methods"], [FEATURES.privateIn, "@babel/plugin-transform-private-property-in-object"]]);
    var featuresKey = "@babel/plugin-class-features/featuresKey";
    var looseKey = "@babel/plugin-class-features/looseKey";
    var looseLowPriorityKey = "@babel/plugin-class-features/looseLowPriorityKey/#__internal__@babel/preset-env__please-overwrite-loose-instead-of-throwing";
    var canIgnoreLoose = function(file, feature) {
      return !!(file.get(looseLowPriorityKey) & feature);
    };
    function enableFeature(file, feature, loose) {
      if (!hasFeature(file, feature) || canIgnoreLoose(file, feature)) {
        file.set(featuresKey, file.get(featuresKey) | feature);
        if (loose === "#__internal__@babel/preset-env__prefer-true-but-false-is-ok-if-it-prevents-an-error") {
          setLoose(file, feature, true);
          file.set(looseLowPriorityKey, file.get(looseLowPriorityKey) | feature);
        } else if (loose === "#__internal__@babel/preset-env__prefer-false-but-true-is-ok-if-it-prevents-an-error") {
          setLoose(file, feature, false);
          file.set(looseLowPriorityKey, file.get(looseLowPriorityKey) | feature);
        } else {
          setLoose(file, feature, loose);
        }
      }
      let resolvedLoose;
      for (const [mask, name] of featuresSameLoose) {
        if (!hasFeature(file, mask)) continue;
        if (canIgnoreLoose(file, mask)) continue;
        const loose2 = isLoose(file, mask);
        if (resolvedLoose === !loose2) {
          throw new Error("'loose' mode configuration must be the same for @babel/plugin-transform-class-properties, @babel/plugin-transform-private-methods and @babel/plugin-transform-private-property-in-object (when they are enabled).\n\n" + getBabelShowConfigForHint(file));
        } else {
          resolvedLoose = loose2;
          var higherPriorityPluginName = name;
        }
      }
      if (resolvedLoose !== void 0) {
        for (const [mask, name] of featuresSameLoose) {
          if (hasFeature(file, mask) && isLoose(file, mask) !== resolvedLoose) {
            setLoose(file, mask, resolvedLoose);
            console.warn(`Though the "loose" option was set to "${!resolvedLoose}" in your @babel/preset-env config, it will not be used for ${name} since the "loose" mode option was set to "${resolvedLoose}" for ${higherPriorityPluginName}.
The "loose" option must be the same for @babel/plugin-transform-class-properties, @babel/plugin-transform-private-methods and @babel/plugin-transform-private-property-in-object (when they are enabled): you can silence this warning by explicitly adding
	["${name}", { "loose": ${resolvedLoose} }]
to the "plugins" section of your Babel config.

` + getBabelShowConfigForHint(file));
          }
        }
      }
    }
    function getBabelShowConfigForHint(file) {
      let {
        filename
      } = file.opts;
      if (!filename || filename === "unknown") {
        filename = "[name of the input file]";
      }
      return `If you already set the same 'loose' mode for these plugins in your config, it's possible that they are enabled multiple times with different options.
You can re-run Babel with the BABEL_SHOW_CONFIG_FOR environment variable to show the loaded configuration:
	npx cross-env BABEL_SHOW_CONFIG_FOR=${filename} <your build command>
See https://babeljs.io/docs/configuration#print-effective-configs for more info.`;
    }
    function hasFeature(file, feature) {
      return !!(file.get(featuresKey) & feature);
    }
    function isLoose(file, feature) {
      return !!(file.get(looseKey) & feature);
    }
    function setLoose(file, feature, loose) {
      if (loose) file.set(looseKey, file.get(looseKey) | feature);
      else file.set(looseKey, file.get(looseKey) & ~feature);
      file.set(looseLowPriorityKey, file.get(looseLowPriorityKey) & ~feature);
    }
    function shouldTransform(path, file) {
      let decoratorPath = null;
      let publicFieldPath = null;
      let privateFieldPath = null;
      let privateMethodPath = null;
      let staticBlockPath = null;
      if ((0, _decorators.hasOwnDecorators)(path.node)) {
        decoratorPath = path.get("decorators.0");
      }
      for (const el of path.get("body.body")) {
        if (!decoratorPath && (0, _decorators.hasOwnDecorators)(el.node)) {
          decoratorPath = el.get("decorators.0");
        }
        if (!publicFieldPath && el.isClassProperty()) {
          publicFieldPath = el;
        }
        if (!privateFieldPath && el.isClassPrivateProperty()) {
          privateFieldPath = el;
        }
        if (!privateMethodPath && el.isClassPrivateMethod != null && el.isClassPrivateMethod()) {
          privateMethodPath = el;
        }
        if (!staticBlockPath && el.isStaticBlock != null && el.isStaticBlock()) {
          staticBlockPath = el;
        }
      }
      if (decoratorPath && privateFieldPath) {
        throw privateFieldPath.buildCodeFrameError("Private fields in decorated classes are not supported yet.");
      }
      if (decoratorPath && privateMethodPath) {
        throw privateMethodPath.buildCodeFrameError("Private methods in decorated classes are not supported yet.");
      }
      if (decoratorPath && !hasFeature(file, FEATURES.decorators)) {
        throw path.buildCodeFrameError('Decorators are not enabled.\nIf you are using ["@babel/plugin-proposal-decorators", { "version": "legacy" }], make sure it comes *before* "@babel/plugin-transform-class-properties" and enable loose mode, like so:\n	["@babel/plugin-proposal-decorators", { "version": "legacy" }]\n	["@babel/plugin-transform-class-properties", { "loose": true }]');
      }
      if (privateMethodPath && !hasFeature(file, FEATURES.privateMethods)) {
        throw privateMethodPath.buildCodeFrameError("Class private methods are not enabled. Please add `@babel/plugin-transform-private-methods` to your configuration.");
      }
      if ((publicFieldPath || privateFieldPath) && !hasFeature(file, FEATURES.fields) && !hasFeature(file, FEATURES.privateMethods)) {
        throw path.buildCodeFrameError("Class fields are not enabled. Please add `@babel/plugin-transform-class-properties` to your configuration.");
      }
      if (staticBlockPath && !hasFeature(file, FEATURES.staticBlocks)) {
        throw path.buildCodeFrameError("Static class blocks are not enabled. Please add `@babel/plugin-transform-class-static-block` to your configuration.");
      }
      if (decoratorPath || privateMethodPath || staticBlockPath) {
        return true;
      }
      if ((publicFieldPath || privateFieldPath) && hasFeature(file, FEATURES.fields)) {
        return true;
      }
      return false;
    }
  }
});

// node_modules/@babel/helper-create-class-features-plugin/lib/index.js
var require_lib8 = __commonJS({
  "node_modules/@babel/helper-create-class-features-plugin/lib/index.js"(exports2) {
    "use strict";
    Object.defineProperty(exports2, "__esModule", {
      value: true
    });
    Object.defineProperty(exports2, "FEATURES", {
      enumerable: true,
      get: function() {
        return _features.FEATURES;
      }
    });
    Object.defineProperty(exports2, "buildCheckInRHS", {
      enumerable: true,
      get: function() {
        return _fields.buildCheckInRHS;
      }
    });
    Object.defineProperty(exports2, "buildNamedEvaluationVisitor", {
      enumerable: true,
      get: function() {
        return _decorators.buildNamedEvaluationVisitor;
      }
    });
    exports2.createClassFeaturePlugin = createClassFeaturePlugin;
    Object.defineProperty(exports2, "enableFeature", {
      enumerable: true,
      get: function() {
        return _features.enableFeature;
      }
    });
    Object.defineProperty(exports2, "injectInitialization", {
      enumerable: true,
      get: function() {
        return _misc.injectInitialization;
      }
    });
    var _core = require("@babel/core");
    var _semver = require_semver();
    var _fields = require_fields();
    var _decorators = require_decorators();
    var _decorators2 = require_decorators_2018_09();
    var _misc = require_misc();
    var _features = require_features();
    var _typescript = require_typescript();
    var versionKey = "@babel/plugin-class-features/version";
    function createClassFeaturePlugin({
      name,
      feature,
      loose,
      manipulateOptions,
      api,
      inherits,
      decoratorVersion
    }) {
      var _api$assumption;
      if (feature & _features.FEATURES.decorators) {
        if (decoratorVersion === "2023-11" || decoratorVersion === "2023-05" || decoratorVersion === "2023-01" || decoratorVersion === "2022-03" || decoratorVersion === "2021-12") {
          return (0, _decorators.default)(api, {
            loose
          }, decoratorVersion, inherits);
        }
      }
      api != null ? api : api = {
        assumption: () => void 0
      };
      const setPublicClassFields = api.assumption("setPublicClassFields");
      const privateFieldsAsSymbols = api.assumption("privateFieldsAsSymbols");
      const privateFieldsAsProperties = api.assumption("privateFieldsAsProperties");
      const noUninitializedPrivateFieldAccess = (_api$assumption = api.assumption("noUninitializedPrivateFieldAccess")) != null ? _api$assumption : false;
      const constantSuper = api.assumption("constantSuper");
      const noDocumentAll = api.assumption("noDocumentAll");
      if (privateFieldsAsProperties && privateFieldsAsSymbols) {
        throw new Error(`Cannot enable both the "privateFieldsAsProperties" and "privateFieldsAsSymbols" assumptions as the same time.`);
      }
      const privateFieldsAsSymbolsOrProperties = privateFieldsAsProperties || privateFieldsAsSymbols;
      if (loose === true) {
        const explicit = [];
        if (setPublicClassFields !== void 0) {
          explicit.push(`"setPublicClassFields"`);
        }
        if (privateFieldsAsProperties !== void 0) {
          explicit.push(`"privateFieldsAsProperties"`);
        }
        if (privateFieldsAsSymbols !== void 0) {
          explicit.push(`"privateFieldsAsSymbols"`);
        }
        if (explicit.length !== 0) {
          console.warn(`[${name}]: You are using the "loose: true" option and you are explicitly setting a value for the ${explicit.join(" and ")} assumption${explicit.length > 1 ? "s" : ""}. The "loose" option can cause incompatibilities with the other class features plugins, so it's recommended that you replace it with the following top-level option:
	"assumptions": {
		"setPublicClassFields": true,
		"privateFieldsAsSymbols": true
	}`);
        }
      }
      return {
        name,
        manipulateOptions,
        inherits,
        pre(file) {
          (0, _features.enableFeature)(file, feature, loose);
          if (typeof file.get(versionKey) === "number") {
            file.set(versionKey, "7.29.7");
            return;
          }
          if (!file.get(versionKey) || _semver.lt(file.get(versionKey), "7.29.7")) {
            file.set(versionKey, "7.29.7");
          }
        },
        visitor: {
          Class(path, {
            file
          }) {
            if (file.get(versionKey) !== "7.29.7") return;
            if (!(0, _features.shouldTransform)(path, file)) return;
            const pathIsClassDeclaration = path.isClassDeclaration();
            if (pathIsClassDeclaration) (0, _typescript.assertFieldTransformed)(path);
            const loose2 = (0, _features.isLoose)(file, feature);
            let constructor;
            const isDecorated = (0, _decorators.hasDecorators)(path.node);
            const props = [];
            const elements = [];
            const computedPaths = [];
            const privateNames = /* @__PURE__ */ new Set();
            const body = path.get("body");
            for (const path2 of body.get("body")) {
              if ((path2.isClassProperty() || path2.isClassMethod()) && path2.node.computed) {
                computedPaths.push(path2);
              }
              if (path2.isPrivate()) {
                const {
                  name: name2
                } = path2.node.key.id;
                const getName = `get ${name2}`;
                const setName = `set ${name2}`;
                if (path2.isClassPrivateMethod()) {
                  if (path2.node.kind === "get") {
                    if (privateNames.has(getName) || privateNames.has(name2) && !privateNames.has(setName)) {
                      throw path2.buildCodeFrameError("Duplicate private field");
                    }
                    privateNames.add(getName).add(name2);
                  } else if (path2.node.kind === "set") {
                    if (privateNames.has(setName) || privateNames.has(name2) && !privateNames.has(getName)) {
                      throw path2.buildCodeFrameError("Duplicate private field");
                    }
                    privateNames.add(setName).add(name2);
                  }
                } else {
                  if (privateNames.has(name2) && !privateNames.has(getName) && !privateNames.has(setName) || privateNames.has(name2) && (privateNames.has(getName) || privateNames.has(setName))) {
                    throw path2.buildCodeFrameError("Duplicate private field");
                  }
                  privateNames.add(name2);
                }
              }
              if (path2.isClassMethod({
                kind: "constructor"
              })) {
                constructor = path2;
              } else {
                elements.push(path2);
                if (path2.isProperty() || path2.isPrivate() || path2.isStaticBlock != null && path2.isStaticBlock()) {
                  props.push(path2);
                }
              }
            }
            if (!props.length && !isDecorated) return;
            const innerBinding = path.node.id;
            let ref;
            if (!innerBinding || !pathIsClassDeclaration) {
              var _path$ensureFunctionN;
              (_path$ensureFunctionN = path.ensureFunctionName) != null ? _path$ensureFunctionN : path.ensureFunctionName = require("@babel/traverse").NodePath.prototype.ensureFunctionName;
              path.ensureFunctionName(false);
              ref = path.scope.generateUidIdentifier((innerBinding == null ? void 0 : innerBinding.name) || "Class");
            }
            const classRefForDefine = ref != null ? ref : _core.types.cloneNode(innerBinding);
            const privateNamesMap = (0, _fields.buildPrivateNamesMap)(classRefForDefine.name, privateFieldsAsSymbolsOrProperties != null ? privateFieldsAsSymbolsOrProperties : loose2, props, file);
            const privateNamesNodes = (0, _fields.buildPrivateNamesNodes)(privateNamesMap, privateFieldsAsProperties != null ? privateFieldsAsProperties : loose2, privateFieldsAsSymbols != null ? privateFieldsAsSymbols : false, file);
            (0, _fields.transformPrivateNamesUsage)(classRefForDefine, path, privateNamesMap, {
              privateFieldsAsProperties: privateFieldsAsSymbolsOrProperties != null ? privateFieldsAsSymbolsOrProperties : loose2,
              noUninitializedPrivateFieldAccess,
              noDocumentAll,
              innerBinding
            }, file);
            let keysNodes, staticNodes, instanceNodes, lastInstanceNodeReturnsThis, pureStaticNodes, classBindingNode, wrapClass;
            if (isDecorated) {
              staticNodes = pureStaticNodes = keysNodes = [];
              ({
                instanceNodes,
                wrapClass
              } = (0, _decorators2.buildDecoratedClass)(classRefForDefine, path, elements, file));
            } else {
              keysNodes = (0, _misc.extractComputedKeys)(path, computedPaths, file);
              ({
                staticNodes,
                pureStaticNodes,
                instanceNodes,
                lastInstanceNodeReturnsThis,
                classBindingNode,
                wrapClass
              } = (0, _fields.buildFieldsInitNodes)(ref, path.node.superClass, props, privateNamesMap, file, setPublicClassFields != null ? setPublicClassFields : loose2, privateFieldsAsSymbolsOrProperties != null ? privateFieldsAsSymbolsOrProperties : loose2, noUninitializedPrivateFieldAccess, constantSuper != null ? constantSuper : loose2, innerBinding));
            }
            if (instanceNodes.length > 0) {
              (0, _misc.injectInitialization)(path, constructor, instanceNodes, (referenceVisitor, state) => {
                if (isDecorated) return;
                for (const prop of props) {
                  if (_core.types.isStaticBlock != null && _core.types.isStaticBlock(prop.node) || prop.node.static) continue;
                  prop.traverse(referenceVisitor, state);
                }
              }, lastInstanceNodeReturnsThis);
            }
            const wrappedPath = wrapClass(path);
            wrappedPath.insertBefore([...privateNamesNodes, ...keysNodes]);
            if (staticNodes.length > 0) {
              wrappedPath.insertAfter(staticNodes);
            }
            if (pureStaticNodes.length > 0) {
              wrappedPath.find((parent) => parent.isStatement() || parent.isDeclaration()).insertAfter(pureStaticNodes);
            }
            if (classBindingNode != null && pathIsClassDeclaration) {
              wrappedPath.insertAfter(classBindingNode);
            }
          },
          ExportDefaultDeclaration(path, {
            file
          }) {
            if (file.get(versionKey) !== "7.29.7") return;
            const decl = path.get("declaration");
            if (decl.isClassDeclaration() && (0, _decorators.hasDecorators)(decl.node)) {
              if (decl.node.id) {
                var _path$splitExportDecl;
                (_path$splitExportDecl = path.splitExportDeclaration) != null ? _path$splitExportDecl : path.splitExportDeclaration = require("@babel/traverse").NodePath.prototype.splitExportDeclaration;
                path.splitExportDeclaration();
              } else {
                decl.node.type = "ClassExpression";
              }
            }
          }
        }
      };
    }
  }
});

// node_modules/@babel/plugin-proposal-decorators/lib/transformer-legacy.js
var require_transformer_legacy = __commonJS({
  "node_modules/@babel/plugin-proposal-decorators/lib/transformer-legacy.js"(exports2) {
    "use strict";
    Object.defineProperty(exports2, "__esModule", {
      value: true
    });
    exports2.default = void 0;
    var _core = require("@babel/core");
    var buildClassDecorator = _core.template.statement(`
  DECORATOR(CLASS_REF = INNER) || CLASS_REF;
`);
    var buildClassPrototype = (0, _core.template)(`
  CLASS_REF.prototype;
`);
    var buildGetDescriptor = (0, _core.template)(`
    Object.getOwnPropertyDescriptor(TARGET, PROPERTY);
`);
    var buildGetObjectInitializer = (0, _core.template)(`
    (TEMP = Object.getOwnPropertyDescriptor(TARGET, PROPERTY), (TEMP = TEMP ? TEMP.value : undefined), {
        enumerable: true,
        configurable: true,
        writable: true,
        initializer: function(){
            return TEMP;
        }
    })
`);
    var WARNING_CALLS = /* @__PURE__ */ new WeakSet();
    function applyEnsureOrdering(path) {
      const decorators = (path.isClass() ? [path, ...path.get("body.body")] : path.get("properties")).reduce((acc, prop) => acc.concat(prop.node.decorators || []), []);
      const identDecorators = decorators.filter((decorator) => !_core.types.isIdentifier(decorator.expression));
      if (identDecorators.length === 0) return;
      return _core.types.sequenceExpression(identDecorators.map((decorator) => {
        const expression = decorator.expression;
        const id = decorator.expression = path.scope.generateDeclaredUidIdentifier("dec");
        return _core.types.assignmentExpression("=", id, expression);
      }).concat([path.node]));
    }
    function applyClassDecorators(classPath) {
      if (!hasClassDecorators(classPath.node)) return;
      const decorators = classPath.node.decorators || [];
      classPath.node.decorators = null;
      const name = classPath.scope.generateDeclaredUidIdentifier("class");
      return decorators.map((dec) => dec.expression).reverse().reduce(function(acc, decorator) {
        return buildClassDecorator({
          CLASS_REF: _core.types.cloneNode(name),
          DECORATOR: _core.types.cloneNode(decorator),
          INNER: acc
        }).expression;
      }, classPath.node);
    }
    function hasClassDecorators(classNode) {
      var _classNode$decorators;
      return !!((_classNode$decorators = classNode.decorators) != null && _classNode$decorators.length);
    }
    function applyMethodDecorators(path, state) {
      if (!hasMethodDecorators(path.node.body.body)) return;
      return applyTargetDecorators(path, state, path.node.body.body);
    }
    function hasMethodDecorators(body) {
      return body.some((node) => {
        var _node$decorators;
        return (_node$decorators = node.decorators) == null ? void 0 : _node$decorators.length;
      });
    }
    function applyObjectDecorators(path, state) {
      if (!hasMethodDecorators(path.node.properties)) return;
      return applyTargetDecorators(path, state, path.node.properties.filter((prop) => prop.type !== "SpreadElement"));
    }
    function applyTargetDecorators(path, state, decoratedProps) {
      const name = path.scope.generateDeclaredUidIdentifier(path.isClass() ? "class" : "obj");
      const exprs = decoratedProps.reduce(function(acc, node) {
        let decorators = [];
        if (node.decorators != null) {
          decorators = node.decorators;
          node.decorators = null;
        }
        if (decorators.length === 0) return acc;
        if (node.computed) {
          throw path.buildCodeFrameError("Computed method/property decorators are not yet supported.");
        }
        const property = _core.types.isLiteral(node.key) ? node.key : _core.types.stringLiteral(node.key.name);
        const target = path.isClass() && !node.static ? buildClassPrototype({
          CLASS_REF: name
        }).expression : name;
        if (_core.types.isClassProperty(node, {
          static: false
        })) {
          const descriptor = path.scope.generateDeclaredUidIdentifier("descriptor");
          const initializer = node.value ? _core.types.functionExpression(null, [], _core.types.blockStatement([_core.types.returnStatement(node.value)])) : _core.types.nullLiteral();
          node.value = _core.types.callExpression(state.addHelper("initializerWarningHelper"), [descriptor, _core.types.thisExpression()]);
          WARNING_CALLS.add(node.value);
          acc.push(_core.types.assignmentExpression("=", _core.types.cloneNode(descriptor), _core.types.callExpression(state.addHelper("applyDecoratedDescriptor"), [_core.types.cloneNode(target), _core.types.cloneNode(property), _core.types.arrayExpression(decorators.map((dec) => _core.types.cloneNode(dec.expression))), _core.types.objectExpression([_core.types.objectProperty(_core.types.identifier("configurable"), _core.types.booleanLiteral(true)), _core.types.objectProperty(_core.types.identifier("enumerable"), _core.types.booleanLiteral(true)), _core.types.objectProperty(_core.types.identifier("writable"), _core.types.booleanLiteral(true)), _core.types.objectProperty(_core.types.identifier("initializer"), initializer)])])));
        } else {
          acc.push(_core.types.callExpression(state.addHelper("applyDecoratedDescriptor"), [_core.types.cloneNode(target), _core.types.cloneNode(property), _core.types.arrayExpression(decorators.map((dec) => _core.types.cloneNode(dec.expression))), _core.types.isObjectProperty(node) || _core.types.isClassProperty(node, {
            static: true
          }) ? buildGetObjectInitializer({
            TEMP: path.scope.generateDeclaredUidIdentifier("init"),
            TARGET: _core.types.cloneNode(target),
            PROPERTY: _core.types.cloneNode(property)
          }).expression : buildGetDescriptor({
            TARGET: _core.types.cloneNode(target),
            PROPERTY: _core.types.cloneNode(property)
          }).expression, _core.types.cloneNode(target)]));
        }
        return acc;
      }, []);
      return _core.types.sequenceExpression([_core.types.assignmentExpression("=", _core.types.cloneNode(name), path.node), _core.types.sequenceExpression(exprs), _core.types.cloneNode(name)]);
    }
    function decoratedClassToExpression({
      node,
      scope
    }) {
      if (!hasClassDecorators(node) && !hasMethodDecorators(node.body.body)) {
        return;
      }
      const ref = node.id ? _core.types.cloneNode(node.id) : scope.generateUidIdentifier("class");
      return _core.types.variableDeclaration("let", [_core.types.variableDeclarator(ref, _core.types.toExpression(node))]);
    }
    var visitor = {
      ExportDefaultDeclaration(path) {
        const decl = path.get("declaration");
        if (!decl.isClassDeclaration()) return;
        const replacement = decoratedClassToExpression(decl);
        if (replacement) {
          const [varDeclPath] = path.replaceWithMultiple([replacement, _core.types.exportNamedDeclaration(null, [_core.types.exportSpecifier(_core.types.cloneNode(replacement.declarations[0].id), _core.types.identifier("default"))])]);
          if (!decl.node.id) {
            path.scope.registerDeclaration(varDeclPath);
          }
        }
      },
      ClassDeclaration(path) {
        const replacement = decoratedClassToExpression(path);
        if (replacement) {
          const [newPath] = path.replaceWith(replacement);
          const decl = newPath.get("declarations.0");
          const id = decl.node.id;
          const binding = path.scope.getOwnBinding(id.name);
          binding.identifier = id;
          binding.path = decl;
        }
      },
      ClassExpression(path, state) {
        const decoratedClass = applyEnsureOrdering(path) || applyClassDecorators(path) || applyMethodDecorators(path, state);
        if (decoratedClass) path.replaceWith(decoratedClass);
      },
      ObjectExpression(path, state) {
        const decoratedObject = applyEnsureOrdering(path) || applyObjectDecorators(path, state);
        if (decoratedObject) path.replaceWith(decoratedObject);
      },
      AssignmentExpression(path, state) {
        if (!WARNING_CALLS.has(path.node.right)) return;
        path.replaceWith(_core.types.callExpression(state.addHelper("initializerDefineProperty"), [_core.types.cloneNode(path.get("left.object").node), _core.types.stringLiteral(path.get("left.property").node.name || path.get("left.property").node.value), _core.types.cloneNode(path.get("right.arguments")[0].node), _core.types.cloneNode(path.get("right.arguments")[1].node)]));
      },
      CallExpression(path, state) {
        if (path.node.arguments.length !== 3) return;
        if (!WARNING_CALLS.has(path.node.arguments[2])) return;
        if (path.node.callee.name !== state.addHelper("defineProperty").name) {
          return;
        }
        path.replaceWith(_core.types.callExpression(state.addHelper("initializerDefineProperty"), [_core.types.cloneNode(path.get("arguments")[0].node), _core.types.cloneNode(path.get("arguments")[1].node), _core.types.cloneNode(path.get("arguments.2.arguments")[0].node), _core.types.cloneNode(path.get("arguments.2.arguments")[1].node)]));
      }
    };
    var _default = exports2.default = visitor;
  }
});

// node_modules/@babel/plugin-proposal-decorators/lib/index.js
var require_lib9 = __commonJS({
  "node_modules/@babel/plugin-proposal-decorators/lib/index.js"(exports2) {
    "use strict";
    Object.defineProperty(exports2, "__esModule", {
      value: true
    });
    exports2.default = void 0;
    var _helperPluginUtils = require_lib();
    var _pluginSyntaxDecorators = require_lib2();
    var _helperCreateClassFeaturesPlugin = require_lib8();
    var _transformerLegacy = require_transformer_legacy();
    var _default = exports2.default = (0, _helperPluginUtils.declare)((api, options) => {
      api.assertVersion("^7.0.0-0 || ^8.0.0-0");
      var {
        legacy
      } = options;
      const {
        version
      } = options;
      if (legacy || version === "legacy") {
        return {
          name: "proposal-decorators",
          inherits: _pluginSyntaxDecorators.default,
          visitor: _transformerLegacy.default
        };
      } else if (!version || version === "2018-09" || version === "2021-12" || version === "2022-03" || version === "2023-01" || version === "2023-05" || version === "2023-11") {
        api.assertVersion("^7.0.2 || ^8.0.0-0");
        return (0, _helperCreateClassFeaturesPlugin.createClassFeaturePlugin)({
          name: "proposal-decorators",
          api,
          feature: _helperCreateClassFeaturesPlugin.FEATURES.decorators,
          inherits: _pluginSyntaxDecorators.default,
          decoratorVersion: version
        });
      } else {
        throw new Error("The '.version' option must be one of 'legacy', '2023-11', '2023-05', '2023-01', '2022-03', or '2021-12'.");
      }
    });
  }
});

// entry.js
module.exports = require_lib9().default;
