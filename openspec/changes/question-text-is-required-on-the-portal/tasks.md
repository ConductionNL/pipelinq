# Tasks: question-text-is-required-on-the-portal

- [x] **T1**: `PortalContributionProvider::questionActions()` names `requiredFields` on both actions
  - PHPUnit `PortalContributionProviderTest::testCitizenAndClientMayAskAndReply`
- [ ] **T2**: Live check on the Zuiddrecht rehearsal: the dossier question form reads "Uw vraag" without "(niet verplicht)" and refuses an empty question before sending
