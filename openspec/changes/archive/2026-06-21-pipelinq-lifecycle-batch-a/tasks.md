# Tasks — Pipelinq Lifecycle Batch A

## 1. Shared helper
- [x] 1.1 Add `lib/Service/Lifecycle/SchemaLifecycleGraph.php` — reads a schema's
  `configuration.x-openregister-lifecycle.transitions` from the bundled register JSON and
  returns a normalised `from → [to,…]` adjacency map (handles keyed-map + array shapes and
  string-or-list `from`; returns `[]` on missing/unreadable).

## 2. Callback (Task schema — already declared)
- [x] 2.1 Confirm Task schema `x-openregister-lifecycle` matches the prior `ALLOWED_TRANSITIONS`.
- [x] 2.2 Replace `CallbackService::ALLOWED_TRANSITIONS` usage with a graph derived from the
  schema (via the helper), keeping `validateStatusTransition()` `{valid,reason}` contract and
  the controller's HTTP 400 on illegal transition.

## 3. Walk-in queue
- [x] 3.1 Add `x-openregister-lifecycle` to the walkInTicket schema (waiting→called/abandoned,
  called→served/abandoned; served/abandoned terminal) in `register.d/45-appointment-booking.json`.
- [x] 3.2 Derive `WalkInQueueService::allowedTransitions()` from the schema declaration, keeping
  `assertTransitionAllowed()` throwing the same `InvalidArgumentException` messages.

## 4. Loyalty programme
- [x] 4.1 Add `x-openregister-lifecycle` to the loyaltyProgramme schema (concept→actief,
  actief↔gepauzeerd, actief/gepauzeerd→beeindigd) in `register.d/70-loyalty-program.json`.
- [x] 4.2 Make `LoyaltyProgrammeService::activate()` assert `concept→actief` via the schema graph
  before the `validateForActivation` business guard; keep `validateForActivation` (date-range,
  ≥1 rule, ≥1 redemption) in PHP — document why it cannot be declarative.

## 5. Tests
- [x] 5.1 CallbackServiceTest: legal transitions still pass, illegal still fail with "not allowed";
  assert the graph is sourced from the schema declaration.
- [x] 5.2 WalkInQueueServiceTest: legal transitions succeed, illegal raise `InvalidArgumentException`
  with the same message; add a schema-derived-graph assertion.
- [x] 5.3 LoyaltyProgrammeServiceTest (new): `concept→actief` allowed once guards pass; an illegal
  source state is rejected; guard failures still raise the same `RuntimeException`.
- [x] 5.4 SchemaLifecycleGraphTest (new): the helper returns the expected adjacency maps for all
  three schemas.

## 6. Quality + verify
- [x] 6.1 `composer lint` + `phpcs --warning-severity=0` clean on changed `lib/`.
- [x] 6.2 Full PHPUnit suite green (baseline 1525).
- [x] 6.3 Register JSON parses; re-import on :8080; live-verify a legal + an illegal transition.
