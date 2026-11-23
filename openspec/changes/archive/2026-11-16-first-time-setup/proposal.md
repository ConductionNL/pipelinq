# Proposal: first-time-setup

kind: feature — adopts the **abstract first-time setup wizard** (hydra ADR-04x, `@conduction/nextcloud-vue` `CnSetupWizard` + manifest `setup` block) for pipelinq. Written FIRST as a per-app requirements source for the central nc-vue change. pipelinq is the **lightweight** case (one required choice, the rest optional).

## Summary

pipelinq is largely self-initialising (schema provisioning via `pipelinq_register.json`, a few migration repairs) and has **no user-facing currency choice today** — reporting assumes EUR. The one thing an operator should pick before running commercial reports is the **currency**; everything else (register/schema mapping, optional ingest) is non-blocking.

This change declares pipelinq's setup as a manifest `setup` block whose only REQUIRED step is the **currency** choice, plus optional `config-fields` (register mapping) and a `done` summary.

**What changes (pipelinq side):**

1. **`manifest.setup`** — steps: `welcome` (info), `currency` (choice, REQUIRED — writes a new `currency` app-config key, default EUR), `register-mapping` (config-fields, optional), `done` (summary). `completionConfigKey: setup_completed_version`.
2. A first-class **`currency`** app-config key (ISO-4217) consumed by the commercial/forecast reporting and the dashboard `stat`/`chart` widgets' currency formatting.
3. **`SetupController`** — `GET /apps/pipelinq/api/setup/status` + `POST /apps/pipelinq/api/setup/action/{actionId}` (admin-only) for status reporting and any optional server-side actions (e.g. ingest product/vendor master).

Depends on / requirements source for `hydra/openspec/changes/manifest-setup-wizard` + `nextcloud-vue` `cn-setup-wizard`.
