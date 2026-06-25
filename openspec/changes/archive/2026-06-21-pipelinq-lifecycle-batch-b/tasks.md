# Tasks — Pipelinq Lifecycle Batch B (money / higher-risk)

## 1. Shared helper
- [x] 1.1 Add `SchemaLifecycleGraph::configurationFor(slug, key)` — a generic
  reader for an arbitrary `configuration.<key>` annotation (same safe file-scan +
  json_decode contract as `lifecycleFor()`; returns null when undeclared/unreadable).

## 2. Contract (money path)
- [x] 2.1 Add `x-openregister-lifecycle` to the contract schema in
  `register.d/96-contract-renewal.json` declaring the **exact reachability the
  service permits today** (non-terminal draft/active/expiring → every valid target;
  renewed/churned/cancelled terminal).
- [x] 2.2 Derive `ContractService::assertTransitionAllowed()`'s terminal set + graph
  from the schema (via the helper), keeping the conditional guards in PHP
  (`expiring`-engine-only, `renewed`-requires-won-lead, `cancelled`-requires-reason)
  with inline comments. RenewalEngineService + ContractController unchanged.

## 3. Booking (money path)
- [x] 3.1 Add `x-openregister-lifecycle` to the booking schema in
  `register.d/45-appointment-booking.json` (pending-deposit/confirmed sources; the
  five terminal states) mirroring the prior `allowedTransitions()` map.
- [x] 3.2 Derive `BookingService::allowedTransitions()` from the schema via
  `fullAdjacencyFor()` (terminal states as empty-array keys), keeping the prior map
  as `FALLBACK_TRANSITIONS` and `assertTransitionAllowed()`'s messages unchanged.
  statusHistory append left in PHP (later audit seam).

## 4. Forecast (high-risk: revert-after-write KEPT in PHP)
- [x] 4.1 Add the open/closed partition + default to the lead schema under the
  app-namespaced `configuration.x-pipelinq-forecast-lifecycle` key in
  `register.d/50-forecast.json`, documenting that OR cannot enforce a second
  lifecycle field (status already owns `x-openregister-lifecycle`).
- [x] 4.2 Make `ForecastDealService` read the partition/default from that schema
  annotation (closed/open/default) with the prior constants as fallback; the
  decisions and the listeners' revert-after-write stay byte-for-byte unchanged.
- [x] 4.3 Add `defaultBehavior: "falsy"` to `forecast_category` so OR applies the
  create-default exactly as the listener did; retain `DealCreatedListener` as an
  idempotent backstop (documented).

## 5. Tests (EXTRA — money paths)
- [x] 5.1 SchemaLifecycleGraphTest: contract full adjacency + terminal, booking full
  adjacency == prior map, forecast `configurationFor` resolves the partition,
  `configurationFor` null for absent key.
- [x] 5.2 ContractServiceTest: legal graph edges accepted; terminal immutability via
  schema-derived terminal set; fallback when schema unreadable. (Existing conditional
  guards still pass.)
- [x] 5.3 BookingServiceTest: `allowedTransitions()` equals the prior map (schema
  source); legal edges accepted + terminal edge rejected with the unchanged message.
- [x] 5.4 ForecastDealServiceTest: partition sourced from schema (closed→open lock,
  closed→closed allowed, default=pipeline); fallback when schema unreadable.

## 6. Quality + verify
- [x] 6.1 `composer lint` + `phpcs --warning-severity=0` clean on changed `lib/`.
- [x] 6.2 Full PHPUnit suite green (baseline 1537; now 1549 — +12 Batch B tests).
- [x] 6.3 Register JSON parses; re-import on :8080 (`loadSettings(force: true)`);
  confirm OR shows the lifecycle on contract (6 transitions) + booking (6) + the
  `forecast_category` falsy default on the lead schema.
- [x] 6.4 LIVE-verify (money paths): contract + booking legal/illegal PHP guards on
  :8080; OR listener defense-in-depth on a real contract save (renewed→active
  rejected). Explicit live-vs-unit per candidate in design.md.
