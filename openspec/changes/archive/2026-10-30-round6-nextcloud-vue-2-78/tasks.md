# Tasks: round6-nextcloud-vue-2-78

## 1. Library version

- [x] 1.1 `package.json` and `package-lock.json`: @conduction/nextcloud-vue ^2.78.0
- [x] 1.2 `tests/schemas/app-manifest-v2.schema.json`: copied from the library (it differed)

## 2. No override request without buildiq

- [x] 2.1 Move `loadPersistedOverrides()` from `src/main.js` to `src/services/persistedOverrides.js`
- [x] 2.2 Return the bundled manifest without a request unless buildiq or openbuild is enabled for the user (`useAppStatus`)
- [x] 2.3 `tests/vitest/persistedOverrides.spec.js`: no GET when buildiq is absent from `OC.appswebroots`; one GET, delta applied, when buildiq or openbuild is there
  - Verify: the "no request" test fails on the old function (the GET is sent once)

## 3. Verification

- [x] 3.1 Full checks once: check:strict, vitest, lint, format, test:l10n, validate-manifest, build
- [x] 3.2 Live on :8099: no buildiq request on the client list and client detail, services list Status shows labels, a failed form save shows no "Saved"
