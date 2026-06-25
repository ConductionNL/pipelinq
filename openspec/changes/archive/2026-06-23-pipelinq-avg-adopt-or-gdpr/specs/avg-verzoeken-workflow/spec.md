# AVG Verzoeken Workflow — Adopt OpenRegister's canonical GDPR semantics

**Spec refs**: `avg-verzoeken-workflow`, OpenRegister `DataSubjectRequestService` + `DataSubjectDeadline` (Gdpr capability), GDPR art-12(3) / art-15 / art-17 / art-20
**Authorized behavioural change**: see `design.md` — pipelinq adopts OR's EU/generic GDPR mechanics in place of its NL approximations.

## MODIFIED Requirements

### Requirement: REQ-AVG-002 — 30-Day Legal Deadline Tracking with Escalation [HANDOFF]

The receiving capability SHALL compute the legal response deadline as the EU art-12(3) term —
**one month** from receipt, extendable once by **two further months** — via OpenRegister's
canonical `DataSubjectDeadline`, NOT a bespoke NL 30-day/60-day approximation. Pipelinq SHALL
keep the Dutch operational escalation chain (7-day advance reminder, <72h team-lead escalation,
breach logging) that OpenRegister does not own, computing it FROM the OR-derived deadline, with
each milestone idempotent via an existing-TermijnEvent guard.

#### Scenario: One-month timer starts on submission

- **GIVEN** an AvgVerzoek is created
- **WHEN** the system calculates the deadline
- **THEN** `wettelijkeTermijnVerloopt` MUST be set to exactly one month later (OR `DataSubjectDeadline::computeDueAt`), normalised to end-of-day
- **AND** the handler dashboard MUST display the request in the green zone
- `@e2e exclude` server-authoritative deadline maths verified by DeadlineServiceTest + live :8080 PHP harness (received 2026-06-23 → due 2026-07-23)

#### Scenario: Single extension adds two months

- **GIVEN** an open request inside its deadline
- **WHEN** a justified extension is granted
- **THEN** the new deadline MUST be the base (received + 1 month) plus two further months (received + 3 months total)
- **AND** a second extension MUST be refused
- `@e2e exclude` server-authoritative extension maths verified by ExtensionServiceTest (intake 2026-04-08 → extended 2026-07-08) + live harness

#### Scenario: Escalation chain fires from the EU deadline

- **GIVEN** a request whose EU deadline is 7 days away, then <72h away, then passed
- **WHEN** the deadline jobs run
- **THEN** a 7-day reminder, then a <72h team-lead escalation, then a `termijn-overschreden` breach TermijnEvent + FG notification MUST each fire exactly once, computed from `wettelijkeTermijnVerloopt`
- **AND** repeated job runs MUST NOT double-notify (existing-TermijnEvent idempotency)
- `@e2e exclude` job-driven escalation chain verified by DeadlineTrackerServiceTest

### Requirement: REQ-AVG-004 — Federated Evidence Collection from Multiple Sources [HANDOFF]

The receiving capability SHALL discover the data subject's objects through OpenRegister's
canonical NER-index discovery (`DataSubjectRequestService::findSubjectData`, RBAC + tenant
scoped), NOT a bespoke `bsn`-equality `findAll` filter. The OpenConnector federated-source
collection, BewijsItem packaging, scope overlay, and content-hash deduplication remain the
pipelinq app overlay on top of that discovery.

#### Scenario: Discovery uses OR's NER index

- **GIVEN** an art-15 request with a verified BSN
- **WHEN** the handler triggers evidence collection
- **THEN** the app MUST call `findSubjectData(subjectId)` so every object the OR NER index ties to the subject (BSN / email / name) is discovered
- **AND** each in-scope envelope MUST become a BewijsItem with source metadata, the matched GdprEntity category, collection timestamp, legal basis, and export flag
- **AND** the app MUST NOT issue a single-column `bsn` equality filter for discovery
- `@e2e exclude` discovery boundary verified by EvidenceCollectionServiceTest (`testSubjectDiscoveryUsesOrNerIndex`) + live findSubjectData shape check

#### Scenario: External source timeout and dedup are unchanged

- **GIVEN** an OpenConnector source times out and another returns a duplicate object
- **WHEN** collection runs
- **THEN** the unreachable source MUST yield a `bron-onbereikbaar` BewijsItem + `collectie-fout` TermijnEvent without aborting the run
- **AND** identical content MUST be flagged `gedupliceerd` and excluded from export
- `@e2e exclude` federated overlay behaviour unchanged; verified by EvidenceCollectionServiceTest

### Requirement: REQ-AVG-009 — 5-Year Dossier Retention with Evidence Pseudonymization [HANDOFF]

The receiving capability SHALL retain dossiers for 5 years (an app-owned policy OR does not own)
and SHALL scrub evidence PII on the 30-day post-export window. Right-to-be-forgotten erasure SHALL
delegate to OpenRegister's legal-hold-aware field-level pseudonymise erasure
(`DataSubjectRequestService::erase(..., 'pseudonymise')`), which RETAINS the owning row and skips
objects under legal hold / immutability — preserving the NL Boekhoudplicht 7-year booking
retention. The cached-evidence scrub aligns on OR's `[erased]` token.

#### Scenario: Erasure preserves the Boekhoudplicht booking row

- **GIVEN** a right-to-be-forgotten request for a customer with booking records, one under an active 7-year Boekhoudplicht legal hold
- **WHEN** the app erases the customer's data via OR
- **THEN** OR MUST overwrite the matching PII values with the `[erased]` token and RETAIN every row
- **AND** the held booking MUST be reported in the `held` bucket and never deleted nor mutated
- **AND** the erasure MUST NOT use `whole-object` (row-delete) mode
- `@e2e exclude` retention invariant verified by DataDeletionServiceTest (`testHeldBookingRowSurvivesErasure`) + live :8080 RetentionService legal-hold guard proof

#### Scenario: 5-year dossier retention and 30-day evidence window stay app-owned

- **GIVEN** archived dossiers with a 5-year `retentieTot` and evidence items past the 30-day window
- **WHEN** the retention pass runs
- **THEN** the dossier cut-off MUST be computed from the app's `retentieTot` (5-year cascade delete) and the evidence cut-off from `verzameldOp + N days`
- **AND** an over-window evidence item's `inhoudPreview` MUST be replaced with OR's `[erased]` token while its source metadata stays intact
- `@e2e exclude` app-owned retention schedule verified by RetentionServiceTest

### Requirement: REQ-AVG-014 — OpenRegister Compliance-Subsystem Consumption Boundary

The AVG workflow SHALL fulfil data-subject rights through OpenRegister's canonical,
RBAC + tenant scoped GDPR capability — `DataSubjectRequestService` (`findSubjectData` /
`assembleAccessExport` / `erase`) and `DataSubjectDeadline` — consumed via the `OrGdprBridge`
adapter. This SUPERSEDES the earlier boundary that kept subject matching, deadline maths, and
erasure semantics in the app: the owner has decided OR's EU-generic mechanics (one-month
deadline, NER discovery, legal-hold-aware pseudonymise) are the correct floor. The app SHALL
still NOT call the administrator-gated `DsarService` (which bypasses RBAC and soft-deletes whole
objects); it consumes the non-admin `DataSubjectRequestService` counterpart, which is RBAC +
tenant scoped and erases via field-level pseudonymise-and-keep. The bridge SHALL degrade to a
safe no-op when OpenRegister is absent, and SHALL never log the subject identifier value.

**Feature tier**: MVP

#### Scenario: Subject discovery and erasure go through the canonical RBAC-scoped service

- **GIVEN** an AVG handler (not necessarily an administrator) fulfilling a request for a data subject
- **WHEN** the app discovers or erases the subject's objects
- **THEN** it MUST use `DataSubjectRequestService::findSubjectData` / `erase` via `OrGdprBridge`, which is RBAC + tenant scoped (the caller only reaches objects it may read/mutate)
- **AND** it MUST NOT call the administrator-gated `DsarService`, which bypasses RBAC and soft-deletes whole objects
- `@e2e exclude` consumption boundary verified by OrGdprBridge live resolution on :8080 (`isAvailable: true`) + DataDeletionServiceTest

#### Scenario: Deadline maths is the EU canonical computation

- **GIVEN** a request received on a given date
- **WHEN** the app computes the legal deadline
- **THEN** it MUST use `DataSubjectDeadline::computeDueAt` (+1 month) and `extend` (+2 months) via `OrGdprBridge`, NOT a local day-count
- `@e2e exclude` deadline delegation verified by DeadlineServiceTest + live harness

#### Scenario: Bridge degrades safely without OpenRegister

- **GIVEN** OpenRegister is not installed
- **WHEN** the app invokes any GDPR leg through `OrGdprBridge`
- **THEN** discovery/export/erase MUST return safe empty results and the deadline MUST fall back to the same one-month/two-month maths locally
- **AND** the subject identifier value MUST NOT be written to any log
- `@e2e exclude` graceful-degradation + no-BSN-logging verified by OrGdprBridge code review + DataDeletionServiceTest (logs only counts)
