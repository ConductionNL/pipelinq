# Design: consume-or-dsar

## Context

ADR-047 moved DSAR ownership to OpenRegister in three phases: (1) OR grows the generic GDPR verbs (done — `DataSubjectRequestService`), (2) pipelinq adopts OR mechanics behind `OrGdprBridge` (done — archived change `pipelinq-avg-adopt-or-gdpr`, spec REQ-AVG-014), (3) pipelinq retires the app-side stack once OR ships the full **case** subsystem. Phase 3's precondition landed on OR origin/development on 2026-07-05 (`dsar-case-engine` + `dsar-integration-seams`): a `dataSubjectRequest` register, case lifecycle with transition guards, pluggable evidence/identity/regulator seams, PAdES-signed export bundles with one-time download tokens, retention sweeps, and an AVG UI page at `/apps/openregister/avg`.

All OR references below were verified against `origin/development` (the local checkout is stale — verify via `git -C ../openregister show origin/development:<path>`).

## Service mapping (deletion audit)

Every pipelinq AVG service, its OR replacement, and the disposition. "OR gap" rows do NOT keep app code — they become OR-side delta requirements (§OR-side gaps).

| # | pipelinq (`lib/Service/Avg/`) | OR replacement (origin/development) | Disposition |
|---|---|---|---|
| 1 | `AvgRequestService` | `Gdpr/DataSubjectRequestService` + `dataSubjectRequest` register + `DsarCaseController` (`/api/gdpr/cases`, create/transition/dossier) | Delete; consumers use OR routes/UI |
| 2 | `AvgRepository` | OR `ObjectService` on the `data_subject_request` register (no app-side persistence layer) | Delete |
| 3 | `DeadlineService` | `Gdpr/DataSubjectDeadline` (`computeDueAt` +1 month, `extend` +2 months, `isOverdue`) | Delete (was already a bridge passthrough) |
| 4 | `DeadlineTrackerService` | `dataSubjectRequest.dueAt`/`extendedUntil` + case lifecycle; **proactive escalation notifications = OR gap** | Delete; gap → OR delta |
| 5 | `ExtensionService` | `DataSubjectDeadline::extend` + case fields `extendedUntil`/`extensionReason` via `dsarCase#transition` | Delete |
| 6 | `EvidenceCollectionService` | `Gdpr/Evidence/EvidenceHarvestService` + `EvidenceSourceRegistry`/`EvidenceSourceProvider` (`dsarCase#evidence`) | Delete; pipelinq becomes a **provider** (below) |
| 7 | `BundleService` | `Gdpr/Export/ExportBundleService` + `PadesSigner`/`UnsignedPadesSigner` + `SignedBundle` + `OneTimeDownloadTokenStore` (`dsarCase#generateBundle`/`downloadBundle`) | Delete |
| 8 | `DenialService` | `Gdpr/Lifecycle/DenialFinaliseGuard` + `denialGround` enum + `dsarCase#transition` | Delete |
| 9 | `RedactionService` | `Gdpr/Redaction/RedactionWriteService` (`dsarCase#redact`) | Delete |
| 10 | `RetentionService` | `Gdpr/Retention/RetentionSweepService` + `DsarRetentionSweepJob` (`retentionWindow`/`retainUntil`/`purgedAt`) | Delete |
| 11 | `DpiaDetectionService` | **OR gap** — OR schema carries only a `dpiaRequired` boolean; no pattern-detection engine | Delete; gap → OR delta |
| 12 | `AvgAccessService` | `Gdpr/Case/CaseAccessControl` | Delete |
| 13 | `AvgEventService` | OR built-in object `auditTrail` + case lifecycle transitions (replaces `termijnEvent` satellites) | Delete |
| 14 | `AvgNotificationService` | `x-openregister-notifications` schema rules on `dataSubjectRequest` (ADR-031) | Delete |
| 15 | `OrGdprBridge` | Superseded — was the Phase-2 adapter; Phase 3 removes the app-side callers it served | Delete |
| — | `lib/Service/Mdm/AVGWorkflowService` + `MdmAvgWorkflowController` (right-of-deletion on master entities) | `DataSubjectRequestService::erase` (legal-hold-aware, `pseudonymise` / `whole-object` modes) + case engine | Delete; see §Ordering |

Background jobs: `AvgCollectEvidenceJob` → OR harvest (on-demand via `dsarCase#evidence`); `AvgDeadlineTrackerJob` → OR gap (escalation); `AvgDpiaPatternDetectionJob` → OR gap (detection); `AvgRetentionJob` → OR `DsarRetentionSweepJob`.

Controllers/routes deleted: `avgVerzoek#*`, `avgEvidence#*`, `avgRedaction#*`, `avgDenial#*`, `avgBundle#*` (`appinfo/routes.php` ~487–521), `mdmAvgWorkflow#*` (~533–537).

Identity/regulator: pipelinq's BSN verification flag (`verzoekerBsnGeverifieerd`) and AP escalation (`avgBundle#escalate`) map to OR's `IdentityVerifyRegistry` and `RegulatorEscalateRegistry` seams (`dsarCase#identityVerify` / `dsarCase#escalate`). A pipelinq-side NL BSN identity-verify provider is NOT built in this change (OR ships `NullIdentityVerifyProvider`; an NL provider is a candidate follow-up recorded in §OR-side gaps).

## Thin app-side surface

**Decision: deep link, not embedded widget.** Verified evidence: OR serves a dedicated AVG SPA page — `UiController::avg()` → `TemplateResponse` at `/apps/openregister/avg` (route `ui#avg`). OR does not export an embeddable AVG widget component to leaf apps. Pipelinq therefore follows its own ADR-045 #D precedent (`MdmDataQuality` deep link into OR's Data-Quality surface, see `src/menu-layout.json` `_settingsSectionNote`): the `AvgRequests` settings-section entry becomes a deep-link nav item opening `/apps/openregister/avg`. If OR later exports an embeddable case widget, replacing the link is a one-line manifest change.

## Evidence-source provider

```
lib/Service/PipelinqEvidenceSourceProvider.php
implements OCA\OpenRegister\Service\Gdpr\Evidence\EvidenceSourceProvider
```

- `getSourceId(): 'pipelinq-crm'` — stable id recorded on every harvested item; registry policy is first-wins with a logged warning on collision.
- `isEnabled()` — true when pipelinq's registers resolve (OR present + register provisioned).
- `harvest(string $caseUuid, array $case)` — reads the case's `subjectId`/`subjectType`, queries pipelinq schemas (client, contact, lead, request, contactmoment) via OR `ObjectService` for subject matches, and returns `EvidenceItem[]` each with a stable `contentHash` (idempotent dedupe across re-runs) and per-item `status`.
- Registration: from pipelinq's boot hook (`Application::boot`), resolve `EvidenceSourceRegistry` lazily through the container (same OR-absent-safe pattern OrGdprBridge used) and call `addProvider`. OR core enumerates ONLY registered providers (ADR-019) — this registration is what makes pipelinq data reachable in DSAR fulfilment.

The interface (`getSourceId`/`isEnabled`/`harvest`) and registry (`addProvider`, first-wins) were verified against `origin/development:lib/Service/Gdpr/Evidence/`.

## Data migration: `avgVerzoek` → `dataSubjectRequest`

`lib/Repair/MigrateAvgVerzoekenToOrDsar.php` (repair step, idempotent — skips objects already carrying a `migratedTo` marker). Field mapping:

| avgVerzoek | dataSubjectRequest | Notes |
|---|---|---|
| `verzoekerContact` / `verzoekerNaam` / `verzoekerBsn` | `subjectId` + `subjectType` | contact UUID preferred; BSN/name fallback. `jurisdiction = 'NL'` |
| `artikel` | `type` | art-15→`access`, art-16→`rectification`, art-17→`erasure`, art-18→`restriction`, art-20→`portability`; `geen-avg` → migrate as `closed` with the original article preserved in the notes block |
| `status` | `status` | ingediend→`received`, in-behandeling→`in-progress`, bewijs-verzamelen→`evidence-collection`, redactie→`evidence-collection`, bundle-genereren→`in-progress`, wachten-op-verzoeker→`verifying`, weigering-opgesteld→`denial-drafted`, afgerond→`fulfilled`\|`refused`\|`closed` per `uitkomst` (toegekend/gedeeltelijk→fulfilled, geweigerd→refused, ingetrokken→closed), gearchiveerd→`closed` |
| `ingediendOp` | `receivedAt` | |
| `wettelijkeTermijnVerloopt` | `dueAt` | |
| `verlengdMet` / `verlengingsgrond` | `extendedUntil` / `extensionReason` | `extendedUntil = dueAt + verlengdMet` days |
| `behandelaar` | `handler` | |
| `afgerondOp` | `closedAt` | |
| `uitkomst` | `outcome` | |
| `dpiaFlag` | `dpiaRequired` | |
| `retentieTot` | `retainUntil` | |
| `weigering` satellite | `denialGround` | nearest Art-23 enum value; full weigering text into notes block |
| `bewijsItem` satellites | `evidence[]` | id + contentHash-style descriptor per item |
| `redactieActie` satellites | `redactions[]` | |
| `kenmerk`, `ingediendVia`, `specifiekeVraag`, `scope`, `verzoekerBsnGeverifieerd`, `fgGeinformeerd`, `termijnOverschreden`, `exportBundle` refs | `notes` | Structured JSON migration block (`{"migratedFrom":"pipelinq/avgVerzoek","kenmerk":…}`) — nothing is silently dropped |

Order of operations: migrate → verify counts (source vs target) → only then remove `40-avg-verzoeken.json` from `register.d/` in the same change. Existing export-bundle files are NOT re-signed; their metadata rides along in the notes block and the original files stay in Files.

## Ordering with retire-mdm-sync-queue

`MdmHardDeleteConfirmationJob` (deleted in `retire-mdm-sync-queue`) constructor-injects `Mdm\AVGWorkflowService` (deleted here). Whichever change lands second must not leave a dangling DI reference: if this change lands first, `retire-mdm-sync-queue`'s job deletion is already unblocked; if it lands first there, this change deletes `AVGWorkflowService` freely. Both tasks lists carry the cross-check.

## OR-side gaps (delta requirements for the openregister repo — NOT app code)

1. **DPIA pattern detection** — pipelinq's `DpiaDetectionService` + `AvgDpiaPatternDetectionJob` detect DPIA-triggering request patterns and inform the FG; OR only stores `dpiaRequired`. OR delta: a detection rule engine (or at minimum a policy-pack-driven flagging rule) on the DSAR register.
2. **Deadline escalation notifications** — pipelinq's `AvgDeadlineTrackerJob` proactively notified handlers as `dueAt` approached/passed. OR delta: a deadline-watch job or an `x-openregister-notifications` temporal rule on `dueAt`.
3. **NL identity-verify provider** — OR ships `NullIdentityVerifyProvider`; an NL BSN-verification provider (DigiD/BRP-backed) is a candidate for whichever app owns NL identity, not necessarily pipelinq.

These are recorded here so the owner can author the OR change; nothing in this change's tasks implements them.

## Risks / Trade-offs

- **RBAC surface shift** — AVG handlers now need access to OR's AVG page (admin-gated on OR side). Mitigation: document the required OR role in the migration release notes; the deep link hides for users without OR access.
- **Behaviour deltas** — OR's EU-generic mechanics (1-month deadline, NER discovery, pseudonymise-erase) were already adopted in Phase 2 (REQ-AVG-014); this change removes the last NL-divergent leftovers (status vocabulary, termijnEvent stream). The migration mapping table is the authoritative translation.
- **In-flight requests during upgrade** — the repair step migrates whatever exists at upgrade time; the old routes are gone afterwards. Rollback = re-enable previous app version (schemas remain until the fragment removal ships in the same release — hence migrate-verify-remove ordering inside one change).
