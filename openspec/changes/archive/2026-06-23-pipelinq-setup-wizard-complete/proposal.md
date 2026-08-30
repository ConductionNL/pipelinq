# Proposal: pipelinq-setup-wizard-complete

kind: code — completes pipelinq's ADR-042 first-time setup wizard. The wizard shipped with a single meaningful step (reporting currency); this change adds the remaining steps a fresh install genuinely needs and the backend that persists/reports them.

## Why

The `first-time-setup` change declared a 3-step wizard (Welcome → Currency → All set). Currency is the only required input and that is correct. But a fresh operator still benefits from confirming that the OpenRegister data was provisioned, naming the organisation, and connecting optional integrations — all things the app actually consumes but never surfaced in setup. A gap analysis of pipelinq's config surface (see `design.md`) shows most setup is auto-seeded on install by the `InitializeSettings` repair step, so the additions are deliberately minimal: one re-runnable provisioning action plus two optional, skippable config-field steps. No invented config.

## What changes

1. **`manifest.setup.steps`** grows from 3 to 6 steps (all declarative, rendered by the abstract `CnSetupWizard`):
   - `welcome` (info) — unchanged intent, refreshed copy.
   - `currency` (choice, **required**) — unchanged; the single gating step.
   - `provision` (run-action `provision-register`, optional) — re-run the install-time register/schema import + default pipelines/queues/skills. For installs where OpenRegister was enabled after pipelinq.
   - `organisation` (config-fields, optional) — organisation name / VAT / KvK, consumed by POS receipts + reports (`receipt_company_*`).
   - `integrations` (config-fields, optional) — Shillinq base URL (`shillinq_app_url`) + XWiki base URL (`xwiki_direct_url`).
   - `done` (summary) — unchanged.
2. **`SetupController`** — `status()` now reports per-step done state for all four non-info/summary steps; `runAction('provision-register')` implements idempotent provisioning by delegating to `SettingsService` (same calls as the `InitializeSettings` repair step). Currency remains the only completion gate.
3. Persistence reuses the existing `POST /api/setup/config` (config-fields/choice) and `POST /api/setup/action/{actionId}` (run-action) contract — no new routes.
