# Design: pipelinq-gdpr-to-or-subsystem (Seam 3)

## 1. OR GDPR-subsystem consumability findings (per service)

Each OR service was read in full (`openregister/lib/Service/`). For each:
*consumable from a leaf app?* and *exact contract*.

### 1.1 `DsarService` — NOT consumable for pipelinq's finds/erases

- **DI**: autowired, but registered only into OR's own admin-gated
  `DsarController`. No cross-app service registration.
- **Hard admin guard**: every public method calls `assertPrivileged()`, which
  `throw`s `RuntimeException('DSAR operations require administrator
  privileges')` unless `groupManager->isAdmin(currentUser)`. Pipelinq's AVG
  handler / booking flows do not run as admin → would hard-fail.
- **Find contract**: `findObjectsForSubject(string $subject, ?string $type,
  string $mode='exact')` matches `openregister_entities.value` (the GdprEntity
  **PII-detection index**) `iLike` the subject, inner-joined to
  `openregister_entity_relations` to resolve owning objects. It depends on OR's
  PII auto-detection having populated that index. It bypasses RBAC/tenant
  (`_rbac:false, _multitenancy:false`) deliberately — a cross-tenant amplifier.
- **Erase contract**: `eraseObjectsForSubject(...)` **soft-deletes the whole
  ObjectEntity** via `setDeleted([...])` + `objectMapper->update`.
- **Why it cannot replace pipelinq**:
  - Pipelinq finds by **plain equality** (`bsn`/`customerId`/`contactId`/
    `bsnHash`) on **its own** registers via `ObjectService::findAll` — a
    different match surface than the PII index. Result sets would differ.
  - Pipelinq's Art-17 is **field-level pseudonymise-and-keep** (Boekhoudplicht),
    the polar opposite of OR's object soft-delete.
  - Admin-gating breaks the non-admin handler path.
  - **Verdict: STOP.** Migrating would change which records are found and how
    they are erased — a compliance regression.

### 1.2 `AvgRetentionService` — NOT consumable for pipelinq retention

- **DI**: autowired, no privilege guard, callable.
- **Contract**: `runRetentionPass(bool $dryRun)` iterates published
  `Verwerkingsactiviteit` rows, computes `now - bewaartermijn` (ISO-8601
  duration), finds objects whose `MAX(audit_trails.created)` for that
  `processing_activity_id` predates the cut-off, soft-deletes them.
- **Why it cannot replace pipelinq**:
  - Pipelinq retention is **per-dossier `retentieTot`** (5-year RvIG window,
    stamped at archive) and **per-evidence `verzameldOp + 30d`**
    pseudonymisation — neither is activity-keyed nor audit-trail-`MAX(created)`-
    keyed.
  - It operates on **pipelinq-owned AVG schemas** (Verzoek, BewijsItem, …) that
    carry no OR `processing_activity_id`.
  - The cut-off arithmetic (string ISO-`T` compare on `retentieTot` vs.
    `MAX(created) < cutoff` on the audit ledger) differs.
  - **Verdict: STOP.** Migrating would re-time every erasure.

### 1.3 `RetentionService` (archival lifecycle) — NOT applicable

- Consumable in principle (autowired), but its contract is **single-object /
  schema-`archive`-config archival metadata**: `applyArchivalMetadata`,
  `calculateArchiefactiedatum`, selectielijst lookup, legal holds,
  `findEligibleForDestruction`. This is Archiefwet/MDTO archival, not AVG
  subject-find or pseudonymisation. Pipelinq's AVG mechanics have no archival-
  metadata surface to delegate. **Not a candidate.**

### 1.4 `ArchivalService` — NOT applicable

- Destruction-workflow mechanics (`generateDestructionList`,
  `approveDestructionList` → **hard delete** of `openregister_objects` rows).
  Pipelinq explicitly must **not** hard-delete bookings (Boekhoudplicht) and
  its AVG dossier deletion is over pipelinq-owned schemas via `AvgRepository`.
  **Not a candidate.**

### 1.5 `AuditHashService` — NOT applicable

- OR-internal SHA-256 audit-chain over `openregister_audit_trails`
  (genesis hash, `computeHash`, `verifyChain`). Pure OR audit integrity; nothing
  in pipelinq's GDPR plumbing maps to it. **Not a candidate.**

## 2. Per-candidate: what moved vs. stayed PHP (+ legal-invariant preserved)

| # | Pipelinq site | Candidate OR target | Decision | Legal invariant preserved |
|---|---|---|---|---|
| 1 | `EvidenceCollectionService::collectFromOpenRegister` (bsn `findAll`) | `DsarService::findObjectsForSubject` | **STAY PHP** | Find-set = "objects in scoped registers for this BSN" via plain `findAll` eq-filter; not OR's PII-index match. Same objects found before/after (no change). |
| 2 | `EvidenceCollectionService::deduplicate` (PHP group-by `contentHash`) | OR aggregation/facet | **STAY PHP** | Dedup is over pipelinq-owned BewijsItem objects keyed on a pipelinq-computed `contentHash`; OR has no facet over this. First-wins ordering preserved. |
| 3 | `DataDeletionService` (Art-17 find + pseudonymise) | `DsarService` erase | **STAY PHP** | Exactly `{customerName,customerEmail,customerPhone}` → SHA-256, **row + all other fields retained** (Boekhoudplicht). OR would soft-delete the whole object — opposite semantics. |
| 4 | `Avg/RetentionService` (per-dossier/evidence cut-off) | `AvgRetentionService::runRetentionPass` | **STAY PHP** | 5-year `retentieTot` dossier window + 30-day evidence pseudonymisation; OR is activity/audit-keyed. Cut-offs unchanged. |
| 5 | DPIA / consent / opt-out / redaction query legs | OR safe eq/IN/sort/limit | **STAY PHP** | All are computed-in-PHP (window arithmetic, group-by-pattern, JSONPath, hash-folded lookup). The OR touch-point is already the generic `ObjectService::findAll` with plain eq filters — already inside the safe envelope; nothing to move. |

**Net: zero mechanics moved.** OR does not own any of these mechanics; the only
shared primitive (generic `ObjectService::findAll` with plain equality filters)
is already in use and stays. This change *documents* that and *guards* the
envelope.

## 3. Safe OR-query envelope (the codified boundary)

Proven gotchas (earlier seams): OR date-range filtering diverges from ISO-`T`
string comparisons, and OR `notIn` is **case-EXACT**. Therefore the boundary for
any pipelinq leg delegated to OR `findAll`:

- **Allowed in OR**: plain equality, `IN`, sort, limit.
- **Stays in PHP**: any computed predicate, any case-folded compare, any
  ISO-`T` timestamp-window compare, group-by/dedup, JSONPath redaction.

The pinning tests assert that the find-set, the anonymised-field-set, and the
retention cut-off are **identical** to today's behaviour — so a future migration
attempt must reproduce them exactly or fail CI.

## 4. Legal-invariants-preserved argument (summary)

No erasure, retention, or anonymisation semantics change in this change:
- Same objects are found for a subject (plain eq-filter find, unchanged).
- Same fields are anonymised (`customerName/Email/Phone` → SHA-256; everything
  else retained; row retained).
- Same retention cut-offs apply (`retentieTot` 5y; `verzameldOp + 30d`; AVG
  dossier hard-delete only after `retentieTot`).
- The DPO early-destruction override and the fail-safe
  "no `retentieTot` ⇒ retention-active" guard are untouched.

If, in a later cycle, OR exposes a leaf-consumable, non-admin-gated, register-
scoped subject-find + field-level pseudonymisation contract that matches these
invariants, the pinning tests are the gate that proves it is safe to adopt.
Until then: **keep PHP.**
