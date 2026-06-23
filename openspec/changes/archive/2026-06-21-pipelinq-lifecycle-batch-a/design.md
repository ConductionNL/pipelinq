# Design — Pipelinq Lifecycle Batch A

## The pattern: `allowedTransitions` → `x-openregister-lifecycle`

### OR's declaration shape (discovered in openregister)

`x-openregister-lifecycle` lives in a schema's `configuration` block. Validated by
`OCA\OpenRegister\Service\Lifecycle\LifecycleAnnotationValidator`:

- `field` (required) — name of a `string` property that carries an `enum` of all states.
- `initial` (required) — must be one of the enum values.
- `transitions` (required) — either a **keyed map** `action => {from, to}` or an
  **array** of `{from, to}` objects. `from` may be a single state string or a list.
  Optional per-transition `requires` (guard DI tag), `authorization` (group/role gate),
  `description`.
- `final` / `terminal` (optional) — terminal states.

### OR's enforcement contract (the load-bearing finding)

`OCA\OpenRegister\Listener\LifecycleValidationListener` is registered in OR's
`Application::register()` against `ObjectUpdatingEvent`, which `ObjectService::saveObject()`
dispatches. On every save where the lifecycle field changes, the listener:

1. finds a transition whose `to` equals the new value AND whose `from` contains the old value;
2. if none matches → rejects (`lifecycle-invalid-transition`, HTTP 422);
3. runs any `requires` guard / `authorization` gate.

So **enforcement is automatic on save** — *provided the mutation goes through
`saveObject()`* (the documented trust boundary; raw mapper/SQL writes bypass it).
`TransitionEngine` is action-sugar over the same annotation for callers that want a
named-action endpoint; it is **not** required for enforcement.

Reference example: decidesk `lib/Settings/decidesk_register.json` declares the Decision
and DecisionStage lifecycles this way (array-of-`{from,to}` form, `states`/`terminal` keys).

### Why a thin PHP guard stays (do not silently weaken enforcement)

OR enforces on **save**, but:

- The rejection envelope (event errors → 422/403) differs from each app's current
  contract: `CallbackService::validateStatusTransition()` returns `{valid, reason}` and the
  controller maps an illegal transition to **HTTP 400** with a specific message;
  `WalkInQueueService::assertTransitionAllowed()` throws **`InvalidArgumentException`** with a
  specific message *before* save. Callers and unit tests depend on these.
- Some validation happens **before** `saveObject()` is reached (the callback controller
  pre-checks the transition and returns 400 without ever attempting the save).

Therefore the guard methods are kept, but their **source of truth** moves from a hardcoded
map into the schema's `x-openregister-lifecycle` declaration. OR's listener stays as
defense-in-depth at the persistence boundary. This is strictly stronger than today (one
declaration, two enforcement points) and preserves the exact error contract.

## The shared helper: `SchemaLifecycleGraph`

`lib/Service/Lifecycle/SchemaLifecycleGraph.php` reads the bundled register JSON
(`lib/Settings/pipelinq_register.json` for Task, `lib/Settings/register.d/*.json` for the
fragment schemas), extracts `configuration.x-openregister-lifecycle.transitions`, and
returns the `from → [to,…]` adjacency map. It normalises both the keyed-map and
array-of-objects shapes and the string-or-list `from`. If the declaration is missing or
unreadable it returns an empty map; each caller falls back to its prior hardcoded graph so a
broken/absent file never regresses behavior. Pure file-read + json_decode — no OR runtime
dependency, so it works in unit tests without a container.

## Per-candidate plan

| Service | Schema (file) | Graph source | Stays in PHP |
|---|---|---|---|
| CallbackService | Task (`pipelinq_register.json`, already declared) | schema | claim/completion timestamps, notifications, `{valid,reason}` envelope, HTTP 400 |
| WalkInQueueService | walkInTicket (`register.d/45-appointment-booking.json`, ADD) | schema | `InvalidArgumentException` messages, `actualServedAt`/`assignedResourceId` stamps |
| LoyaltyProgrammeService | loyaltyProgramme (`register.d/70-loyalty-program.json`, ADD) | schema | `validateForActivation` guard (date-range, ≥1 rule, ≥1 redemption — NOT declarative), activation log |

`concept→actief` is the only edge the loyalty service enforces today; the schema also
declares the obvious operational edges (pause/resume/end) for completeness and to make the
walkInTicket/loyalty schemas first-class lifecycle citizens that OR enforces on save.

## ADRs

- ADR-031 declarative-first (schema-declared state machines).
- ADR-022 apps consume OR abstractions rather than re-deriving them.
