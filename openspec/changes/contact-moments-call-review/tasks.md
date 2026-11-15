# Tasks: contact-moments-call-review

## 1. Transcript and summary

- [x] 1.1 `ticket` gains `transcript` (read only), `callSummary`, `transcriptStatus`
  - Verify: register import logs no `PARTIAL IMPORT`; `npm run check:schema-l10n` exit 0
- [x] 1.2 Queue `CallTranscriptionJob` from `attachRecording()` behind `call_transcription_enabled`
  - Verify: PHPUnit on `CtiService::attachRecording()` with the switch off and on
- [x] 1.3 `CallTranscriptionJob` + `CallTranscriptionService`: download, `core:audio2text`, write transcript, delete the temporary file in `finally`, statuses for expired recording and missing provider
  - Verify: PHPUnit with a mocked task manager for done, failed and unavailable; assert the temp file is gone in every case
- [x] 1.4 Summary through hermiq's lazy resolve; empty without hermiq
  - Verify: PHPUnit with and without a hermiq stub
- [x] 1.5 TicketDetail summary card (editable) and transcript panel
  - Verify: Playwright `tests/e2e/call-transcript.spec.ts` with a seeded transcribed contact moment
  - Built as a Call tab on TicketDetail (shown while the ticket has a recording): `record-notes` on `callSummary` and a `facts` card with the transcript status, the retention date, the transcript and a link to the recording. The Playwright run is a live check and is not done here.

## 2. Review

- [x] 2.1 Schemas `callScorecard` and `callReview` with the authorization block of D3 and a weighted `total`
  - Verify: PHPUnit on the total; an OpenRegister read as another agent is refused
  - Status: schemas in `register.d/99-zzzzzzzzzzz-call-review.json`; `CallReviewSaveListener` sets `total` (and `agreed`, see 2.4) on every save; PHPUnit covers both. The live read as another agent is not run here.
- [x] 2.2 Scorecard admin section in pipelinq admin settings
  - Verify: Vitest on the criteria editor
  - `ScorecardManager.vue` with the rules in `src/utils/scorecardCriteria.js`, and a Call transcription section with the `call_transcription_enabled` switch; `tests/vitest/scorecardManager.spec.js`.
- [x] 2.3 Call reviews page and the review form, for group `pipelinq-call-review`
  - Verify: Playwright: a reviewer scores a call on three criteria and shares it
  - `src/manifest.d/84-call-reviews.json`: Call reviews and Recorded calls index pages and CallReviewDetail (scores and coaching points as field lists, Share with agent); Review this call on TicketDetail for the group. The Playwright run is a live check and is not done here.
- [x] 2.4 My Work shows shared reviews; Agree per coaching point sets `agreedAt`, all agreed sets status `agreed`
  - Verify: Playwright as the agent agrees both points and the review shows agreed to the reviewer
  - Reviews of your calls on My Work; Agree calls `POST /api/call-reviews/{id}/coaching-points/{index}/agree` (`CallReviewController`, `CoachingPointAgreement`, PHPUnit), and the save listener sets `agreed`. The Playwright run is a live check and is not done here.
  - Status: the server half is built (a shared review whose points all carry `agreedAt` is stored as `agreed`, and the agent may update the review); the My Work list and the Agree buttons are not.

## 3. Docs

- [x] 3.1 `docs/Features/call-review.md`: switching transcription on, where audio goes, who sees a review
  - Verify: docs build exit 0; `npm run test:l10n` exit 0
