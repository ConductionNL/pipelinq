# Design — pipelinq setup wizard, completed

## Gap analysis: what the wizard asked vs what a fresh install needs

### What the wizard asked (before this change)

| Step | Type | Required | Configures |
|------|------|----------|-----------|
| `welcome` | info | — | nothing |
| `currency` | choice | **yes** | `currency` app-config (ISO-4217) |
| `done` | summary | — | nothing |

So the only thing the wizard actually captured was the reporting currency.

### What a usable fresh pipelinq install needs

Derived from the app's real config surface: the `InitializeSettings` repair step (`lib/Repair/InitializeSettings.php`), `SettingsService::CONFIG_KEYS` / `TUNABLE_DEFAULTS`, the manifest dashboard widgets, and the dependency block.

| Concern | Status | Decision |
|---------|--------|----------|
| **Reporting currency** | required, not auto | **Ask** — keep as the single gating step. |
| **OpenRegister register + schemas** | auto-seeded on install (`InitializeSettings` → `SettingsService::loadSettings`, writes the `register` config key) | **Offer a re-runnable action**, don't ask. The repair step skips provisioning when OpenRegister is not installed at pipelinq-install time, so an admin who enables OR afterwards has no register. A `run-action` lets them provision from the wizard instead of a CLI `occ maintenance:repair`. Idempotent. |
| **Default sales pipeline + stages** | auto-seeded (`DefaultPipelineService::createDefaultPipelines`) | Covered by the same provisioning action; not a separate question. |
| **Default service queues + agent skills** | auto-seeded (`DefaultQueueService`) | Same as above. |
| **Default lead sources + request channels** | auto-seeded (`SystemTagService::ensureDefaults`) | Same as above. |
| **Organisation name / VAT / KvK** | not auto; consumed by POS receipts + reports (`receipt_company_name`/`_vat`/`_kvk` tunables) | **Ask (optional)** — a `config-fields` step. Useful org metadata, skippable. |
| **Shillinq integration** | not auto; `shillinq_app_url` resolves the recurring-revenue widget + billing/ledger entry points (ADR-019 registry) | **Ask (optional)** — `config-fields`. Empty disables the integration. |
| **XWiki knowledge base** | not auto; `xwiki_direct_url` enables the embedded KB widget | **Ask (optional)** — `config-fields`. Empty disables it. |
| **OpenConnector / Deck / workflowengine / timemanager / forms** | hard dependencies; gated by `CnAppRoot`'s dependency-check phase, not setup | Not asked — dependency phase handles install/enable prompts. |
| **Locale / timezone** | sourced from Nextcloud's per-user settings; pipelinq has no own key | Not asked — would be dead config. |
| **Seed/demo data** | no production seeding path (only the dev `clean-env` import) | Not asked. |

### Required vs optional vs auto-seeded

- **Required (gates the app):** currency only — unchanged, matches REQ-SETUP-PIP-001.
- **Optional (skippable, non-gating):** provision-register action, organisation, integrations.
- **Auto-seeded on install (not asked):** register + schemas, default pipelines/stages, queues, skills, lead sources, request channels.

## What was added + how each persists

| Step | Type | Persistence | Config key(s) written |
|------|------|-------------|-----------------------|
| `provision` | `run-action` | `POST /api/setup/action/provision-register` → `SetupController::provisionRegister()` → `SettingsService::loadSettings(false)` + `createDefaultPipelines/Queues/Skills()` | writes `register` (+ portal/sla register ids) via `SettingsLoadService` |
| `organisation` | `config-fields` (JSON Schema) | `POST /api/setup/config` → `SetupController::saveConfig()` writes each posted key | `receipt_company_name`, `receipt_company_vat`, `receipt_company_kvk` |
| `integrations` | `config-fields` (JSON Schema) | same `saveConfig()` path | `shillinq_app_url`, `xwiki_direct_url` |

`status()` reports done state for each: `currency` (key set), `provision` (`register` key set), `organisation` (`receipt_company_name` set), `integrations` (either URL set, or neither integration app installed). Only `currency` drives `completed` + `setup_completed_version`.

## Reuse, not rebuild

- Wizard chrome / gating / step rendering: the abstract `CnSetupWizard` (nc-vue). No app-side renderer.
- `provision-register` delegates to the **same** `SettingsService` methods the `InitializeSettings` repair step already calls — no duplicated provisioning logic.
- `saveConfig` already wrote arbitrary posted keys; the new `config-fields` keys ride that path with no controller change.

## Not in scope

- Wiring the configured `currency` into the dashboard widgets' `format.currency` (the widgets still hard-code `"EUR"`). That is REQ-SETUP-PIP-002 from the original `first-time-setup` change and is a separate rendering concern; this change only completes the wizard's capture surface.
