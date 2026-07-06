# Tasks: align-claims-and-first-hour

## 1. Licence Conformance

- [x] 1.1 EUPL-1.2 in all prose + badge + `<licence>` element
  - **done 2026-07-06**: deployed NC's `resources/app-info.xsd` verified to carry the `EUPL-1.2` enum value → `<licence>EUPL-1.2</licence>` shipped (no fallback needed); `DOMDocument::schemaValidate` against the deployed xsd passes. While validating, three PRE-EXISTING schema violations in info.xml were fixed (always-fix-preexisting): element order (`php` before `nextcloud`; `background-jobs` before `repair-steps`; `settings`/`activity` before `navigations`), activity child order (settings→filters→providers), and the `<notification>` element — which NC core never reads and the xsd rejects — replaced by the canonical `registerNotifierService(Notifier::class)` in `lib/AppInfo/Application.php` (the Notifier was previously never registered).
  - **spec_ref**: `specs/docs-product-pages-conformance/spec.md#requirement-licence-claims-conformance`
  - **files**: `README.md` (line 13 badge, licence section), `appinfo/info.xml` (lines 28, 51, 56)
  - **acceptance_criteria**:
    - Badge → EUPL-1.2 shield linking to LICENSE; EN description "under the EUPL-1.2 license"; NL "onder de EUPL-1.2-licentie"
    - `<licence>EUPL-1.2</licence>` (PO decision 2026-07-05; the token is in the upstream xsd enum since nextcloud/server PR #60212, 2026-05-07, and in the App Store licenses fixtures) — verify the deployed NC's `resources/app-info.xsd` carries the EUPL value (tags ≥ v33.0.5 / stable31+ heads; do NOT trust the stale dev checkout). Fallback ONLY for NC versions whose xsd predates the value: keep `agpl` + XML comment per design.md §Licence
    - `grep -ri agpl README.md appinfo/info.xml` matches nothing except (if the fallback is in force) the annotated element, plus the licence-compatibility list in README:268, which correctly names AGPL as an EUPL-compatible licence

## 2. Claim Rewrites

- [x] 2.1 Unified Search attributed to OpenRegister
  - **spec_ref**: `specs/docs-product-pages-conformance/spec.md#requirement-feature-claims-match-implementation`
  - **files**: `README.md` (line 94)
  - **acceptance_criteria**:
    - Entry states global-search integration is provided via OpenRegister (`lib/Search/ObjectsProvider.php`), no pipelinq-owned claim remains

- [x] 2.2 Request-to-Case Bridge → roadmap referencing semantic-handoff-emit
  - **done 2026-07-06**: README feature bullet + the info.xml EN/NL "Pairs naturally with Procest" prose (which carried the present-tense handoff claim) reworded to in-development referencing `semantic-handoff-emit`.
  - **spec_ref**: `specs/docs-product-pages-conformance/spec.md#requirement-feature-claims-match-implementation`
  - **files**: `README.md` (line 58), `appinfo/info.xml` (feature lists EN + NL)
  - **acceptance_criteria**:
    - Wording marks the bridge as in development via openspec change `semantic-handoff-emit` (kind-addressed `ns#Case` handoff); no "hand off directly to Procest when ready" present-tense claim

- [x] 2.3 Duplicate detection re-pointed at OR MDM; CSV-import claim removed
  - **done 2026-07-06**: grep-verified — CSV *export* code exists (`lib/Service/Export/ExportDataService.php` csv/parquet/jsonl; ForecastController csv) and vCard import/export exists (`ContactImportService`, `ContactVcardWriterService`), so the bullet now claims "vCard contact import and export via Nextcloud Contacts, plus scheduled CSV data exports" — no bulk CSV-import claim remains in EN or NL.
  - **spec_ref**: `specs/docs-product-pages-conformance/spec.md#requirement-feature-claims-match-implementation`
  - **files**: `appinfo/info.xml` (lines 20–21 + NL lines 44), `README.md` (line 48, import/export bullet)
  - **acceptance_criteria**:
    - Duplicate detection described as provided via OpenRegister master-data management (in-app engine deleted in PR#332)
    - No CSV-import claim in EN or NL; vCard claims retained only where code backs them (verify export paths by grep during apply — claim only what exists)

## 3. Overlay & docs/features.json

- [x] 3.1 Downgrade omnichannel-registratie to beta with reason
  - **done 2026-07-06**: `status: "beta"` + `statusReason` (overlay entries carried no prior reason field, so `statusReason` is the additive field); EN/NL summaries describe inbound registration + consent logging. JSON re-validated.
  - **spec_ref**: `specs/docs-product-pages-conformance/spec.md#requirement-features-overlay-status-honesty`
  - **files**: `openspec/features.overlay.json`
  - **acceptance_criteria**:
    - `status: "beta"` + reason field (per overlay schema) naming: outbound WhatsApp/SMS send has zero production callers/no UI; `SlaEngineService::dispatchNotification` defers all channels except nextcloud-notification; inbound webhooks wired
    - EN/NL summaries rewritten to inbound registration + consent logging; JSON re-validated after edit

- [x] 3.2 Fix docs/features.json staleness
  - **done 2026-07-06**: kcc-werkplek entry describes the shipped Customer Support workspace (KccWerkplek nav host, src/views/werkplek). Two adjacent stale entries corrected in the same batch: contactmomenten-rapportage (shipped at /rapportage/contactmomenten with Playwright coverage) and email-calendar-sync (EmailSyncService/CalendarSyncService/EmailMatchJob exist — reworded to a backend-integration exclusion instead of "not yet implemented"). JSON re-validated.
  - **spec_ref**: `specs/docs-product-pages-conformance/spec.md#requirement-docsfeaturesjson-matches-shipped-reality`
  - **files**: `docs/features.json`
  - **acceptance_criteria**:
    - kcc-werkplek entry describes the shipped Customer Support workspace (no "not yet implemented")
    - Adjacent entries contradicting HEAD corrected in the same batch (factual status only); JSON re-validated

## 4. Dashboard

- [x] 4.1 Remove the null Satisfaction KPI from the Operational dashboard
  - **done 2026-07-06**: widget def, layout slot 17, `widget-satisfaction` slot mapping, registry entry and `SatisfactionKpiWidget.vue` all removed (nothing else referenced them); KPI row 2 reflowed to 3×4-wide (lead-conversion / avg-resolution / contact-volume) — no hole, verified in the browser; `_note` on the OperationalDashboard page records restoration via `customer-satisfaction-closed-loop`; `AnalyticsService` left untouched.
  - **spec_ref**: `specs/dashboard/spec.md#requirement-no-permanently-null-default-widgets`
  - **files**: `src/manifest.json` (widget def ~388, layout slot ~408, template map ~423), `src/registry.js`, `src/views/dashboard/widgets/SatisfactionKpiWidget.vue`
  - **acceptance_criteria**:
    - Widget def, layout slot `id: "17"`, and `widget-satisfaction` mapping removed; grid reflowed (no hole)
    - Component + registry entry removed only if nothing else references them; manifest `_note` records restoration via `customer-satisfaction-closed-loop`
    - No placeholder tile added; AnalyticsService `$responses = []` left for `customer-satisfaction-closed-loop` (no partial CSAT re-sourcing here)

## 5. Demo Seed

- [x] 5.1 Seeding service + occ command `pipelinq:demo:seed`
  - **done 2026-07-06** with documented deviations, all live-verified on the dev instance:
    - Command registered via `appinfo/register_command.php` (the repo's existing convention — PortalCleanupCommand registers there; info.xml has no `<commands>` block), not via info.xml as the task file-list guessed.
    - Client identities are provisioned contact-first through `ContactVcardService::provisionContactFromForm` because the live client schema (register.d/15-unify-client-contact.json) marks `contactsUid` REQUIRED (FK to the authoritative NC addressbook contact). The backing addressbook contacts are matched-or-created and intentionally NOT deleted by `--remove` (they may be pre-existing matches).
    - Idempotency/removal lookups scan the schema and match in PHP (`buildLookupIndex`) — magic-table equality filters are schema-dependent (lead.title filters silently match nothing live), and a false negative would break idempotency.
    - `--remove` deletes exactly the `[Demo]`-marked set for clients/leads/requests; the contactmoment schema declares `x-openregister-archival` (user-driven deletes rejected with SCHEMA_ARCHIVAL_IMMUTABLE by design), so its demo rows are reported as `retained` and expire via the OR retention cron — the closest faithful reading of "removal deletes exactly the seeded set" for an append-only compliance schema.
    - Live-verified: seed creates 5/6/8/12 linked objects; re-run creates 0 (all skipped); `--remove` deletes exactly the demo set incl. duplicates from historic runs; unit tests cover idempotency, removal scoping (with non-demo decoys), archival retention, and failure modes.
  - **spec_ref**: `specs/first-time-setup/spec.md#requirement-req-setup-pip-008--optional-demo-data-seed`
  - **files**: `lib/Service/DemoSeedService.php`, `lib/Command/SeedDemoDataCommand.php`, `appinfo/info.xml` (command registration)
  - **acceptance_criteria**:
    - Mirrors procest `SeedBezwaarBeroepCommand`: Symfony Command, explicit owner context (occ has no session), idempotent via stable demo identifiers
    - Seeds linked demo set (~5 clients person/org, ~6 leads across stages, ~8 requests across statuses, ~12 contactmomenten across channels); klantbeeld-360 + dashboards render populated
    - Demo marking on every object; `--remove` deletes exactly the seeded set; unit tests cover idempotency + removal scoping

- [x] 5.2 Optional setup-wizard action
  - **done 2026-07-06**: `seed-demo-data` action added to the existing SetupController runAction surface (extended, not forked) invoking the same DemoSeedService; manifest setup step `demo-data` (run-action, optional — no `required` flag, so skipping never blocks completion; only currency is required). Walked live in the browser: the wizard gates on an unconfigured install, offers "Demo data (optional)" as step 4, and Run reports the seeded/idempotent result.
  - **spec_ref**: `specs/first-time-setup/spec.md#requirement-req-setup-pip-008--optional-demo-data-seed`
  - **files**: `lib/Controller/SetupController.php` (action `seed-demo-data`), `src/manifest.json` (setup block)
  - **acceptance_criteria**:
    - Admin-only optional action per ADR-042 invoking `DemoSeedService` (same write path as the command); skipping never blocks completion
    - Coordinated with the `first-time-setup` change's SetupController surface — extend it, do not fork it

## 6. Verification

- [x] 6.1 Tests + gates
  - **done 2026-07-06**: PHPUnit — 7 DemoSeedService tests + full unit suite green in bare php:8.3-cli (CI parity); vitest 45 green; Playwright `tests/e2e/spec-coverage/align-claims-first-hour.spec.ts` 3 green (dashboard renders without satisfaction tile + reflow; seed action success + idempotency through the app session; seeded clients render in the Clients list); Newman "Setup Wizard (ADR-042)" folder added (status, seed-demo-data, unknown→404). info.xml validates against the deployed app-info.xsd; phpcs/phpmd/psalm/phpstan clean on the diff; hydra gates 28/28 green. NOTE: the gated-wizard UI walk (unset currency → wizard → run demo step) was verified manually via real browser clicks and is not encoded as a repeatable Playwright test because it requires mutating the shared instance's required app config; the action surface + idempotency are covered by e2e + Newman instead.
  - **spec_ref**: all
  - **files**: `tests/unit/`, `tests/e2e/`
  - **acceptance_criteria**:
    - PHPUnit: seed idempotency/removal; JSON validity checks for overlay + features.json edits
    - Playwright: Operational dashboard renders without the satisfaction tile; wizard offers + runs the demo action (UI clicks); API assertions in Newman
    - `appinfo/info.xml` validates against app-info.xsd; `composer check:strict` green; hydra gates pass
