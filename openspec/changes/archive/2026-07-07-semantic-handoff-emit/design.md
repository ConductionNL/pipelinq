# Design: semantic-handoff-emit

## Context

ADR-051 (authored in parallel, `hydra/openspec/changes/semantic-object-handoff`) defines cross-app handoff via shared semantic primitives: schemas **declare** what they implement or emit against `https://openregister.app/ns#<Kind>` URIs; OR's `SemanticTypeResolver` resolves which installed app implements a kind at runtime; a new `x-openregister-handoff` dialect describes the emit mapping. Pipelinq already ships the ADR-048 declaration precedent — `referenceSemanticType: "https://openregister.app/ns#Vendor"` on the vendor reference in `register.d/92-product-supply-master.json`, with the same "unfillable when no app supplies it" degradation this change generalises to actions.

Because the hydra contract is being authored in parallel, every dialect shape below is **indicative and subordinate**: at apply time, verify field names and dialect syntax against the hydra change at HEAD — do not trust this file over the contract (and do not trust remembered wave-claims; verify against HEAD).

## Architecture

### SemanticHandoffService (thin, OR-absent safe)

```
lib/Service/SemanticHandoffService.php
  hasImplementer(string $kindUri): bool        // SemanticTypeResolver lookup, lazy container resolve
  handoff(string $kindUri, array $payload): HandoffResult  // invoke OR handoff engine per x-openregister-handoff
```

- Resolves OR services lazily through the container (OrGdprBridge pattern): app loads without OR; `hasImplementer` returns false, actions hide.
- NO queueing, NO retry, NO app-side handoff log — the OR engine owns delivery semantics (same ADR-045 stance as `retire-mdm-sync-queue`).
- The initial-state payload for the frontend includes per-kind implementer availability so actions render (or not) without an extra roundtrip.

### Emit chain (a): request → ns#Case

Trigger: "Convert to case" on request detail, allowed from `in_progress` only (existing lifecycle rule). Flow:

1. `hasImplementer('https://openregister.app/ns#Case')` — false ⇒ action hidden, endpoint 409s cleanly.
2. `handoff(...)` maps the request per the hydra contract and creates the target case in the implementer's register via OR.
3. Success ⇒ request `status = converted` (existing enum value), `caseReference = {targetUuid, implementerAppId}` provenance; failure ⇒ request untouched.
4. Detail view: converted state renders the cross-app deep link and locks core fields (spec behaviour that predates this change, now actually implemented).

Indicative field mapping (subject to the hydra contract):

| pipelinq `request` | ns#Case handoff field | Notes |
|---|---|---|
| `title` | `name` | |
| `description` | `description` | |
| `clientRef` → client | `subject` | party the case is about |
| `contactRef` | `applicant` | |
| `priority` | `priority` | |
| `channel` | `channel` | intake channel provenance |
| uuid + app id | `provenance.source` | reverse link back to the request |

### Emit chain (b): quote / contract → invoicing

- Declarations: `contract` schema (96-contract-renewal.json) gains `implements ns#Contract`; the future `quote` schema is specced to be born with `implements ns#Quote` (schema does not exist at HEAD — declaration lives in the `product-catalog-quoting` spec delta until that Enterprise change builds it).
- Handoff: "Send to invoicing" from an accepted quote / active contract targets the `ns#Invoice` implementer (shillinq abstract-order-primitive). Indicative mapping (hydra contract governs):

| pipelinq source | ns#Invoice handoff field | Notes |
|---|---|---|
| contract `lineItems` / quote line items | `lines[]` | product ref, qty, unit price |
| `valuePerInterval` + `billingInterval` / quote `total` | `amount`, `interval` | one-off quotes: no interval |
| `currency` (contract) / app currency config | `currency` | |
| `clientRef` | `customer` | counterparty |
| `contractNumber` / `quoteNumber` | `provenance.sourceNumber` | |
| uuid + app id | `provenance.source` | |

- Degradation: without an `ns#Invoice` implementer the action hides — identical UX rule as chain (a).

## Decisions

1. **Kind-addressed, never app-addressed** — no `procest`/`shillinq` literals in emit code paths; app ids appear only in provenance after resolution. This is the whole point of ADR-051 over a bespoke bridge.
2. **Reuse the existing `converted` status + `caseReference` field** — the request lifecycle already reserved them; no schema surgery on `request` beyond the emit declaration.
3. **Hidden, not disabled** — an action a user can never complete is noise; hiding matches the ADR-048 "unfillable field" precedent. The endpoint still guards server-side (no UI-only enforcement).
4. **Provenance is a link, not a sync** — converted requests are terminal + read-only; case status does not flow back in this change.
5. **Spec-level declaration for quote** — declaring on an unbuilt schema in `register.d/` would ship a phantom schema; the requirement rides in the quoting spec instead, so building quotes without the declaration fails verify.

## Risks / Trade-offs

- **Parallel-authored contract drift** — the hydra dialect may change shape before apply. Mitigation: mappings here are marked indicative; the tasks require verification against the hydra change at HEAD before wiring.
- **Multiple implementers of one kind** — resolver policy (first-wins? priority?) is ADR-051's to define; pipelinq consumes whatever it returns and surfaces the chosen implementer in the action label.
- **Read-only converted requests** — irreversible from the UI by design (existing spec); admins can still correct via OR object surface.
