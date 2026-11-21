# Tasks: brp-monitor-moves-to-integriq (pipelinq)

Spec only in this PR. Build after integriq `brp-connection-monitor-from-pipelinq` and keepiq `connection-certificate-expiry-for-integriq`. Tier V1.

## 1. Move the settings

- [x] 1.1 Repair step `MoveBrpConnectionToIntegriq` as in design section 2: no overwrite, secrets only through integriq's event, keys cleared only after confirmation, report per key.
  - spec_ref: `specs/brp-lookup/spec.md#requirement-the-brp-connection-settings-move-to-integriq-once`
  - files: `lib/Repair/MoveBrpConnectionToIntegriq.php`, `lib/Event/BrpConnectionHandoverEvent.php` (the string-named event integriq listens for: settings and secrets in, an answer per part out), `appinfo/info.xml`, `tests/Unit/Repair/MoveBrpConnectionToIntegriqTest.php`, `tests/Unit/Event/BrpConnectionHandoverEventTest.php`
  - test: `vendor/bin/phpunit --no-coverage --filter MoveBrpConnectionToIntegriqTest`

## 2. Look up through integriq only

- [x] 2.1 Remove the direct OAuth2 and mTLS path from `HaalCentraalClient`; a missing or broken source gives the message from design section 4.
  - spec_ref: `specs/brp-lookup/spec.md#requirement-brp-person-lookup-goes-through-integriq-only`
  - files: `lib/Service/HaalCentraalClient.php`, its test
  - Built: the token exchange, the mTLS client, the direct `/personen` call, `getCertificateExpiry()`, `BrpOauthMessages` and `TimedResponse` are gone. Without integriq (`isConfigured()` is now `isInstalled('integriq')`) or when its source does not answer, `lookupPersoon()` throws `NO_SOURCE_MESSAGE` with status 503 and makes no call; `BrpController` answers that status with "BRP lookups go through integriq. Ask your administrator to set up the BRP connection there." (en, nl). Tests: `HaalCentraalClientTest`, `HaalCentraalClientGoldenTest`, `BrpLookupWithoutIntegriqTest`.

## 3. Remove the monitor

- [x] 3.1 Remove `BrpHealthCheckJob`, `BrpMonitorJob`, `BrpController::monitor` and its route, `BrpMonitor.vue`, its manifest page and registry entry, and the connection fields in `BrpAdminController`; clear `brp.monitor_report` and `brp.cert_health`.
  - spec_ref: `specs/brp-lookup/spec.md#requirement-pipelinq-does-not-monitor-the-brp-connection`
  - acceptance: `git grep -n "BrpMonitor\|BrpHealthCheck\|brp.cert_path" lib src appinfo` is empty
  - Built: the jobs, the monitor route, `BrpMonitor.vue` and its page were already gone, and `RemoveBrpMonitorState` clears the jobs and the two reports. This change takes the connection fields (`baseUrl`, `oauthEndpoint`, `clientId`, `clientSecret`, `certPath`, `keyPath`, `caBundle`) and the monitor's `healthTimezone` out of `BrpAdminController`; `GET /api/brp/settings` answers `connectionManagedBy: integriq`. `BrpAdminControllerTest`. The acceptance grep still lists two repair steps that must name the old keys and jobs to move or clear them: `MoveBrpConnectionToIntegriq` (reads `brp.cert_path`) and `RemoveBrpMonitorState` (the job class names it unregisters).

## 4. Verify

- [ ] 4.1 `COMPOSER_PROCESS_TIMEOUT=0 composer check:strict`, `npm run lint`, `npm run test:l10n` once before push.
- [ ] 4.2 Live check: a BRP lookup on the customer workplace succeeds through integriq with the pipelinq keys cleared.
