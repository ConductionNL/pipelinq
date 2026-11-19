# Design — pipelinq first-time setup

## Steps (manifest `setup.steps[]`)

| id | type | required | what it does |
|----|------|----------|--------------|
| `welcome` | `info` | no | intro |
| `currency` | `choice` | **yes** | ISO-4217 currency (default EUR) → writes `currency` app-config key |
| `register-mapping` | `config-fields` | no | review/confirm OR register/schema mapping (existing `CnRegisterMapping` config) |
| `done` | `summary` | no | health recap + links to Dashboard / Leads |

Only `currency` is required, so pipelinq exercises the "single required step + otherwise non-blocking" path — the lightweight end of the spectrum (contrast shillinq's three required steps).

## Why currency is the required choice

pipelinq's commercial dashboard already uses declarative `stat`/`chart` widgets with currency formatting (e.g. `format: { style: "currency", currency: "EUR" }`). Hard-coding EUR is wrong for non-EUR tenants; the wizard captures the currency once into a `currency` app-config key that the widgets and reports read via `loadState`/initial-state. This is the pipelinq analogue of shillinq's region choice — a single app-level scalar that downstream rendering depends on.

## Server-side contract

- `GET /apps/pipelinq/api/setup/status` → `{ version, completed, steps }`. `currency.done` = `currency` config key set.
- `POST /apps/pipelinq/api/setup/action/{actionId}` (admin-only, CSRF) for optional server-side actions (e.g. `ingest-product-vendor-master`). pipelinq has no required server-side seed.

## Reuse / not rebuild

- `register-mapping` reuses the existing `CnRegisterMapping` / admin Settings config.
- Currency formatting already exists in the dashboard widgets — this change only sources the value from config instead of a literal.
- Wizard chrome / gating / admin entry come from the central `CnSetupWizard`.

## Requirements this surfaces for the central feature

- A `choice` step whose value feeds runtime rendering (currency formatting), confirming the value must be exposed via initial-state/`loadState` for widgets to read.
- The "single required step, rest optional" shape — the minimal end of the gating model.
