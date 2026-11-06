# Tasks: segment-last-contact-signal

- [x] 1.1 `SegmentSignalService` publishes `pipelinqLastContactAt`, the latest contact moment of the customer
  - Files: lib/Service/Marketing/SegmentSignalService.php, lib/Service/Marketing/LastContactReader.php
  - Test: tests/Unit/Service/Marketing/SegmentSignalServiceTest.php
- [x] 1.2 The customer audience offers Last contact (date) and Place
  - Files: src/services/segmentFieldOptions.js
  - Test: tests/vitest/segmentFieldOptions.spec.js
- [ ] 1.3 Live: build the board's segment on PqSegmentBouwer and read its size
