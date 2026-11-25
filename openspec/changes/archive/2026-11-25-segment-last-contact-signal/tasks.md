# Tasks: segment-last-contact-signal

- [x] 1.1 `SegmentSignalService` publishes `pipelinqLastContactAt`, the latest contact moment of the customer
  - Files: lib/Service/Marketing/SegmentSignalService.php, lib/Service/Marketing/LastContactReader.php
  - Test: tests/Unit/Service/Marketing/SegmentSignalServiceTest.php
- [x] 1.2 The customer audience offers Last contact (date) and Place
  - Files: src/services/segmentFieldOptions.js
  - Test: tests/vitest/segmentFieldOptions.spec.js
- [x] 1.3 Live: build the board's segment on PqSegmentBouwer and read its size
  - Live 25 Nov 2026 (sandbox NC 34 + MariaDB + OpenRegister main, pipelinq development, Zuiddrecht set installed). The board PqSegmentBouwer (identity.conduction.nl, read 25 Nov) draws audience Klanten: Plaats is gelijk aan Zuiddrecht, and a group "Voldoet aan een van de voorwaarden" with Branche Horeca or Branche Detailhandel, and Laatste contact na a date 12 months back (drawn as 7 okt 2025). Built as `POST /api/segments/preview` {entityType customer, AND[place equals Zuiddrecht, OR[industry contains Horeca, industry contains Detailhandel], pipelinqLastContactAt after 2025-11-25]}: valid, estimatedSize 0. Without the Branche group: valid, estimatedSize 1, so the derived last-contact signal is read live.
  - Why 0: no client of the set carries Horeca or Detailhandel; the set's industries are SBI descriptions (Winkels in vlees en vleeswaren, Bloemenwinkel, Sportverenigingen, ...). Data item for the set, not code. The board draws the Branche rule as "Is gelijk aan"; the editor offers only "Contains" for the array field Industry, and the API refuses `equals` on it ("not coercible to type array"): a board difference for 5.1's re-triage.
