## Context

ADR-047 relocates the generic AVG/DSAR **case-management workflow** into OpenRegister and reframes NL jurisdiction as **data + bindings**. pipelinq today runs a parallel, stateful `avgVerzoek` case workflow over OR object storage: intake → deadline-tracking → evidence → redaction → bundle → denial → archive/retention, plus NL policy (Dutch denial grounds, BSN/BRP/RvIG, AP-complaint escalation, Boekhoudplicht retention). ADR-047's gap analysis found this is ~70% already delegable to OR and ~30% pipelinq-unique, where the unique 30% splits cleanly into a generic case spine (→ OR) and NL jurisdiction policy (→ policy-pack data + two seam bindings).

This change is **Phase 3, the consumer + retirement**. OpenRegister Phase 1 (`dsar-case-subsystem`, `dsar-case-engine`) and Phase 2 (`dsar-policy-pack-and-seams`, `dsar-integration-seams`, `dsar-case-ui`) supply the persisted `dataSubjectRequest` entity, the `dsarPolicyPack` schema, the `IdentityVerifyRegistry`/`RegulatorEscalateRegistry` seams, and the `AvgIndex.vue` Cases tab. pipelinq then: ships the NL policy pack (data), binds two providers into OR's seams (ADR-019), deep-links its nav into OR's case UI, and **deletes** its local AVG surface.

**Current state — pipelinq owns no DB tables** (thin client, ADR-022): `avgVerzoek`/BewijsItem/etc. objects are already OpenRegister-native, stored via `ObjectService` on the `pipelinq` register. `OrGdprBridge` already delegates the *stateless engine* (discovery/deadline/export/erase) to OR's `DataSubjectRequestService`; what remains local is the *stateful case workflow*. This change completes the move: the bridge's delegation concept is superseded by directly consuming OR's persisted case surface.

**Cross-repo gate (critical):** this change is hard-gated on the five OpenRegister changes above being implemented, merged, and released. opsx `depends_on` only spans same-repo changes, so it is empty; the gate is enforced by human confirmation at queue time. Until then pipelinq's AVG surface stays exactly as-is (ADR-047 migration plan).

## Goals / Non-Goals

**Goals:**
- Ship one NL-jurisdiction `dsarPolicyPack` config object conforming to OR's Phase-2 schema (deadlines, art-23 denial grounds + Dutch citations, retention windows, intake channels, DPO/FG mapping, letter-template refs, seam selectors).
- Register a pipelinq `IdentityVerifyProvider` (BSN/BRP/RvIG) and a pipelinq `RegulatorEscalateProvider` (AP-complaint) into OR's two registries from pipelinq's bootstrap, first-wins (ADR-019).
- Replace the `AvgRequests` internal nav route with a deep-link into OR's AVG case surface (ADR-019 deep-link / ADR-044 menu).
- Retire (**BREAKING**) pipelinq's local AVG surface: controllers, `lib/Service/Avg/*`, 6 background jobs, 3 views, `avgApi.js`, their routes, their manifest entry, and their `info.xml` `<background-jobs>` registrations.
- Correct the stale `menu-layout.json` `_settingsSectionNote` claiming pipelinq cannot consume OR.

**Non-Goals:**
- Building or respecifying the OR case subsystem, policy-pack schema, seams, or case UI — those are the OpenRegister Phase-1/2 changes.
- Migrating other fleet apps (procest/zaakafhandelapp/opencatalogi) — each gets its own tracked "consume-OR-avg-workflow" issue once pipelinq proves the pattern.
- Any change to pipelinq's loyalty *program* (`LoyaltyController`, `LoyaltyReportingController`) or BRP *admin* (`BrpAdminController`) surfaces beyond the AVG-scoped removals enumerated below (see DEFERRED_QUESTIONS for the `LoyaltyGdprController`/`BrpController` scope calls).
- Rewriting stored `avgVerzoek` objects — the retirement removes pipelinq code, not OR-stored data.

## Decisions

### ADR-031: jurisdiction is data, engine is OR — nothing stays as a pipelinq service

| Concern | Old pipelinq mechanism (removed) | New mechanism | Home |
|---|---|---|---|
| Case lifecycle / deadline / evidence / redaction / bundle / denial / retention | `lib/Service/Avg/*` services + `AvgVerzoekController` et al. | OR `dataSubjectRequest` case engine + `/api/gdpr/cases/*` | OpenRegister |
| Deadline durations + escalation thresholds | Hard-coded in `DeadlineService`/`DeadlineTrackerService` | `dsarPolicyPack.deadlines` + `.escalationTiers` (config) | pipelinq policy pack (data) |
| Denial grounds + Dutch wording | `DenialService` enum/strings | `dsarPolicyPack.denialGrounds[]` (key → label + citation) | pipelinq policy pack (data) |
| Retention windows (Boekhoudplicht/RvIG) | `RetentionService` constants | `dsarPolicyPack.retentionWindows[]` (window → duration) | pipelinq policy pack (data) |
| Intake channels | Hard-coded in intake view | `dsarPolicyPack.intakeChannels[]` | pipelinq policy pack (data) |
| DPO/FG role mapping | `AvgAccessService` | `dsarPolicyPack.roleMapping` | pipelinq policy pack (data) |
| BSN/BRP/RvIG identity verification | `BrpController` + BRP jobs | `IdentityVerifyProvider` registered into OR's `IdentityVerifyRegistry` | pipelinq binding (code, ADR-019 seam) |
| AP-complaint escalation | inline in `DenialService`/`AvgEventService` | `RegulatorEscalateProvider` registered into OR's `RegulatorEscalateRegistry` | pipelinq binding (code, ADR-019 seam) |
| Nav entry to the AVG surface | `AvgRequests` internal route → `AvgDashboardView` | deep-link menu entry → OR `AvgIndex.vue` Cases tab | pipelinq nav (deep-link, ADR-019/044) |

**Rationale:** per ADR-031/ADR-047, no jurisdiction wording or threshold may remain in code once OR ships. Only the two identity/regulator seams are legitimate code bindings (the ADR-019/031 registry-seam exception); everything else becomes policy-pack data or is deleted.

### Enumerated retirement list (grounded in the real pipelinq tree, 2026-07-03)

Deleted **controllers** (`lib/Controller/`): `AvgBundleController.php`, `AvgDenialController.php`, `AvgEvidenceController.php`, `AvgRedactionController.php`, `AvgVerzoekController.php`, `MdmAvgWorkflowController.php`, `BrpController.php`, `LoyaltyGdprController.php`.

Deleted **services** (`lib/Service/Avg/`, all 15): `AvgAccessService.php`, `AvgEventService.php`, `AvgNotificationService.php`, `AvgRepository.php`, `AvgRequestService.php`, `BundleService.php`, `DeadlineService.php`, `DeadlineTrackerService.php`, `DenialService.php`, `DpiaDetectionService.php`, `EvidenceCollectionService.php`, `ExtensionService.php`, `OrGdprBridge.php`, `RedactionService.php`, `RetentionService.php`.

Deleted **background jobs** (`lib/BackgroundJob/`): `AvgDeadlineTrackerJob.php`, `AvgDpiaPatternDetectionJob.php`, `AvgRetentionJob.php`, `BrpHealthCheckJob.php`, `BrpMonitorJob.php`, `BrpRetentionJob.php`. (`AvgCollectEvidenceJob.php` exists but is NOT registered in `info.xml`; it is deleted with the surface — see DEFERRED_QUESTIONS.)

Deleted **frontend** (`src/`): `views/avg/AvgDashboard.vue`, `views/avg/AvgIntakeView.vue`, `views/avg/AvgRequestDetail.vue`, `services/avgApi.js`.

Edited **config**: `appinfo/routes.php` (remove the `avgVerzoek#*`, `avgEvidence#*`, `avgDenial#*`, `avgBundle#*`, `avgRedaction#*`, `mdmAvgWorkflow#*`, `brp#*`, `loyaltyGdpr#*` route entries — ~59 route lines matched `avg|brp|loyalty`, scope to the AVG-surface ones only), `appinfo/info.xml` (remove the 6 `<job>` lines: `AvgDeadlineTrackerJob`, `AvgDpiaPatternDetectionJob`, `AvgRetentionJob`, `BrpHealthCheckJob`, `BrpMonitorJob`, `BrpRetentionJob`), `src/manifest.d/40-avg-verzoeken.json` (replace the 3 internal pages + menu route with one deep-link entry), `src/menu-layout.json` (`AvgRequests` becomes a deep-link; correct `_settingsSectionNote`).

### Deep-link (ADR-019 / ADR-044)

pipelinq's existing manifest already uses external `href` menu entries (e.g. `"/index.php/apps/hrmq/timesheets/approval"`). Replace the `AvgRequests` internal route with a deep-link menu entry pointing at OpenRegister's AVG case surface (the `AvgIndex.vue` Cases tab route under the `openregister` app). This keeps the `AvgRequests`-labelled entry in the settings foldout but sends the handler into OR's case UI. Alternative considered: drop the entry entirely — rejected, because handlers still need a discoverable route to the case surface.

### NL policy pack as data (ADR-031), not PHP

The pack is authored as an OpenRegister object on OR's `dsar-policy-packs` register, conforming to the `dsarPolicyPack` schema. pipelinq ships it as seed/config data (candidate home: `lib/Settings/` seed alongside pipelinq's other register config, imported via `ConfigurationService`), NOT as a PHP `PolicyService`. All ids/tokens/role-ids use safe placeholders. See Seed Data below.

## Seed Data — NL `dsarPolicyPack` (illustrative, safe placeholders)

```json
{
  "@self": { "register": "dsar-policy-packs", "schema": "dsarPolicyPack" },
  "id": "00000000-0000-0000-0000-000000000000",
  "jurisdiction": "NL",
  "title": "Nederland — AVG/DSAR beleidspakket (pipelinq)",
  "description": "Nederlandse jurisdictie-overlay voor OpenRegister's AVG/DSAR casusworkflow.",
  "deadlines": {
    "standardResponseDays": 30,
    "extensionDays": 60,
    "extensionMaxCount": 1
  },
  "escalationTiers": [
    { "key": "reminder",   "title": "Herinnering",       "offsetDays": -7 },
    { "key": "escalation", "title": "Escalatie",         "offsetDays": -3 },
    { "key": "breach",     "title": "Termijn overschreden","offsetDays": 0 }
  ],
  "denialGrounds": [
    { "key": "manifestly-unfounded", "label": "Kennelijk ongegrond of buitensporig", "citation": "AVG art. 12 lid 5" },
    { "key": "third-party-rights",   "label": "Rechten en vrijheden van anderen",     "citation": "AVG art. 23 lid 1 sub i" },
    { "key": "legal-obligation",     "label": "Wettelijke bewaarplicht",               "citation": "AVG art. 23 lid 1 sub e" },
    { "key": "national-security",    "label": "Nationale veiligheid",                  "citation": "AVG art. 23 lid 1 sub a" }
  ],
  "retentionWindows": [
    { "key": "standard",        "title": "Standaard dossier",       "durationMonths": 12 },
    { "key": "boekhoudplicht",  "title": "Fiscale bewaarplicht",    "durationMonths": 84 },
    { "key": "rvig",            "title": "RvIG/BRP verstrekking",   "durationMonths": 60 }
  ],
  "intakeChannels": ["handmatig", "email", "balie", "post", "webformulier"],
  "roleMapping": {
    "dpoRole": "<role-id>",
    "fgRole": "<role-id>",
    "handlerRole": "<role-id>"
  },
  "letterTemplates": {
    "acknowledgement": "<template-id>",
    "denial": "<template-id>",
    "fulfilment": "<template-id>",
    "extension": "<template-id>"
  },
  "identityVerifyProvider": "pipelinq-bsn-brp",
  "regulatorEscalateProvider": "pipelinq-ap-complaint"
}
```

Notes: `deadlines.standardResponseDays: 30` reflects AVG art. 12(3) (one month); `extensionDays: 60` reflects the two-month extension. Citations are illustrative placeholders — the authoritative wording is confirmed at authoring time. All `<role-id>`/`<template-id>`/nil-UUID values are safe placeholders. The `identityVerifyProvider`/`regulatorEscalateProvider` ids MUST match the provider ids pipelinq registers (see seam bindings).

## Risks / Trade-offs

- **[Applied before the OR surface exists → citizens' AVG requests have no landing surface + pipelinq's working AVG is deleted.]** → Hard cross-repo gate: a human confirms `dsar-case-subsystem`/`-engine`/`-policy-pack-and-seams`/`-integration-seams`/`-case-ui` are released before queuing. Stated prominently in proposal.md; DEFERRED_QUESTIONS carries the split recommendation so the deletion head lands last.
- **[Policy-pack schema drift — pipelinq's pack diverges from OR's evolving `dsarPolicyPack` contract.]** → Author the pack against the released OR schema version; OR's default/example pack is the shape reference; validate the pack via `ObjectService` schema validation on import.
- **[Provider id mismatch — the pack's `identityVerifyProvider`/`regulatorEscalateProvider` selectors name ids the bindings do not register.]** → Single source of the ids in one place (constants on the provider classes referenced by the pack); OR fails **closed** (refusing provider) on an unknown id, so a mismatch degrades safely, not open.
- **[Stored `avgVerzoek` objects orphaned if OR's case register is a different register/schema than pipelinq's `avgVerzoek`.]** → Migration Plan below; DEFERRED_QUESTIONS captures the re-point decision. Retirement removes code only; no destructive data op ships in this change.
- **[Removing `BrpController`/`LoyaltyGdprController` breaks a non-AVG caller.]** → Confirm at apply time that these are AVG-scoped (BRP identity verification for AVG; loyalty GDPR export/erase) and not depended on by the loyalty *program* or BRP *admin* surfaces; DEFERRED_QUESTIONS flags the scope call.
- **[Deleting 6 `<background-jobs>` without an upgrade repair step leaves stale `oc_jobs` rows.]** → Migration Plan handles orphaned job rows.

## Migration Plan

1. **Gate check (human).** Confirm all five OpenRegister changes are merged + released and the OR AVG case UI + seams are live on the target instance. Do NOT proceed otherwise.
2. **Ship the NL policy pack (data).** Import the `dsarPolicyPack` NL object onto OR's `dsar-policy-packs` register (via `ConfigurationService`/repair import), with the two seam selectors set to pipelinq's provider ids.
3. **Register the two seam providers (code).** From `lib/AppInfo/Application.php` `register()`, register `pipelinq-bsn-brp` into `IdentityVerifyRegistry` and `pipelinq-ap-complaint` into `RegulatorEscalateRegistry` (first-wins, ADR-019).
4. **Deep-link the nav.** Replace `40-avg-verzoeken.json`'s pages/route with a deep-link menu entry to OR's AVG case surface; correct `menu-layout.json` `_settingsSectionNote`.
5. **Retire the local surface (BREAKING).** Delete the enumerated controllers/services/jobs/views/`avgApi.js`; remove their `routes.php` entries; remove the 6 `<job>` entries from `info.xml`.
6. **Orphaned background jobs.** Deleting a `<job>` from `info.xml` does NOT remove its `oc_jobs` row. Add a repair step (or document the `occ background-job:delete` / removal on next `app:upgrade`) so the 6 removed AVG/BRP jobs are unregistered from `oc_jobs`; otherwise the scheduler logs "class not found" every tick.
7. **Existing `avgVerzoek` objects.** These are OR-native already (thin-client). If OR's case surface reads them in place (same register/schema), no data op is needed — the deep-link surfaces them via OR's case list. If OR's `dataSubjectRequest` is a distinct register/schema, a one-time re-point/copy (via `ObjectService`, non-destructive, keep source) is required — decided in DEFERRED_QUESTIONS, and if needed ships as its own follow-up change, NOT inline with the deletion.
8. **Rollback.** Revert the pipelinq change (restore controllers/services/jobs/views/routes/manifest/info.xml) and re-register the removed jobs on `app:upgrade`; the stored objects are untouched, so rollback is code-only. The policy pack + seam registrations are additive and harmless if left.

## Open Questions

See DEFERRED_QUESTIONS in the final summary (split shape, `avgVerzoek` re-point, `AvgCollectEvidenceJob`, `LoyaltyGdprController`/`BrpController` scope, policy-pack home).
