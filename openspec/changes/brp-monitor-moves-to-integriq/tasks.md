# Tasks: brp-monitor-moves-to-integriq (pipelinq)

Spec only in this PR. Build after integriq `brp-connection-monitor-from-pipelinq` and keepiq `connection-certificate-expiry-for-integriq`. Tier V1.

## 1. Move the settings

- [ ] 1.1 Repair step `MoveBrpConnectionToIntegriq` as in design section 2: no overwrite, secrets only through integriq's event, keys cleared only after confirmation, report per key.
  - spec_ref: `specs/brp-lookup/spec.md#requirement-the-brp-connection-settings-move-to-integriq-once`
  - files: `lib/Repair/MoveBrpConnectionToIntegriq.php`, `appinfo/info.xml`, `tests/Unit/Repair/MoveBrpConnectionToIntegriqTest.php`
  - test: `vendor/bin/phpunit --no-coverage --filter MoveBrpConnectionToIntegriqTest`

## 2. Look up through integriq only

- [ ] 2.1 Remove the direct OAuth2 and mTLS path from `HaalCentraalClient`; a missing or broken source gives the message from design section 4.
  - spec_ref: `specs/brp-lookup/spec.md#requirement-brp-person-lookup-goes-through-integriq-only`
  - files: `lib/Service/HaalCentraalClient.php`, its test

## 3. Remove the monitor

- [ ] 3.1 Remove `BrpHealthCheckJob`, `BrpMonitorJob`, `BrpController::monitor` and its route, `BrpMonitor.vue`, its manifest page and registry entry, and the connection fields in `BrpAdminController`; clear `brp.monitor_report` and `brp.cert_health`.
  - spec_ref: `specs/brp-lookup/spec.md#requirement-pipelinq-does-not-monitor-the-brp-connection`
  - acceptance: `git grep -n "BrpMonitor\|BrpHealthCheck\|brp.cert_path" lib src appinfo` is empty

## 4. Verify

- [ ] 4.1 `COMPOSER_PROCESS_TIMEOUT=0 composer check:strict`, `npm run lint`, `npm run test:l10n` once before push.
- [ ] 4.2 Live check: a BRP lookup on the customer workplace succeeds through integriq with the pipelinq keys cleared.
