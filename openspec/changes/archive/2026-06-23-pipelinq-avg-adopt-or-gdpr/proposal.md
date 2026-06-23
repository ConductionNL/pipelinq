---
kind: code
depends_on: []
---

## Why

pipelinq's AVG workflow DIVERGED from OpenRegister's canonical GDPR capability: an NL 30/60-day
deadline vs EU art-12(3) one-month/two-month; a `bsn`-equality `findAll` vs OR's NER-index
discovery; named-field SHA-256 vs OR's legal-hold-aware field-level pseudonymise. The owner has
authorized REVERSING the earlier REQ-AVG-014 boundary and ADOPTING OR's semantics — the EU month
is the statutory text, NER discovery is more complete, and OR's pseudonymise still honours
retention/legal-hold (row kept, PII removed), so the NL Boekhoudplicht booking retention holds.
This is an intentional behavioural change, not a behaviour-preserving refactor. See design.md.

## What Changes

A thin `OrGdprBridge` (lazy DI resolve of OR's two GDPR services, degrades gracefully when OR is
absent) re-points each leg of the AVG workflow onto OR's canonical capability:

1. **Deadline** → OR `DataSubjectDeadline::computeDueAt` (+1 month) / `extend` (+2 months). The
   pipelinq 30/60-day maths is removed. The 7-day reminder / <72h escalation / breach chain (which
   OR does not own) is kept and recomputed FROM the new EU deadline; the staged-chain idempotency
   (TermijnEvent guards) is preserved verbatim.
2. **Discovery** → OR `findSubjectData(subjectId)` (NER index). The bsn-filter `findAll` is removed.
   The OpenConnector federated-source collection + BewijsItem packaging / dedup stay as the app
   overlay.
3. **Access / portability export** → OR `assembleAccessExport` anchors the deliverable; pipelinq's
   signing / one-time download token / AP-dossier wrapper (`BundleService`) is kept.
4. **Erasure** → OR `erase(subjectId, type, eraseMode: "pseudonymise")`. The named-field SHA-256
   (`DataDeletionService`) is removed; evidence pseudonymise aligns on OR's `[erased]` token. OR's
   erase honours legal-hold / immutability, so a Boekhoudplicht-held booking row is reported `held`
   and never deleted — the 7-year retention invariant holds by construction.
5. **Request model** → `avgVerzoek.artikel` maps to the generic `dataSubjectRequest.type`
   (access / rectification / erasure / restriction / portability).

**Kept as the Dutch overlay (unchanged):** AP complaint-reference, `weigering`/art-23
(`DenialService`), FG/DPO roles (`AvgAccessService`), 4-eyes citizen Dutch drafts
(`AvgNotificationService`), BSN/BRP verification, DPIA detection, the 5-year dossier retention
policy (`RetentionService::deleteExpiredDossiers`) and the 30-day evidence-window schedule.

## Impact

- **Specs:** `avg-verzoeken-workflow` — REQ-AVG-002, REQ-AVG-004, REQ-AVG-009 MODIFIED to the OR
  semantics; REQ-AVG-014 (the consumption-boundary that forbade OR delegation) MODIFIED to permit
  and require the canonical, RBAC-scoped `DataSubjectRequestService` (distinct from the
  admin-gated `DsarService` it previously forbade).
- **Code:** new `lib/Service/Avg/OrGdprBridge.php`; re-pointed `DeadlineService`,
  `EvidenceCollectionService`, `BundleService`, `RetentionService`, `AvgRequestService`,
  `DataDeletionService`. No controller / route / frontend change.
- **Tests:** unit tests updated to assert the NEW (OR) behaviour, including a live-verified
  retention invariant (held booking row survives erasure).
