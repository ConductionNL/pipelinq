<!--
SPDX-License-Identifier: EUPL-1.2
SPDX-FileCopyrightText: 2026 Conduction B.V.
-->

# AVG right-of-deletion procedure

This procedure describes how Pipelinq MDM executes a data subject's **right to
erasure** ("right to be forgotten") across the master entity and all of its source
records, while preserving a proof-of-compliance audit trail.

## Legal basis

- **AVG / GDPR Article 17** — Right to erasure ("right to be forgotten").
- **AVG / GDPR Article 5(2)** — Accountability: the controller must be able to
  demonstrate compliance, which is why the audit trail is retained (in redacted
  form) rather than destroyed.

Erasure obligations may be limited by overriding retention duties (e.g. fiscal
record-keeping). Confirm with the data-protection officer before approving a
request that touches financially relevant records.

## Workflow overview

MDM uses a **soft-delete → 30-day cooling-off → hard-delete** workflow, each step
admin-gated and server-driven (`AVGWorkflowService`, REQ-MDM-009).

```
initiate  ─▶  steward review  ─▶  approve & execute (soft-delete + anonymize)
   │                                          │
   │                                          ▼
   │                                 +30-day cooling-off
   │                                          │
   └──────────────────────────────────▶  confirm hard-delete (permanent)
```

## Step-by-step

### 1. Initiate

```
POST /api/mdm/avg-workflow/initiate
{ "masterEntityId": "<id>", "gdprRequestId": "<request-ref>" }
```

Records the GDPR request (date, requester proof method, legal basis), sets the
task status to `pending-review`, and notifies the assigned steward. Requires an
admin user; `masterEntityId` and `gdprRequestId` are both mandatory (`400` if
missing).

### 2. Steward review

The steward verifies the requester's identity and the legal basis, then either
approves or rejects. Rejections are recorded with a reason in the AVG admin panel
(**MDM → AVG right-of-deletion**).

### 3. Approve and execute (soft-delete + anonymization)

```
POST /api/mdm/avg-workflow/approve
{ "masterEntityId": "<id>", "gdprRequestId": "<request-ref>" }
```

Atomically, MDM:

1. fetches the master entity and all linked source records,
2. anonymizes each source record — `rawAttributes` and `mappedAttributes` are
   replaced with `{anonymized}` and `withdrawn = true`,
3. sets the master entity `status = soft-deleted` and appends a *geanonimiseerde*
   note to `gdprNotes`,
4. enqueues `soft-delete` sync items for every downstream app,
5. schedules the hard-delete callback for **+30 days**,
6. writes an audit-trail entry with the GDPR request id and approval timestamp.

### 4. Hard-delete confirmation (after cooling-off)

After 30 days the daily job surfaces the entity in
`GET /api/mdm/avg-workflow/candidates`. An admin confirms permanent erasure:

```
POST /api/mdm/avg-workflow/{masterEntityId}/hard-delete
```

This permanently deletes the master entity and all source records, marks the
related sync-queue items completed, and writes the final audit entry
("Hard-deleted by admin user X on date Y").

## Audit-trail redaction

The audit trail is **not** destroyed — it is redacted so it still proves *that* and
*when* actions occurred, without exposing personal data:

- attribute values → `[***]`,
- names → `[***]`,
- master-entity id values → hashed / opaque.

Event structure and timestamps remain visible. For example, a pre-erasure merge
event reads: *"Merge on 2025-03-15 — [***] into [***]"* — the date and action are
intact, the identifiers are opaque. This satisfies the accountability requirement
of Article 5(2) without retaining erased personal data.

## Data retention during cooling-off

| Phase | Master entity | Source records | Downstream apps |
|---|---|---|---|
| After approve | `soft-deleted`, anonymized notes | `{anonymized}`, `withdrawn = true` | `soft-delete` sync enqueued |
| Days 0–30 | recoverable by admin (reverse soft-delete) | retained anonymized | awaiting acknowledgment |
| After confirm | permanently deleted | permanently deleted | sync items completed |

The 30-day cooling-off period guards against erroneous or contested erasure
requests. Personal data is already anonymized on day 0; the cooling-off concerns the
*record skeleton* and downstream coordination, not the personal data itself.

## See also

- [MDM administrator guide](../admin/master-data-management.md)
