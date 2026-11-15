# Tasks: question-detail-on-the-portal

- [x] **T01**: `QuestionDetailService::timeline()` and `dossierItems()` (REQ-QDP-001, REQ-QDP-002). Verification: PHPUnit `tests/Unit/Service/Portal/QuestionDetailServiceTest.php`.
- [x] **T02**: Provider: detail block, `timeline`, `itemList`, `questionTimeline`, `questionDossierItems` (REQ-QDP-001). Verification: PHPUnit `PortalContributionProviderTest` ("the questions page shows the selected question", "the detail methods delegate to the service").
- [x] **T03**: `replyToQuestion` attached to `myQuestions` with `rowField` and `rowWhen` (REQ-QDP-003). Verification: PHPUnit `PortalContributionProviderTest` ("citizen and client may ask and reply").
- [x] **T04**: `portalAnswers` on the ticket, register 1.9.0, ticket 1.4.0, appended by `CustomerReplySection` (REQ-QDP-001). Verification: PHPUnit `DossierQuestionSchemaTest`, vitest `tests/vitest/dossierQuestionSections.spec.js`.
- [x] **T05**: `openspec validate question-detail-on-the-portal --strict`.
