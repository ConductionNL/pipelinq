# Tasks: contact-moments-call-review

## 1. Transcript and summary

- [ ] 1.1 `ticket` gains `transcript` (read only), `callSummary`, `transcriptStatus`
  - Verify: register import logs no `PARTIAL IMPORT`; `npm run check:schema-l10n` exit 0
- [ ] 1.2 Queue `CallTranscriptionJob` from `attachRecording()` behind `call_transcription_enabled`
  - Verify: PHPUnit on `CtiService::attachRecording()` with the switch off and on
- [ ] 1.3 `CallTranscriptionJob` + `CallTranscriptionService`: download, `core:audio2text`, write transcript, delete the temporary file in `finally`, statuses for expired recording and missing provider
  - Verify: PHPUnit with a mocked task manager for done, failed and unavailable; assert the temp file is gone in every case
- [ ] 1.4 Summary through hermiq's lazy resolve; empty without hermiq
  - Verify: PHPUnit with and without a hermiq stub
- [ ] 1.5 TicketDetail summary card (editable) and transcript panel
  - Verify: Playwright `tests/e2e/call-transcript.spec.ts` with a seeded transcribed contact moment

## 2. Review

- [ ] 2.1 Schemas `callScorecard` and `callReview` with the authorization block of D3 and a weighted `total`
  - Verify: PHPUnit on the total; an OpenRegister read as another agent is refused
- [ ] 2.2 Scorecard admin section in pipelinq admin settings
  - Verify: Vitest on the criteria editor
- [ ] 2.3 Call reviews page and the review form, for group `pipelinq-call-review`
  - Verify: Playwright: a reviewer scores a call on three criteria and shares it
- [ ] 2.4 My Work shows shared reviews; Agree per coaching point sets `agreedAt`, all agreed sets status `agreed`
  - Verify: Playwright as the agent agrees both points and the review shows agreed to the reviewer

## 3. Docs

- [ ] 3.1 `docs/Features/call-review.md`: switching transcription on, where audio goes, who sees a review
  - Verify: docs build exit 0; `npm run test:l10n` exit 0
