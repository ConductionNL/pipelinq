# Proposal: pipelinq-activityservice-gate27

## Problem

Hydra mechanical gate-27 (`no-phantom-cross-app-rpc`, ADR-041) hard-fails on
`lib/Service/ActivityService.php` with **8 findings**, all of the form:

```
lib/Service/ActivityService.php:<n> rule=phantom-foundation-call
detail=publish()-removed from OpenRegister ObjectService; use the RBAC publicatiedatum model
```

Gate-27 has two sub-cases. Sub-case 2 ("phantom foundation call", Rule E) keeps
a denylist of OpenRegister public methods that were **removed** — seeded with
the deprecate-published-metadata removals `ObjectService::publish` /
`depublish` and the `ObjectEntity` `@self.published` accessors. The real
incident it guards is opencatalogi calling `$objectService->publish()` after OR
deleted that method: every gate stayed green and the call only died at runtime.

The 8 pipelinq findings are **false positives**. Rule E matched the method name
`publish` on **any receiver**, with no receiver-type discrimination. But none of
the 8 sites is an OpenRegister `ObjectService->publish()` call:

- **7 sites** are `$this->publish(...)` — calls to ActivityService's *own*
  private `publish()` helper (lines 85, 121, 153, 184, 215, 246, 275).
- **1 site** is `$this->activityManager->publish($event)` (line 328) — NC's
  **first-party** Activity stream API, `OCP\Activity\IManager::publish()`.

This activity-publishing path is **live**: the `oc_activity` table holds real
pipelinq rows (`lead_created`, `request_created`, …) emitted through it. It is
intentionally NOT the OR object-publishing surface and intentionally NOT
`x-openregister-notifications` (the file's own docblock, lines 35–42, explains
why: the Activity stream is a distinct surface the notification runtime does not
feed). There is nothing to migrate in pipelinq — the gate rule is wrong.

The gate's own design (archived change `2026-06-15-hydra-gate-no-phantom-cross-app-rpc`)
states the acceptance bar: **"zero-false-positive against the live fleet"**, and
its full-tree acceptance table records **pipelinq = 0 hits**. Rule E was added in
a *later* commit (`1dfc905c`) than the acceptance run (`b8ca1327`) and regressed
that guarantee: the receiver-blind `publish()` match now false-flags every method
literally named `publish` across pipelinq, decidesk, shillinq, procest, and
softwarecatalog.

## Solution

Fix the **gate**, not the leaf (the SKILL's own escape hatch: *"When a method
appears here that is genuinely still on OR's surface, the denylist is wrong — fix
the denylist, not the leaf."* — and here the calls are not OR calls at all).

Add **receiver discrimination** to Rule E's active denylist half in
`hydra/scripts/lib/check_phantom_cross_app_rpc.py`: a denylisted method name
(`publish` / `depublish` / `setPublished` / `getPublished` / `setDepublished` /
`getDepublished`) only fails the gate when its **receiver is an OpenRegister
handle** (`$objectService` / `$objectEntity` / `$object` / `$or` — the same
`_OR_HANDLE_RE` tokens the forward-contract half already uses). The removed
accessors only ever fatal on the OR ObjectService / ObjectEntity surface, so the
same-named method on any other receiver — NC's `$activityManager->publish()`, an
app's own `$this->publish()`, an app-local `$publicationService->publish()` — is
a different method and is no longer flagged.

This restores the design's full-tree zero-false-positive guarantee while keeping
the real `$objectService->publish()` / `$objectEntity->setPublished()` incident
caught. No pipelinq runtime code changes: `ActivityService.php` is already
correct and stays byte-for-byte unchanged.

## Scope

- `hydra/scripts/lib/check_phantom_cross_app_rpc.py` — Rule E receiver guard
  (`_DENY_CALL_WITH_RECEIVER_RE` + `_OR_RECEIVER_TOKENS`), module docstring.
- `hydra/scripts/lib/test_check_phantom_cross_app_rpc.py` — new unittest file
  (15 cases) locking in the receiver discrimination + Rules A–D.
- `pipelinq/openspec/changes/pipelinq-activityservice-gate27/` — this change.

No schema, route, controller, or service changes. No frontend changes.
`lib/Service/ActivityService.php` is unchanged.
