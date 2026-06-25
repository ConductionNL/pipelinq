---
kind: code
references:
  - capability: avg-verzoeken-workflow
  - adr: ADR-022 (apps-consume-or-abstractions)
---

# Proposal: pipelinq-gdpr-to-or-subsystem (Seam 3)

## Problem

Pipelinq carries its own GDPR / AVG / DSAR / retention plumbing — evidence
collection, Art-17 pseudonymisation, two-tier retention, DPIA pattern
detection, consent/opt-out lookups, redaction. OpenRegister (OR) ships a
compliance subsystem of its own — `DsarService`, `AvgRetentionService`,
`RetentionService`, `ArchivalService`, `AuditHashService`. ADR-022 says leaf
apps consume OR abstractions rather than reimplement them. Seam 3 asks whether
pipelinq's GDPR mechanics can be migrated onto OR's subsystem so the two stop
diverging.

This is a **LEGAL-COMPLIANCE** seam: erasure, retention and anonymisation
semantics are regulated (AVG Art 5(1)(e)/15/16/17/20, NL Boekhoudplicht 7-year
retention, RvIG 5-year DSAR-dossier retention, Archiefwet selectielijst). Any
migration that changes *which objects are found*, *which fields are
anonymised*, or *which cut-off is applied* is a compliance regression, not a
refactor. The discipline is identical to the lifecycle "one lifecycle field per
schema" finding: **move only the mechanics OR genuinely owns; STOP rather than
risk changing legal semantics.**

## Investigation outcome (the decision)

After reading both subsystems in full (see `design.md` for the per-service
contract analysis), the conclusion is that **OR's GDPR subsystem is NOT a
drop-in substrate for pipelinq's GDPR mechanics**. The two subsystems address
*different data models with different mechanisms*, and every migration candidate
would alter a legally-load-bearing behaviour. The behaviour-preserving outcome
is therefore to **keep the pipelinq PHP and codify why**, plus harden the one
thing that is genuinely shared (the OR `ObjectService::findAll` query leg) so it
stays inside the documented safe envelope.

Decisive mismatches (full detail in `design.md`):

1. **Subject-find mechanism differs.** OR `DsarService::findObjectsForSubject`
   matches against the `openregister_entities` GdprEntity **PII-detection
   index** (joined via `entity_relations`) and is **hard admin-gated**
   (`assertPrivileged()` throws for any non-admin caller). Pipelinq's finds are
   plain equality filters (`bsn`, `customerId`, `contactId`, `bsnHash`) over its
   **own** registers/schemas via the generic `ObjectService::findAll`, run in
   the booking/AVG-handler context (not necessarily admin) and **without**
   depending on the PII index being populated. Swapping in `DsarService` would
   (a) throw for non-admin callers, (b) silently return different/empty results
   when the PII index is unpopulated, (c) change the match surface from
   "this register's objects for this customer" to "every object OR's PII
   scanner has tagged for this subject". All three change *which records are
   found and erased*. **STOP.**

2. **Retention mechanism differs.** OR `AvgRetentionService::runRetentionPass`
   keys retention on `audit_trails.processing_activity_id` grouped by a
   `Verwerkingsactiviteit` catalog `bewaartermijn` (ISO-8601 duration vs.
   `MAX(created)`). Pipelinq retention is a per-dossier `retentieTot` cut-off
   (5-year RvIG dossier window) and a per-evidence-item `verzameldOp + 30d`
   pseudonymisation window over pipelinq-owned AVG schemas. The keys, the
   subjects, and the cut-off arithmetic are all different. Driving pipelinq
   dossiers through OR's activity-keyed pass would require every pipelinq AVG
   object to carry an OR processing-activity and would silently re-time every
   erasure. **STOP.**

3. **Pseudonymisation policy is pipelinq legal IP, not a mechanic.** Pipelinq's
   `DataDeletionService` deterministically SHA-256-hashes exactly
   `customerName`/`customerEmail`/`customerPhone` and **retains every other
   field and the row itself** (Boekhoudplicht). OR `DsarService::erase…`
   soft-deletes the whole object (`setDeleted(...)`). These are *opposite*
   erasure semantics — field-level pseudonymise-and-keep vs. object-level
   soft-delete. Not interchangeable. **STOP.**

4. **DPIA / dedup / consent / opt-out / redaction** are computed-in-PHP legs
   (window arithmetic, group-by-pattern, JSONPath redaction, hash-folded
   lookups) over pipelinq-owned schemas. OR offers no equivalent; the only OR
   touch-point is the generic `ObjectService::findAll`, which they already use.

## Solution

A **documentation + hardening** change, not a migration:

1. Record the OR-subsystem consumability findings and the
   legal-invariants-preserved argument in `design.md` (the canonical Seam-3
   record).
2. Add a guarding requirement to the `avg-verzoeken-workflow` capability that
   **fixes the safe query envelope** for the shared OR touch-point
   (`ObjectService::findAll`): plain equality / `IN` / sort / limit only;
   computed, case-folded, or ISO-`T`-window predicates stay in PHP. This
   codifies the proven gotchas (OR date-range diverges from ISO-`T` string
   compares; OR `notIn` is case-EXACT) so a future migration cannot silently
   push an unsafe leg into OR.
3. Make the safe envelope **enforceable** by adding a behaviour-preserving unit
   test per candidate that pins the current find/erase/cut-off behaviour, so any
   future attempt to migrate onto OR's subsystem must reproduce identical
   results to pass.

## Scope

- **No production code logic change.** No erasure/retention/anonymisation
  semantics are altered.
- New: per-candidate behaviour-pinning unit tests (find-set, anonymised-field-set,
  retention-cut-off) under `tests/Unit/Service/`.
- New: one ADDED requirement in `specs/avg-verzoeken-workflow/spec.md` codifying
  the OR `findAll` safe-query envelope + the keep-in-PHP boundary.
- Out of scope: changing `DataDeletionService`, `Avg/RetentionService`,
  `EvidenceCollectionService`, `DpiaDetectionService`, `ConsentService`,
  `OptOutService`, `RedactionService`, `AvgRequestService` behaviour. Out of
  scope: the contact-sync WIP in stash.
