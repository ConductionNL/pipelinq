# Tasks: align-claims-and-first-hour

## 1. Licence Conformance

- [ ] 1.1 EUPL-1.2 in all prose + badge + `<licence>` element
  - **spec_ref**: `specs/docs-product-pages-conformance/spec.md#requirement-licence-claims-conformance`
  - **files**: `README.md` (line 13 badge, licence section), `appinfo/info.xml` (lines 28, 51, 56)
  - **acceptance_criteria**:
    - Badge → EUPL-1.2 shield linking to LICENSE; EN description "under the EUPL-1.2 license"; NL "onder de EUPL-1.2-licentie"
    - `<licence>EUPL-1.2</licence>` (PO decision 2026-07-05; the token is in the upstream xsd enum since nextcloud/server PR #60212, 2026-05-07, and in the App Store licenses fixtures) — verify the deployed NC's `resources/app-info.xsd` carries the EUPL value (tags ≥ v33.0.5 / stable31+ heads; do NOT trust the stale dev checkout). Fallback ONLY for NC versions whose xsd predates the value: keep `agpl` + XML comment per design.md §Licence
    - `grep -ri agpl README.md appinfo/info.xml` matches nothing except (if the fallback is in force) the annotated element, plus the licence-compatibility list in README:268, which correctly names AGPL as an EUPL-compatible licence

## 2. Claim Rewrites

- [ ] 2.1 Unified Search attributed to OpenRegister
  - **spec_ref**: `specs/docs-product-pages-conformance/spec.md#requirement-feature-claims-match-implementation`
  - **files**: `README.md` (line 94)
  - **acceptance_criteria**:
    - Entry states global-search integration is provided via OpenRegister (`lib/Search/ObjectsProvider.php`), no pipelinq-owned claim remains

- [ ] 2.2 Request-to-Case Bridge → roadmap referencing semantic-handoff-emit
  - **spec_ref**: `specs/docs-product-pages-conformance/spec.md#requirement-feature-claims-match-implementation`
  - **files**: `README.md` (line 58), `appinfo/info.xml` (feature lists EN + NL)
  - **acceptance_criteria**:
    - Wording marks the bridge as in development via openspec change `semantic-handoff-emit` (kind-addressed `ns#Case` handoff); no "hand off directly to Procest when ready" present-tense claim

- [ ] 2.3 Duplicate detection re-pointed at OR MDM; CSV-import claim removed
  - **spec_ref**: `specs/docs-product-pages-conformance/spec.md#requirement-feature-claims-match-implementation`
  - **files**: `appinfo/info.xml` (lines 20–21 + NL lines 44), `README.md` (line 48, import/export bullet)
  - **acceptance_criteria**:
    - Duplicate detection described as provided via OpenRegister master-data management (in-app engine deleted in PR#332)
    - No CSV-import claim in EN or NL; vCard claims retained only where code backs them (verify export paths by grep during apply — claim only what exists)

## 3. Overlay & docs/features.json

- [ ] 3.1 Downgrade omnichannel-registratie to beta with reason
  - **spec_ref**: `specs/docs-product-pages-conformance/spec.md#requirement-features-overlay-status-honesty`
  - **files**: `openspec/features.overlay.json`
  - **acceptance_criteria**:
    - `status: "beta"` + reason field (per overlay schema) naming: outbound WhatsApp/SMS send has zero production callers/no UI; `SlaEngineService::dispatchNotification` defers all channels except nextcloud-notification; inbound webhooks wired
    - EN/NL summaries rewritten to inbound registration + consent logging; JSON re-validated after edit

- [ ] 3.2 Fix docs/features.json staleness
  - **spec_ref**: `specs/docs-product-pages-conformance/spec.md#requirement-docsfeaturesjson-matches-shipped-reality`
  - **files**: `docs/features.json`
  - **acceptance_criteria**:
    - kcc-werkplek entry describes the shipped Customer Support workspace (no "not yet implemented")
    - Adjacent entries contradicting HEAD corrected in the same batch (factual status only); JSON re-validated

## 4. Dashboard

- [ ] 4.1 Remove the null Satisfaction KPI from the Operational dashboard
  - **spec_ref**: `specs/dashboard/spec.md#requirement-no-permanently-null-default-widgets`
  - **files**: `src/manifest.json` (widget def ~388, layout slot ~408, template map ~423), `src/registry.js`, `src/views/dashboard/widgets/SatisfactionKpiWidget.vue`
  - **acceptance_criteria**:
    - Widget def, layout slot `id: "17"`, and `widget-satisfaction` mapping removed; grid reflowed (no hole)
    - Component + registry entry removed only if nothing else references them; manifest `_note` records restoration via `customer-satisfaction-closed-loop`
    - No placeholder tile added; AnalyticsService `$responses = []` left for `customer-satisfaction-closed-loop` (no partial CSAT re-sourcing here)

## 5. Demo Seed

- [ ] 5.1 Seeding service + occ command `pipelinq:demo:seed`
  - **spec_ref**: `specs/first-time-setup/spec.md#requirement-req-setup-pip-008--optional-demo-data-seed`
  - **files**: `lib/Service/DemoSeedService.php`, `lib/Command/SeedDemoDataCommand.php`, `appinfo/info.xml` (command registration)
  - **acceptance_criteria**:
    - Mirrors procest `SeedBezwaarBeroepCommand`: Symfony Command, explicit owner context (occ has no session), idempotent via stable demo identifiers
    - Seeds linked demo set (~5 clients person/org, ~6 leads across stages, ~8 requests across statuses, ~12 contactmomenten across channels); klantbeeld-360 + dashboards render populated
    - Demo marking on every object; `--remove` deletes exactly the seeded set; unit tests cover idempotency + removal scoping

- [ ] 5.2 Optional setup-wizard action
  - **spec_ref**: `specs/first-time-setup/spec.md#requirement-req-setup-pip-008--optional-demo-data-seed`
  - **files**: `lib/Controller/SetupController.php` (action `seed-demo-data`), `src/manifest.json` (setup block)
  - **acceptance_criteria**:
    - Admin-only optional action per ADR-042 invoking `DemoSeedService` (same write path as the command); skipping never blocks completion
    - Coordinated with the `first-time-setup` change's SetupController surface — extend it, do not fork it

## 6. Verification

- [ ] 6.1 Tests + gates
  - **spec_ref**: all
  - **files**: `tests/unit/`, `tests/e2e/`
  - **acceptance_criteria**:
    - PHPUnit: seed idempotency/removal; JSON validity checks for overlay + features.json edits
    - Playwright: Operational dashboard renders without the satisfaction tile; wizard offers + runs the demo action (UI clicks); API assertions in Newman
    - `appinfo/info.xml` validates against app-info.xsd; `composer check:strict` green; hydra gates pass
