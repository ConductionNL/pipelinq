// Default-unwrap shim for @nextcloud/axios.
//
// @nextcloud/axios's built entry marks `__esModule` and does
// `exports.default = <axios instance>`. Aliasing `@nextcloud/axios$` straight at
// that file hands nc-vue's dist a namespace `{ default, isCancel, … }`; nc-vue's
// interop then double-wraps it so `axios.default` is the namespace (no
// `.interceptors`), and `@nextcloud/password-confirmation`'s
// `addPasswordConfirmationInterceptors(axios)` reads `axios.interceptors.request`
// → undefined, blanking the app at mount. Re-export the default so
// `require('@nextcloud/axios')` returns the instance itself.
// Relative path (not the `@nextcloud/axios/dist/index.cjs` package subpath) so
// webpack does NOT apply the package's `exports` field, which only declares the
// `import` condition and would 404 the CJS entry under webpack's require conditions.
const mod = require('../node_modules/@nextcloud/axios/dist/index.cjs')
module.exports = mod.default || mod
