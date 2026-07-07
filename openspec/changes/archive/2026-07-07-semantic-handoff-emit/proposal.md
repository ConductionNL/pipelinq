# Proposal: semantic-handoff-emit

kind: feature — pipelinq's **emit side** of the ADR-051 semantic-object-handoff chains: (a) request/ticket → `ns#Case`, (b) quote/contract → the shillinq invoicing chain (`ns#Quote` / `ns#Contract` / `ns#Invoice`). The cross-app contract (semantic kinds, `x-openregister-handoff` dialect, resolver behaviour) is authored in parallel in `hydra/openspec/changes/semantic-object-handoff` + ADR-051; this change covers only what pipelinq declares and emits.

## Problem

1. **The Request-to-Case Bridge is a claim without code.** `README.md:58` and `appinfo/info.xml` advertise "Request-to-Case Bridge — hand off requests directly to Procest when ready"; the `request-management` spec has a full Request-to-Case Conversion requirement (status `converted`, `caseReference`, read-only after conversion) — and there is **zero** implementing code. No conversion action, no Procest call, nothing. The 2026-07-05 audit confirmed this against HEAD.

2. **Point-to-point handoff is the wrong shape anyway.** Hard-wiring pipelinq→procest would repeat the integration anti-pattern the owner has ruled out: cross-app handoff happens via schema.org-style shared **semantic primitives** (ADR-051), resolved at runtime through OpenRegister — pipelinq emits to *whichever installed app implements* `https://openregister.app/ns#Case` (procest today, possibly zaakafhandelapp tomorrow), and hides the action when nobody does. Pipelinq already ships the precedent for the declaration mechanics: the ADR-048 `referenceSemanticType: "https://openregister.app/ns#Vendor"` annotation in `lib/Settings/register.d/92-product-supply-master.json`.

3. **The commercial chain has the same gap on the emit side.** Shillinq's abstract-order-primitive work expects upstream apps to declare their quote/contract schemas against `ns#Quote` / `ns#Contract` and hand off to the `ns#Invoice` implementer. Pipelinq's `contract` schema (live, `lib/Settings/register.d/96-contract-renewal.json`) and `quote` schema (specced in `product-catalog-quoting`, not yet registered — no quote schema exists in any register fragment at HEAD) declare nothing.

## Solution

**(a) Request → ns#Case emit**

- A "Convert to case" action on the request detail view (available from `in_progress`, per the existing lifecycle) that invokes OR's handoff engine: resolve the installed implementer of `https://openregister.app/ns#Case` via OR's `SemanticTypeResolver` (verified present on OR origin/development: `lib/Service/SemanticTypeResolver.php`), map request fields per the hydra contract through the new `x-openregister-handoff` dialect, and create the target case.
- On success: request status → `converted` (the enum value already exists in the `request` schema), provenance stored in `caseReference` (target object UUID + implementing app), detail view shows the cross-app link, converted requests become read-only on core fields — exactly what the spec always promised.
- Graceful degradation: when no installed app implements `ns#Case`, the action is **hidden** (not disabled-with-error).
- This finally backs the README/info.xml "Request-to-Case Bridge" claim with real code; `align-claims-and-first-hour` re-points the claim wording at this change until it ships.

**(b) Quote / contract emit declarations + invoice handoff**

- `contract` schema (96-contract-renewal.json) gains the `implements https://openregister.app/ns#Contract` declaration (ADR-051 dialect form as defined by the hydra change).
- The `quote` schema declaration is added to the `product-catalog-quoting` **spec** (schema still unbuilt — the delta ensures it is born implementing `ns#Quote` when that Enterprise change is built).
- An "Send to invoicing" handoff from an accepted quote / active contract to whichever app implements `ns#Invoice` (shillinq's abstract-order-primitive), with field mappings per the hydra contract (design.md carries pipelinq's mapping tables). Same hidden-when-unimplemented degradation.

## Scope

- Backend: a thin `SemanticHandoffService` (resolve-implementer + invoke-handoff wrapper around OR's engine, OR-absent safe), request conversion endpoint/action wiring, quote/contract emit wiring
- Schemas: `x-openregister-handoff` / implements declarations on `request` (emitter), `contract`; spec-level declaration for `quote`
- Frontend: "Convert to case" action + converted-state UI on request detail; "Send to invoicing" action on contract (and quote view when built); actions hidden without an implementer
- Specs: `request-management` (conversion requirement modified to the semantic mechanism), `contract-renewal-tracking` (implements declaration + invoice handoff), `product-catalog-quoting` (implements declaration on the future quote schema)

**Depends on:** hydra `semantic-object-handoff` change + ADR-051 (parallel authoring — kinds `ns#Case`, `ns#Quote`, `ns#Contract`, `ns#Invoice`; `x-openregister-handoff` dialect), OR `SemanticTypeResolver` (origin/development), ADR-048 (`ns#Vendor` precedent), shillinq abstract-order-primitive (consumer side of `ns#Invoice`).

## Out of Scope

- The cross-app contract itself (semantic kind definitions, dialect schema, resolver rules) — owned by hydra `semantic-object-handoff` / ADR-051
- Procest's / shillinq's **receive** side (implements-`ns#Case` / implements-`ns#Invoice` declarations and intake mapping)
- Building the quote engine (`product-catalog-quoting` remains its own Enterprise change; here we only mark its schema contract)
- Reverse-direction sync (case status back into the request) — provenance link only; feedback flows are a future ADR-051 iteration
- README/info.xml claim wording — `align-claims-and-first-hour`

## Success Criteria

- With procest installed (implements `ns#Case`): "Convert to case" on an `in_progress` request creates a case via OR's handoff engine, sets status `converted` + `caseReference`, renders a working cross-app link, and core fields become read-only
- With no `ns#Case` implementer installed: the action is absent from the request detail UI and the conversion endpoint returns a clean not-available error
- Failed target-creation leaves the request status unchanged
- `contract` schema carries the `ns#Contract` declaration; the quoting spec requires `ns#Quote` on the future quote schema; an active contract can be handed to the `ns#Invoice` implementer with the mapped fields
- All field mappings match the hydra `semantic-object-handoff` contract (verify against the hydra change at apply time — it is being authored in parallel)
