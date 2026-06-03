<!--
SPDX-License-Identifier: EUPL-1.2
SPDX-FileCopyrightText: 2026 Conduction B.V.
-->

# Deployment checklist — customer-portal

## 1. Register creation (automatic)

The portal lives in a **separate** OpenRegister register, `pipelinq-portal`,
defined by the fragment `lib/Settings/register.d/40-portal.json` (ADR-037). It
is imported automatically on app upgrade by the existing repair step
(`lib/Repair/InitializeRegister.php` pattern) via
`SettingsLoadService::loadSettings()`. After deploy, verify the register and
schema ids were stored:

```
occ config:app:get pipelinq portal_register
occ config:app:get pipelinq portalAccount_schema
```

Both must be non-empty. If empty, run `occ pipelinq:settings:reimport` (or the
Settings → Reimport action) once OpenRegister and Pipelinq are both enabled — the
register import runs in a repair step so OR autoloaders are available.

## 2. Database migrations

None. All portal storage is OpenRegister-object-backed in the
`pq_portal_*`-prefixed tables OR creates for the `pipelinq-portal` register.

## 3. Bundled JS

The portal SPA ships as a new webpack entry `pipelinq-portal.js`
(`src/portal.js`). Rebuild the frontend (`npm run build`) and confirm
`js/pipelinq-portal.js` exists. `appinfo/info.xml` `<version>` was bumped to
`0.3.0` so the immutable JS cache is busted for existing tabs.

## 4. Feature flag (per tenant)

The portal is configured per tenant via `portal_tenant_config`. Until a tenant
config exists the portal serves sane defaults (cobalt branding, all read
features). To disable a tenant's portal, set its `enabledFeatures: []` and hide
the route, or simply do not link `/apps/pipelinq/portal` from the tenant site.

## 5. Backup

Recommended before first production deploy: back up the OpenRegister database
(the portal register is additive and rollback-safe — removing the register and
the `/portal/*` routes fully reverts the feature).

## 6. Smoke tests (run against a live instance — DEFERRED here)

These require a running Nextcloud + OpenRegister and seeded data; they are listed
for the deploying operator and were NOT executed in the build worktree:

- [ ] `GET /apps/pipelinq/portal` serves the portal SPA shell (200, public).
- [ ] `GET /apps/pipelinq/portal/api/tenant-config` returns branding (no auth).
- [ ] `POST /apps/pipelinq/portal/api/auth/login` with seeded credentials returns a token.
- [ ] An authenticated `GET /portal/api/invoices` returns only the customer's own invoices.
- [ ] A guessed invoice id from another customer returns 404 (IDOR check).
- [ ] `POST /portal/api/requests` creates a request and starts the SLA clock.
- [ ] Document download via a signed URL works inside the 5-min TTL and 410s after.
- [ ] Brand colour below 4.5:1 contrast is rejected (422) in the admin panel.
- [ ] `occ pipelinq:portal:cleanup` runs without error.

## 7. Rollback

Delete the `pipelinq-portal` register (and its `pq_portal_*` tables), drop the
`/portal/*` routes, and redeploy the prior version. No core Pipelinq schema or
Nextcloud user data is touched.
