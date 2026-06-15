# Design: contract-renewal-tracking

## Context

Pipelinq has clients (`client-management`, NC-addressbook-synced contacts via ContactSyncService), a deal pipeline (`lead-management` + `pipeline`), a product/service catalog, My Work, dashboard widgets, and OR-engine notifications. It lacks any recurring-revenue object. The in-flight `customer-portal` change already consumes contracts (`PortalContractService` reading `{contractNumber, startDate, endDate, value, status}`), so the schema below is shaped to satisfy that consumer as-is. Renewals deliberately reuse the existing lead/pipeline machinery — a renewal is just a lead with a contract behind it — so forecasting, automation, and reporting pick renewals up for free.

## Architecture

### Data Layer

#### New Schema: `contract`

| Property | Type | Required | Description |
|---|---|---|---|
| `contractNumber` | string | Yes | Human-readable unique number, auto-generated `C-{year}-{seq}` on create, admin-overridable. Read by customer-portal. |
| `clientRef` | string (FK) | Yes | UUID of the `client` object. Client identity stays in client-management / NC addressbook — never duplicated here. |
| `title` | string | Yes | Display title, e.g. "Support & maintenance 2026". |
| `lineItems` | array | No | `[{ productRef, description, quantity, unitValue }]` — productRef references the existing product/service catalog. |
| `billingInterval` | string | Yes | `monthly`, `quarterly`, `annual`, `one-off`. |
| `valuePerInterval` | number | Yes | Contract value per billing interval (ex VAT). |
| `currency` | string | No | ISO 4217, default EUR. |
| `startDate` | string (date) | Yes | Contract start. |
| `endDate` | string (date) | No | Contract end. Null = indefinite (still gets notice-deadline reminders if noticePeriodDays set, anchored to anniversary). |
| `autoRenew` | boolean | No | True = renews automatically unless cancelled before notice deadline; affects reminder copy, not the renewal-lead motion. |
| `noticePeriodDays` | integer | No | Days before endDate by which notice must be given. |
| `status` | string | Yes | Lifecycle: `draft`, `active`, `expiring`, `renewed`, `churned`, `cancelled`. |
| `ownerId` | string | Yes | NC user UID of the account manager. Defaults to the client's owner. |
| `renewalLeadRef` | string (FK) | No | UUID of the auto-created renewal lead. |
| `predecessorContractRef` | string (FK) | No | UUID of the contract this one renewed. Builds the renewal chain. |
| `notes` | string | No | Free text. |

OpenRegister built-in fields available on all objects (do NOT redefine): `id`, `uuid`, `uri`, `version`, `createdAt`, `updatedAt`, `owner`, `organization`, `register`, `schema`, `relations`, `files`, `auditTrail`, `notes`, `tasks`, `tags`, `status`, `locked`.

#### Lifecycle state machine

```
draft ──activate──> active ──window entered──> expiring ──renewal lead won──> renewed
  │                   │                            │──lead lost / endDate passed──> churned
  └─cancel─> cancelled└──────────cancel───────────>cancelled
```

Guarded transitions live in `ContractService` (app logic per ADR-022 — not a pass-through wrapper): `renewed` requires a won renewal lead; `expiring` is only set by the engine; `cancelled` requires a reason. Terminal states: `renewed`, `churned`, `cancelled`.

### Renewal Engine

Nightly background job (`RenewalWindowJob`, registered via the valid bootstrap pattern — not `IRegistrationContext::registerJob`):

1. **Window detection** — for each `active` contract with an endDate: window start = endDate − max(noticePeriodDays, configured default renewal lead time, 60 days fallback). If today ≥ window start → transition to `expiring`.
2. **Renewal-lead creation** — on the `active → expiring` transition, create one lead via the existing lead-management path: title "Renewal: {contract.title}", value = annualized contract value, client = clientRef, assignee = ownerId, tag `renewal`, link contract ↔ lead (`renewalLeadRef`). Idempotent: an `expiring` contract that already has a renewalLeadRef is skipped.
3. **Reconciliation** — when the renewal lead reaches won: contract → `renewed`, draft successor contract (copy fields, startDate = old endDate + 1 day, status `draft`, predecessorContractRef set). Lead lost, or endDate passes while `expiring`: contract → `churned`.
4. **Notice-deadline reminder** — when today ≥ endDate − noticePeriodDays and the contract is `expiring`, ensure a My Work entry exists for the owner ("Notice deadline approaching" for autoRenew contracts: "auto-renews unless cancelled by {date}").

Notifications are NOT dispatched imperatively: the register JSON carries x-openregister-notifications rules (ADR-031) on the `contract` schema for the `expiring` transition, targeting `ownerId`. The job only mutates objects; the OR engine notifies.

### Recurring-Revenue Aggregation

`RecurringRevenueService` (read-side, on the fly):
- **MRR normalization**: monthly = value; quarterly = value/3; annual = value/12; one-off = excluded. Statuses counted: `active` + `expiring`.
- **Roll-ups**: company MRR/ARR (ARR = MRR×12), per-client recurring value, MRR delta per period (new − churned), renewal rate per period = renewed ÷ (renewed + churned) among contracts whose window closed in the period, churned MRR per period.
- Surfaces: dashboard MRR KPI card + "Renewals due" widget (expiring contracts ordered by endDate), recurring-revenue block in pipeline insights.

### Frontend

- **Contracts list** (filter by status/owner/client, renewal-window highlight) and **contract detail** (lifecycle, line items, renewal chain via predecessor/renewal-lead links, linked client).
- **Client view Contracts tab** — per-client contracts + recurring value summary (klantbeeld integration).
- **Create/edit modal** in `src/modals/` (modal-isolation gate), NcSelect with `inputLabel`, NcDateTimePicker for dates.
- Plain object reads/writes through `useObjectStore` against the OR API per ADR-022; only guarded transitions and engine actions go through app endpoints.

## Decisions

1. **Renewals are leads, not a new pipeline object** — reuses stage management, automation triggers, forecasting (`forecast-roll-up-and-categories` sees renewal leads natively), and avoids a parallel kanban. The `renewal` tag enables filtered views.
2. **Successor contract drafted, not auto-activated** — renewal terms usually change (indexation, scope); a human confirms the draft.
3. **One-off contracts allowed but excluded from MRR** — keeps the contract list complete (e.g., fixed-price projects) without polluting recurring metrics.
4. **Schema shaped for customer-portal's existing reader** — field names match what `PortalContractService` expects; the portal change needs no rework.
5. **No billing integration in MVP** — value fields are CRM-truth, not invoice-truth; the Shillinq bridge is a future change in the established series.
6. **English i18n source keys** throughout (`t('pipelinq', 'Renewals due')`), Dutch in `l10n/nl.json`.

## Risks / Trade-offs

- **Date math correctness** (notice vs. lead-time vs. timezone) — all dates are civil dates evaluated in the instance timezone; the nightly job is idempotent so a missed run self-heals next night.
- **Lead/contract drift** — a user could delete the renewal lead; the engine treats a missing renewalLeadRef on an `expiring` contract as "recreate" (idempotency key = contract UUID + endDate).
- **MRR on-the-fly cost** — contract volume for MKB tenants is small (hundreds); compute live, revisit snapshots only if profiling demands (forecast snapshots already exist for the deal side).
- **Indefinite contracts** — anniversary-anchored reminders are an approximation; explicit endDates are recommended in docs.
