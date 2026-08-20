# Semantic Object Handoff (Request → Case, Contract → Invoice)

Pipelinq hands work off to other Conduction apps by **semantic kind**, not by
app name. It emits against shared schema.org-style primitives
(`https://openregister.app/ns#<Kind>`) and lets OpenRegister resolve *whichever
installed app implements that kind* at runtime. When no installed app implements
the kind, the action is simply **hidden** — never a dead button. This is the
ADR-051 mechanism; pipelinq is an emitter (it declares and triggers), the OR
handoff engine owns delivery.

## Request → Case ("Convert to case")

The `request` schema declares an `x-openregister-handoff` emit entry
`convert-to-case` targeting `https://openregister.app/ns#Case`. On a request in
status `in_progress`, a "Convert to case" action:

1. is rendered only when an installed app implements `ns#Case` (procest today,
   possibly zaakafhandelapp tomorrow) — resolved via OR's `SemanticTypeResolver`;
2. invokes OR's handoff engine, which maps the request through the target's
   binding and creates the case under the caller's RBAC;
3. on success sets the request `status = converted` and stores the target case
   UUID in `caseReference`; the detail view shows the cross-app link and the
   "converted" notice, and core fields become read-only;
4. on failure leaves the request untouched.

Field mapping (per the hydra `semantic-object-handoff` contract / OR
`HandoffKindContracts` — mandatory `ns#Case` fields fully mapped):

| request field | → ns#Case field | expression |
|---|---|---|
| `title` | `title` | from |
| `description` | `summary` | from (default "") |
| `channel` | `channel` | from (default "onbekend") |
| — | `source` | template `pipelinq:request:{{title}}` (scalar URN; UUID-level provenance additionally lives in the OR handoff relations) |
| `client` | `requester` | semanticRef (ADR-048 reference) |

This finally backs the README/info.xml "Request-to-Case Bridge" claim with real,
kind-addressed code — no hard-wired procest call.

## Contract → Invoice ("Send to invoicing")

The `contract` schema declares `implements ns#Contract` (so downstream apps can
discover pipelinq contracts by kind) and an `x-openregister-handoff` emit entry
`send-to-invoicing` targeting `https://openregister.app/ns#Invoice` (shillinq's
abstract-order-primitive implements it today). On an `active` contract, a "Send
to invoicing" action hands the contract to the invoice implementer and records
the created invoice UUID in `invoiceReference` on the contract. Hidden when no
`ns#Invoice` implementer is installed; a failed handoff leaves the contract
unchanged.

Field mapping (mandatory `ns#Invoice` fields fully mapped):

| contract field | → ns#Invoice field | expression |
|---|---|---|
| `clientRef` | `counterparty` | semanticRef |
| `currency` | `currency` | from (default "EUR") |
| `valuePerInterval` | `totalAmount` | from |
| `contractNumber` | `source` | template `pipelinq:contract:{{contractNumber}}` |
| `lineItems` | `lines` | from |
| `endDate` | `dueDate` | from |

## Quote → Invoice (spec-bound, Enterprise)

The `quote` schema is not yet registered (Enterprise tier, unbuilt). The
`product-catalog-quoting` capability spec now **requires** that, when the quote
schema is built, it declare `implements ns#Quote` and offer a "Send to invoicing"
emit from status `geaccepteerd` to the `ns#Invoice` implementer — so quotes are
born into the ADR-051 contract. No declaration ships until the schema itself
does (this change introduces no phantom quote schema).

## Notes

- **Kind-addressed only** — no `procest` / `shillinq` literal appears in any
  emit code path; the implementing app id appears only in the returned
  provenance / OR relations after resolution.
- **Provenance is a link, not a sync** — converted requests are terminal; case
  status does not flow back in this change (a future ADR-051 iteration).
- The cross-app contract itself (kind definitions, `x-openregister-handoff`
  dialect, resolver rules) is owned by the hydra `semantic-object-handoff`
  change + ADR-051; the receive side (implements-`ns#Case` / implements-`ns#Invoice`
  intake) is owned by procest / shillinq.
