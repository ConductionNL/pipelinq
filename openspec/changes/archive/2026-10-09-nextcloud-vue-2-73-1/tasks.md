# Tasks: nextcloud-vue-2-73-1

## 1. The library version

- [x] 1.1 `package.json` and `package-lock.json`: `@conduction/nextcloud-vue` ^2.73.1
- [x] 1.2 `tests/schemas/app-manifest-v2.schema.json` compared with the one 2.73.1 ships: identical, nothing to re-vendor

## 2. The appVersion define from the library

- [x] 2.1 `webpack.config.js` calls `appVersionDefine` from `@conduction/nextcloud-vue/webpack`
- [x] 2.2 `scripts/appVersionDefine.js` removed; `scripts/readInfoXmlVersion.js` keeps the info.xml reader
- [x] 2.3 `tests/vitest/appVersionDefine.spec.js`: the config calls the library helper with `pipelinq` and the info.xml version
  - Verify: fails on development (the config called its own copy)

## 3. Notification labels

- [x] 3.1 The prop `App.vue` passes (`notificationLabels`) is the prop CnAppRoot 2.73.1 declares
- [x] 3.2 Live: the notification preferences in the user settings show the labels, and the footer shows the installed version
