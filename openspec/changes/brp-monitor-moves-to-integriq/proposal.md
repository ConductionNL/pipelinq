# The BRP monitor moves to integriq

Ruben's decision of 2026-10-09, taken while reviewing the pipelinq screens: the BRP monitor (board `PqBrpMonitor`: query performance and certificate expiry) moves entirely to integriq, because connections and their health belong there. Certificates and credentials of any connection live in keepiq; integriq references the keepiq secret and shows its expiry with a link to keepiq to renew it. pipelinq keeps only doing the BRP lookups, through integriq.

This is pipelinq's share. The counterpart changes are:

- ConductionNL/integriq `brp-connection-monitor-from-pipelinq`: a health view per connection with query performance and certificate expiry, the `brp-haalcentraal` source holding pipelinq's settings, and mTLS material by keepiq reference.
- ConductionNL/keepiq `connection-certificate-expiry-for-integriq`: keepiq tells integriq the expiry of a certificate it references, and offers a renew link.
- ConductionNL/design-system: board `PqBrpMonitor` becomes `integriq/IqBrpMonitor`, in integriq's header and side bar.

## Why

pipelinq runs its own BRP connection today, next to integriq's.

- **Settings.** `BrpAdminController` (`/api/brp/settings`) stores the connection in pipelinq's app config: `brp.base_url`, `brp.oauth_endpoint`, `brp.client_id`, `brp.client_secret_encrypted` (encrypted with `ICrypto`), and the mTLS material as **file paths** on the server: `brp.cert_path`, `brp.key_path`, `brp.ca_bundle`.
- **Transport.** `HaalCentraalClient` already tries integriq's `brp-haalcentraal` source first (the OR leaf), and falls back to a direct OAuth2 plus mTLS call with the settings above.
- **Monitoring.** `BrpHealthCheckJob` reads the certificate file, checks `notAfter`, notifies administrators at 30, 14 and 7 days and caches the result in `brp.cert_health`. `BrpMonitorJob` aggregates the last 24 hours of `bsnAuditRecord` (lookups, cache hits, errors, response time) into `brp.monitor_report`. `BrpController::monitor` (`GET /api/brp/monitor`) serves both to the `BrpMonitor` page (`src/views/admin/BrpMonitor.vue`, manifest page `BrpMonitor` at `/admin/brp-monitor`).

So a certificate lives as a file on the pipelinq host, its expiry is watched by pipelinq, and the connection's health is invisible in integriq, where every other connection is watched.

## What changes

- **The connection settings move to integriq.** A repair step writes pipelinq's `brp.base_url`, `brp.oauth_endpoint` and `brp.client_id` into integriq's `brp-haalcentraal` source. The client secret and the certificate, key and CA bundle go into keepiq through the credential broker; the source holds only the references. The pipelinq keys are then cleared.
- **The direct fallback is retired.** `HaalCentraalClient` calls integriq's source only. When integriq is absent or the source is not set up, a lookup fails with a clear message naming integriq, and nothing is called directly.
- **The monitor leaves pipelinq.** `BrpHealthCheckJob`, `BrpMonitorJob`, `GET /api/brp/monitor`, the `BrpMonitor` page and the BRP part of the admin settings go. integriq shows the connection's performance and certificate expiry.
- **What stays in pipelinq:** the lookup itself (`POST /api/brp/lookup`), the doelbinding modal, BSN validation and masking, the Wet BRP audit trail (`bsnAuditRecord`), the cache (`brp.cache_ttl_hours`), retention (`BrpRetentionJob`, `brp.retention_days`), who may look up (`brp.allowed_groups`), the pseudonym secret, address reveal, opt-out and the mutation webhook.

## Capabilities

### Modified capabilities

- `brp-lookup`: lookups go through integriq only; connection settings, credentials and health are integriq's and keepiq's.

## Impact

- Removed: `lib/BackgroundJob/BrpHealthCheckJob.php`, `lib/BackgroundJob/BrpMonitorJob.php` (and their `info.xml` entries), `BrpController::monitor` and its route, `src/views/admin/BrpMonitor.vue`, the `BrpMonitor` page in `src/manifest.json` and its registry entry, the connection fields in `BrpAdminController`.
- Changed: `lib/Service/HaalCentraalClient.php` loses the direct OAuth2 and mTLS path.
- New: `lib/Repair/MoveBrpConnectionToIntegriq.php`.
- **pipelinq now needs integriq for BRP lookups.** It mostly did already; the fallback goes.
- **Order.** Build after integriq can hold mTLS material by keepiq reference, and keepiq can report the expiry (the counterpart changes).
- Feature tier: V1.
