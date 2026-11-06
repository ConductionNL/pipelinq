# Tasks: round4-nextcloud-vue-2-76

## 1. Library version

- [x] 1.1 `package.json` and `package-lock.json`: @conduction/nextcloud-vue ^2.76.0
- [x] 1.2 `tests/schemas/app-manifest-v2.schema.json`: copied from the library (it differed)

## 2. Add labels

- [x] 2.1 Remove `src/utils/widgetAddLabels.js` and its call in `src/main.js`; the library translates `content.addLabel`
- [x] 2.2 `tests/vitest/readableValues.spec.js`: the library's CnObjectListWidget turns "Add contact person" into "Contactpersoon toevoegen" through the app's translate, and main.js no longer translates it first
  - Verify: fails on 2.73.1 (the label comes back as written) and on the old main.js

## 3. Verification

- [x] 3.1 Full checks once: check:strict, vitest, lint, format, test:l10n, check:manifest, build
- [x] 3.2 Live on :8099: the client lock URL, the lead Related card, the client Add button
