# Tasks: round5-client-related-and-service-list

## 1. Client Related card

- [x] 1.1 `src/manifest.json`: a `client-related` widget (type `related`) on ClientDetail, laid out beside the Records strip (8 + 4 columns)
- [x] 1.2 `tests/vitest/clientRelatedAndServiceList.spec.js`: the detail pages with a Related card include the client page and the seven the cloud check named; the client card sits beside the Records strip and overlaps nothing
  - Verify: fails on the old manifest (no `related` widget on ClientDetail)

## 2. Services list

- [x] 2.1 `src/services/cellFormatters.js`: `enumLabel` and `yesNo` formatters
- [x] 2.2 `src/manifest.d/80-appointment-booking-admin.json`: Status uses `enumLabel`, Online uses `yesNo`
- [x] 2.3 Tests: the Status cell reads "Active" and the Online cell "Yes" through the library's CnCellRenderer with pipelinq's formatters
  - Verify: fails on the old manifest (Status reads "active", Online reads nothing for true and a dash for false)

## 3. Verification

- [x] 3.1 Full checks once: check:strict, vitest, lint, format, test:l10n, check:manifest
