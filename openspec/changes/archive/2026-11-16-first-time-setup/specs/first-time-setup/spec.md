# first-time-setup Specification

**Status:** proposed
**Scope:** pipelinq
**Tier:** V1
**Depends on:** the abstract setup wizard (hydra ADR-04x, `@conduction/nextcloud-vue` `CnSetupWizard` + manifest `setup` block + CnAppRoot `setup` phase). Written FIRST as a requirements source for that central change.

## Purpose

Give pipelinq a minimal first-time setup: require the operator to pick a reporting **currency** (today hard-coded EUR) before commercial reports/widgets render, with register mapping and any ingest left optional. This is the lightweight end of the gating model — exactly one required step.

## ADDED Requirements

### Requirement: REQ-SETUP-PIP-001 — Currency Is The Single Required Setup Choice

pipelinq SHALL declare a `setup` block whose `currency` step is `required: true` and writes an ISO-4217 `currency` app-config key (default `EUR`); all other steps (`register-mapping`, optional actions) SHALL be optional and non-gating.

#### Scenario: App is gated only on currency

- **GIVEN** pipelinq is enabled with no `currency` configured
- **WHEN** an admin opens the app
- **THEN** `CnSetupWizard` SHALL gate the shell on the `currency` step only
- **AND** once `currency` is set the app SHALL be usable without further required steps

### Requirement: REQ-SETUP-PIP-002 — Configured Currency Drives Reporting

pipelinq SHALL source the dashboard/report currency from the `currency` app-config value (exposed via initial-state) instead of a hard-coded literal, so commercial `stat`/`chart` widgets format amounts in the chosen currency.

#### Scenario: Dashboard formats amounts in the chosen currency

- **GIVEN** an admin set `currency` to a non-EUR value
- **WHEN** the commercial dashboard renders revenue/forecast widgets
- **THEN** the amounts SHALL be formatted in the configured currency

### Requirement: REQ-SETUP-PIP-003 — Setup Status Is Reported For The Wizard

pipelinq SHALL expose `GET /apps/pipelinq/api/setup/status` returning `{ version, completed, steps }` where `currency.done` reflects the `currency` key being set, and SHALL write `setup_completed_version` once the required step is done.

#### Scenario: Completion after currency chosen

- **GIVEN** `currency` is set
- **WHEN** the wizard re-queries status
- **THEN** `completed` SHALL be true and the wizard SHALL stop gating
