# Tasks — AVG status lifecycle to OR declarative mechanic

## 1. Schema declaration

- [x] 1.1 Add `configuration.x-openregister-lifecycle` to the `avgVerzoek` schema
      (`lib/Settings/register.d/40-avg-verzoeken.json`): `field: status`,
      `initial: ingediend`, `final: [afgerond, gearchiveerd]`, transition map
      mirroring the current permit set exactly (7 working states → any of 9;
      terminals have no outgoing edges).
- [x] 1.2 Validate the register JSON parses.

## 2. Service refactor (graph moves; legal stays)

- [x] 2.1 Inject `SchemaLifecycleGraph` into `AvgRequestService` (defaulted helper).
- [x] 2.2 Add `assertStatusTransitionAllowed()` + `allowedTransitions()` deriving the
      graph from the schema (mirrored `FALLBACK_TRANSITIONS` constant for unreadable
      declaration; never regresses).
- [x] 2.3 In `update()`, validate a status change against the schema-derived graph,
      raising the same `OCSBadRequestException` for an illegal move.
- [x] 2.4 Keep the read-only check (afgerond/gearchiveerd) ahead of the graph check
      so its verbatim message is preserved.
- [x] 2.5 Confirm intake deadline/reference, archive `retentieTot`, retention-guarded
      delete + DPO override, and allowed-FIELDS enforcement all stay in PHP unchanged.

## 3. Pinning test (legal safety net)

- [x] 3.1 Transition-matrix test: every legal transition (7 working × 9 targets)
      succeeds and persists; unknown target rejected; terminal-state updates rejected
      with the preserved message.
- [x] 3.2 Test asserting the graph is sourced from the schema declaration.

## 4. Verification

- [x] 4.1 `composer lint` + `phpcs` (lib-only) clean on changed PHP.
- [x] 4.2 Full PHPUnit green (1584 ≥ baseline).
- [x] 4.3 Live on :8080: re-imported register (force), OR shows
      `x-openregister-lifecycle` on avgVerzoek (schema 1201: field=status,
      initial=ingediend, final=[afgerond,gearchiveerd], 9 transitions); legal
      `ingediend->in-behandeling` succeeded (HTTP 200); illegal `afgerond->in-behandeling`
      rejected by OR's LifecycleValidationListener (HTTP 422, lifecycle-invalid-transition)
      — defense-in-depth confirmed.
