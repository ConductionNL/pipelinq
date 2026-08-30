# Design: pipelinq-activityservice-gate27

## The gate-27 rule (what it forbids / accepts + escape hatch)

Gate-27 (`no-phantom-cross-app-rpc`, ADR-041) enforces two sub-cases via
`hydra/scripts/lib/check_phantom_cross_app_rpc.py`; it is diff-scoped (ADR-020)
and counts one stdout line per hard finding.

**Sub-case 1 — phantom cross-app *command* RPC** (Rules A–D): an app invoking a
sibling Conduction app's business action through a non-existent abstraction —
`->getLeaf(` (A), `->call('<fleetAppId>', …)` (B), the non-existent FQN
`OCA\OpenRegister\Service\IntegrationService` (C), or a session-less server-side
`IClientService` POST/GET to a sibling app's `linkToRoute('<app>.…')` (D). The
sanctioned replacement is a typed `IEventDispatcher` event (the ADR-041
RequestedEvent + result-slot + ConcludedEvent recipe).

**Sub-case 2 — phantom *foundation* call** (Rule E): a leaf calling an
OpenRegister public method that has been **removed**. Active half = a denylist
`OR_REMOVED_METHODS` = {`publish`, `depublish`, `getPublished`, `getDepublished`,
`setPublished`, `setDepublished`} (the deprecate-published-metadata removals).
The sanctioned replacement is the RBAC `publicatiedatum` model (anon publication
= `publicatiedatum<=$now` + public group), not re-adding the removed method.

**Escape hatch / accepted:** comment-only lines are skipped. The SKILL documents
the canonical Rule E exception: *"When a method appears here that is genuinely
still on OR's surface, the denylist is wrong — fix the denylist, not the leaf."*
There is no per-site `@exempt` annotation for Rule E — the rule is meant to be
precise enough not to need one, so a false positive is fixed at the detector.

## What the 8 publish() sites actually do — case (b)

`lib/Service/ActivityService.php` publishes Pipelinq lifecycle events to the
**Nextcloud Activity stream** via `OCP\Activity\IManager`. The 8 gate hits:

| Line(s) | Call | What it is |
|---|---|---|
| 85,121,153,184,215,246,275 | `$this->publish(...)` | ActivityService's **own** private `publish()` helper (the 7 public `publishCreated/Assigned/StageChanged/StatusChanged/NoteAdded/DealWon/DealLost` methods delegate to it) |
| 328 | `$this->activityManager->publish($event)` | NC **first-party** `IManager::publish()` — writes the assembled `IEvent` to the Activity stream |

This is **case (b): a legitimate first-party NC API flagged as a false
positive.** It is neither (a) phantom cross-app RPC nor (c) OR object publishing
that should use RBAC `publicatiedatum`:

- Not (a): no `getLeaf`, no `->call('<app>')`, no `IntegrationService`, no
  cross-app HTTP. The receiver is NC's own `IManager`, injected via DI.
- Not (c): the Activity stream is a *distinct surface* from OR object
  publication. The file docblock (lines 35–42) already records that this is
  intentionally NOT migrated to `x-openregister-notifications` because the
  annotation runtime emits `nc-notification` channel notifications and does not
  feed the Activity stream — replacing it would silently drop the activity feed.

Rule E fired only because its active half matched the bare method name `publish`
on any receiver, with no check that the receiver is an OpenRegister handle.

## The chosen fix + why

**Add receiver discrimination to Rule E's active denylist half.** A denylisted
method name now fails only when its receiver is an OpenRegister handle —
`$objectService`, `$objectEntity`, `$object`, `$or` (the `_OR_RECEIVER_TOKENS`
set, mirroring the `_OR_HANDLE_RE` the forward-contract half already uses). New
regex `_DENY_CALL_WITH_RECEIVER_RE` captures `(receiver)->(method)(` so the
detector can require the OR receiver before flagging.

Why this and not a leaf annotation or a leaf code change:

1. **Root cause is the gate.** The 8 sites are not OR calls; the only correct
   place to fix a receiver-blind method-name match is the detector. The SKILL's
   own guidance is "fix the denylist, not the leaf."
2. **Fleet-wide.** The same false positive flags `$activityManager->publish()`
   and app-local `$publicationService->publish()` across decidesk, shillinq,
   procest, softwarecatalog. A pipelinq-local suppression would leave 4 other
   apps broken; the gate fix clears all of them.
3. **Restores the documented guarantee.** The gate's design acceptance bar is
   "zero-false-positive against the live fleet" with "pipelinq = 0 hits"; Rule E
   regressed it after the acceptance run. The guard re-establishes it.
4. **No global weakening.** The real `$objectService->publish()` /
   `$objectEntity->setPublished()` incident still hard-fails (proven by the new
   test). The removed OR accessors are instance methods on the OR service/entity,
   never static and never on another receiver, so anchoring on the OR receiver
   loses no real coverage. (Static `ObjectService::publish()` appears only in
   comments fleet-wide — already excluded — so dropping the `::` arm of the old
   match is safe.)

## Visibility-preserved proof

- **No pipelinq code changed.** `lib/Service/ActivityService.php` is byte-for-byte
  unchanged; the published activity content, type, subject, parameters,
  `objectType`/`objectId`, and `affectedUser` (recipient/visibility) are
  identical.
- **Unit:** `tests/Unit/Service/ActivityServiceTest.php` — 8/8 pass (10
  assertions); the publish content/visibility assertions hold.
- **Live (:8080):** created a `lead` object in the pipelinq register (id 446) →
  `ObjectEventDispatcher` → `ActivityService::publishCreated` →
  `IManager::publish()`. `oc_activity` rows for `app='pipelinq'` went 117 → 118;
  the new row is `type=pipelinq_assignment, subject=lead_created,
  object_type=lead`, matching the shape of every pre-existing pipelinq activity.
  Activity publishing is intact.
- **Detector:** new `test_check_phantom_cross_app_rpc.py` proves the real
  `$objectService->publish()` / `$objectEntity->setPublished()` /
  `$object->setDepublished()` / `$this->or->getPublished()` are still caught,
  and `$activityManager->publish()` / `$this->publish()` /
  `$publicationService->publish()` / `$oriPublicationService->publish()` are not.

## Gate status after

`bash hydra/scripts/run-hydra-gates.sh` on pipelinq: **ALL 27 GATES GREEN**,
including gate-27 PASS (was 8 findings), gate-6 (orphan-auth) PASS, gate-16
(spec-coverage) PASS — no regression.
