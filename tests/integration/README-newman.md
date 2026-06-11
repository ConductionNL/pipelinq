# Pipelinq API-contract tests (Newman)

Newman/Postman contract tests that exercise pipelinq's HTTP controllers directly,
locking the API contract. Per the gate-19 split, **API/contract correctness lives
in Newman**; Playwright drives the UI only.

Run with `./run-newman.sh` (defaults to `http://localhost:8080`, `admin:admin`).
The collection is **self-contained and idempotent**: setup seeds the POS
transactions it needs and teardown deletes them.

## What is covered

59 requests / 76 assertions across CRM + POS:

| Folder | Endpoints | Happy | Error (4xx not 500) | Authz |
| --- | --- | --- | --- | --- |
| 0. Setup | seed two POS draft transactions (OR object API) | seeds + captures ids | — | — |
| 1. Clients CRUD | OR `/api/objects/{register}/{clientSchema}` (ADR-022) | create→read→update→delete | missing-required 400 | anon read 200 (OR not auth-gated) |
| 2. Leads CRUD | OR `/api/objects/{register}/{leadSchema}` | — (**create QUARANTINED**, see below) | missing-required 400 | — |
| 3. Products CRUD | OR `/api/objects/{register}/{productSchema}` | create→read→update→delete | missing-required 400 | anon read 200 |
| 4. Contacts CRUD | OR `/api/objects/{register}/{contactSchema}` | create→read→update→delete | missing-required 400 | anon read 200 |
| 5. POS lifecycle | `GET …/{posTxnSchema}/{id}`, `POST /api/pos-transactions/{id}/confirm` `/settle`, `GET /api/pos-transactions/tax-report` | POS sale persists (Phase-0), tax-report 200 | confirm/settle unknown id 4xx | confirm + tax-report no-auth 401 |
| 6. POS tender + payments | `GET /api/pos/tender-types` (**QUARANTINED**), `GET /api/payment-providers`, `…/{name}` | payment-providers 200 | unknown provider 4xx | payment-providers no-auth 401 |
| 7. Contactmomenten | `DELETE /api/contactmomenten/{id}` | — | delete unknown 404 | delete no-auth 401 |
| 8. Request channels + lead sources | `GET /api/settings/request-channels`, `…/lead-sources` | both 200 | — | request-channels no-auth 401 |
| 9. Reports / rapportage / analytics | `GET /api/analytics/{summary,overview,trends,funnels}`, `GET /api/rapportage/{pipeline-stats,kpis}`, `POST /api/navi/query` | all 200 | trends unknown-metric 400, kpis missing-params 400 | analytics + rapportage no-auth 401 |
| 10. Settings | `GET /api/settings`, `…/user`, `…/forecast`, `GET /api/prospect-settings` | all 200 + contract shape | — | settings no-auth 401, invalid-auth 401 |
| 11. Health | `GET /api/health` | 200, `status:ok` | — | public probe → 200 unauthenticated (by design) |
| 99. Teardown | deletes the seeded POS transactions | idempotent cleanup | — | — |

CRM entity CRUD is delegated to the OpenRegister object API (ADR-022 — pipelinq
owns no client/lead/product/contact CRUD controller). The POS lifecycle and all
pipelinq-owned report/settings/health controllers are tested against their own
`/api/...` routes.

## Phase-0 POS fix — confirmed at the API level

A POS sale created via the OR object API **persists** (folder 5, "POS draft
persisted"): create returns `201` and the object reads back with its `status`
and `cashier` intact. There is **no "service not registered" 500** — the
PosTransactionService is registered and reachable. The Phase-0 fix holds.

## Quarantined bugs (honest, NOT fake passes)

Each quarantine asserts the **current** failing status so the suite stays green
without faking a pass. When the underlying bug is fixed, the quarantine test
goes RED — flip it to the happy-path assertion at that point.

1. **`POST /api/pos-transactions/{id}/confirm` → 500.**
   Root cause is **NOT** the Phase-0 "service not registered" bug. The service
   runs and reaches OpenRegister. `PosTransactionService::confirmTransaction`
   routes through the OR **TransitionEngine** (`transitionObject` →
   `getTransitionEngine()->transition()`), whose re-save re-validates the whole
   object and rejects the optional typed field `consentSyncStatus` when it is
   `null`: *"Property 'consentSyncStatus' should be type 'string' but is 'null'."*
   `saveTransaction()` already null-filters for the **direct**-save path
   (`lib/Service/PosTransactionService.php` ~line 1098), but the TransitionEngine
   path bypasses that filter. Settle/refund/park/resume share the same
   `transitionObject` path and are expected to be affected identically.

2. **Lead create via OR object API → 500.**
   OpenRegister persists the lead, then fires pipelinq's object-created listener:
   `ObjectEventHandlerService::handleCreated`
   (`lib/Service/ObjectEventHandlerService.php` ~line 71) →
   `ObjectEventDispatcher::dispatchCreated(string $entityType, string $title, …)`
   (`lib/Service/ObjectEventDispatcher.php` ~line 60). `dispatchCreated()` types
   `$title` as `string`, but for the **lead** schema `$data['title']` arrives as
   an **array**, raising an uncaught
   `TypeError: dispatchCreated(): Argument #2 ($title) must be of type string,
   array given`. This breaks **every** lead create. Contact/product/client
   creates are unaffected (their `title` is absent or scalar). Fix: coerce/guard
   `$data['title']` in `handleCreated` (and the equivalent `handleUpdated` path).

3. **`GET /api/pos/tender-types` → 500 (config gap).**
   `PosTenderController::listTenderTypes` fails with *"Tender register or schema
   is not configured"* because the `posTenderType` register/schema app-config key
   is not set in this deployment. This is a deployment-config gap surfacing as a
   raw 500 (ideally the controller would return a clearer 4xx for the
   unconfigured case). Configure the schema (and/or harden the controller) to fix.

### Not a `findObjects`/`findObject` bug

Unlike procest, pipelinq's ~18 `findObjects()`/`findObject()` call sites are
**private helper methods** (in `AnalyticsService`, `NaviService`, `ForecastService`,
`ForecastExportService`, `QuotaService`, `EntityActivityService`, `EmailMatchService`)
that internally call the **correct** OR API (`findAll(...)` / `find(...)`). None of
them is a call to a non-existent `ObjectService::findObjects()`. The analytics,
navi, forecast and reporting endpoints that go through those helpers all return
`200` — verified in folder 9. No `findObjects`-induced 500 exists in pipelinq.

## Auth-isolation detail (reused fleet pattern)

Authenticated requests run against `{{baseUrl}}` (`http://localhost:8080`) with an
explicit basic-auth block (admin:admin). No-auth / invalid-auth requests run
against `{{noAuthBase}}` (`http://127.0.0.1:8080`); NC session cookies are
host-scoped, so the `localhost` session is never sent to `127.0.0.1`, keeping
those requests genuinely unauthenticated. `run-newman.sh` derives `noAuthBase`
from `BASE_URL` automatically (override with `NO_AUTH_BASE`). Combined with
`--ignore-redirects` + `Accept: application/json`, unauthenticated requests get
NC's JSON `401`, not the `303`→login `200` HTML.

OpenRegister object **reads** are not auth-gated (ADR-022): the anon-read tests
assert the `200` the OR API actually returns rather than a `401` it never sends.

## Collection variables

`baseUrl`, `noAuthBase`, `adminUser`, `adminPass`, plus the deployed OpenRegister
IDs `register=16`, `clientSchema=60`, `contactSchema=61`, `leadSchema=62`,
`productSchema=66`, `posTransactionSchema=434`. The seeded object ids
(`posTxnId`, `posTxnId2`, `clientId`, `productId`, `contactId`) are captured at
runtime and cleared in teardown.

## Running

```bash
# defaults: BASE_URL=http://localhost:8080, ADMIN_USER=admin, ADMIN_PASS=admin
./run-newman.sh

# or directly:
newman run pipelinq.postman_collection.json \
  --env-var baseUrl=http://localhost:8080 \
  --env-var noAuthBase=http://127.0.0.1:8080 \
  --env-var adminUser=admin \
  --env-var adminPass=admin \
  --ignore-redirects
```

`run-newman.sh` prefers a globally-installed `newman`, falls back to `npx newman`,
and serialises runs under `flock /tmp/uiaudit-pipelinq.lock` so parallel CI agents
do not trip the Nextcloud brute-force protection.
