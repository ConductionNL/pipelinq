# Tasks: latest-state-probe

- [x] 1 `IntegriqConsentClient::probeOne()` and the probe argument on the decision event.
  - spec_ref: `specs/consent-in-integriq/spec.md#requirement-lateststate-asks-integriq-as-a-probe-req-cii-007`
  - files: `lib/Service/IntegriqConsentClient.php`, `tests/Stubs/Integriq/Event/OutboundSendDecisionRequestedEvent.php`
- [x] 2 `ConsentService::latestState()` uses `probeOne()`.
  - files: `lib/Service/ConsentService.php`, `tests/Unit/Service/ConsentServiceTest.php`
  - acceptance: the latestState event is a probe, a send's event is not. Red before.
  - test: `vendor/bin/phpunit --no-coverage --filter ConsentServiceTest`
- [ ] 3 Live: opening a contact's consent writes no row in integriq's opt-out log; a send writes one.
