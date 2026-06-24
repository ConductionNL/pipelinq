# Design — Adopt OpenRegister's canonical GDPR semantics in the AVG workflow

## Status: AUTHORIZED BEHAVIOURAL CHANGE (not a behaviour-preserving refactor)

The product owner has authorized pipelinq's AVG (GDPR data-subject-rights) workflow to **adopt
OpenRegister's generic EU/GDPR mechanics** in place of its earlier divergent NL approximations.
This document is the canonical record of that change: what changed (old → new), why it is the
correct floor, the retention invariant that MUST hold, and what deliberately stayed as the Dutch
overlay. The change directly REVERSES the earlier `REQ-AVG-014` consumption boundary, which had
codified the divergence and forbidden delegating to OR.

## Why adopt OR's semantics

| Leg | Old (pipelinq, divergent) | New (OR, adopted) | Why the new floor is correct |
|-----|---------------------------|-------------------|------------------------------|
| Deadline | NL **30 days** base + **60 days** extension | EU **1 month** base + **2 months** extension (`DataSubjectDeadline`) | "One month … extendable by two further months" is the literal AVG **art-12(3)** statutory text. 30/60 days was a calendar approximation that ran *short* in 31-day months. |
| Discovery | `ObjectService::findAll` with a **`bsn` equality filter** | OR **NER-index** discovery (`findSubjectData`: `openregister_entities ⋈ entity_relations`, RBAC + tenant scoped) | A single-column `bsn` filter only finds objects that happen to carry a literal `bsn` column. The NER index ties **every** object to the subject's detected PII (BSN / email / name), so discovery is materially more complete. |
| Erasure | **Named-field SHA-256** of `customerName`/`customerEmail`/`customerPhone` | OR **legal-hold-aware field-level pseudonymise** (`erase(..., 'pseudonymise')`; matching scalars → `[erased]`, row retained, held rows skipped) | OR's erase is generic (scrubs every matching scalar, not three hard-coded fields), reversible only via the immutable audit trail, and — critically — **honours legal hold / immutability**, so it cannot violate a retention floor. |

The mechanism: a thin `OrGdprBridge` (`lib/Service/Avg/OrGdprBridge.php`) lazily resolves OR's
`DataSubjectRequestService` and `DataSubjectDeadline` through the DI container — the same pattern
the AVG repository already uses for `ObjectService` — and exposes `computeDueAt` / `extend` /
`findSubjectData` / `assembleAccessExport` / `erase`. When OR is absent every verb degrades to a
safe empty/false result (and `computeDueAt`/`extend` fall back to the same 1-/2-month maths
locally), so the app still loads without OR.

## The CRITICAL retention invariant (must hold; verified live)

**Invariant:** OR's erase MUST NOT delete a row that NL law requires retained. The 7-year
Boekhoudplicht booking retention is a hard legal floor — the booking **row** stays, only the PII
is removed.

**Why it holds by construction:**

1. pipelinq always erases in OR's **`pseudonymise`** mode (`OrGdprBridge::ERASE_MODE_PSEUDONYMISE`),
   never `whole-object`. Pseudonymise mode is a field-level **value overwrite followed by
   `saveObject`** — it never calls delete. The row therefore always survives; only matching scalar
   PII becomes the `[erased]` token.
2. Independently, OR's `DataSubjectRequestService::erase` runs a **retention guard**
   (`RetentionService::hasActiveLegalHold` + `validateNotImmutable`) on every matched object. An
   object under an active legal hold or in an immutable archival status is reported in the **`held`**
   bucket and is **never mutated**.

**Live proof (on :8080, against the deployed OR capability):**

- A booking `ObjectEntity` with `retention.legalHold.active = true` (reason "Boekhoudplicht 7
  jaar") → `RetentionService::hasActiveLegalHold` returns **`true`** → reported `held`, row kept.
- A booking with no hold → `hasActiveLegalHold` returns **`false`** → its PII is erasable.
- `DataDeletionService::pseudonymizeCustomerBookings` summary reports `{bookings: <erased>,
  held: <held>}`: only unheld bookings are erased; held bookings survive.

If a future caller ever requested `whole-object` mode on a Boekhoudplicht-retained register, that
would breach this floor — the bridge hard-codes `pseudonymise` to prevent it, and any future
change to that MUST stop and re-confirm the floor.

## The escalation chain stays in pipelinq (OR does not own it)

OR owns the legal **deadline** (1 month / 2 months) but NOT the Dutch operational escalation
chain. `DeadlineTrackerService` keeps the **7-day advance reminder**, the **<72h team-lead
escalation**, and the **breach** detection (stamp request + `termijn-overschreden` TermijnEvent +
FG notification), each idempotent via an existing-TermijnEvent guard so repeated job runs never
double-notify. These now compute FROM the OR-derived EU deadline (`wettelijkeTermijnVerloopt` is
the EU computation). The deadline is normalised to end-of-calendar-day so the citizen keeps the
full final day — the one piece of NL presentation kept on top of the OR mechanic.

## What stayed the Dutch overlay (unchanged)

- AP complaint-reference and AP-escalation dossier wrapper.
- `weigering` / art-23 denial grounds (`DenialService`).
- FG / DPO role model and access scoping (`AvgAccessService`).
- 4-eyes citizen Dutch notification drafts (`AvgNotificationService`).
- BSN / BRP verification, DPIA pattern detection.
- The **5-year dossier retention policy** (`RetentionService::deleteExpiredDossiers`, the
  `retentieTot` cascade) and the **30-day evidence-window schedule** — retention *policies* OR does
  not own. Only the PII-scrubbing *style* aligns on OR's `[erased]` token.
- The OpenConnector federated-source evidence collection, BewijsItem packaging, scope filter, and
  content-hash dedup — the federated overlay on top of OR's NER discovery.
- The export bundle's signing (PAdES-LTV / sha256 fallback), one-time download token, and AP
  dossier — kept on top of OR's `assembleAccessExport`.

## Request-model mapping

`avgVerzoek.artikel` (Dutch overlay) maps to the generic `dataSubjectRequest.type` consumed by
OR: `art-15-inzage → access`, `art-16-rectificatie → rectification`, `art-17-wissing → erasure`,
`art-18-beperking → restriction`, `art-20-portabiliteit → portability`; `geen-avg` → null (not
routed through OR). The avgVerzoek lifecycle (status graph, intake, 4-eyes) is unchanged.

## Out of scope

No controller, route, or frontend change. The avgVerzoek schema, the BSN/BRP path, and the AP
escalation flow are untouched.
