# Design: adopt-connection-registry

The contract is hydra `openspec/changes/connection-registry/design.md`. This
file records how pipelinq meets it and where its adapters do not fit.

## D1. What is declared, and what is not

A row is a connection an admin can make real or break. The survey of
`development` on 2026-09-14 found these:

| Key | What answers | Why this shape |
|---|---|---|
| `cti` | `Cti\AdapterRegistry`: Asterisk, RingCentral or CallVoip, all real HTTP adapters | One row, not three. One platform is active at a time, so three rows would show two idle rows forever. |
| `social-<network>` | `Social\SocialAdapterRegistry`, seven adapters, all calling through OpenRegister's credential broker | One row per network. Readiness is per network: Threads has no filing, Bluesky is a preview. |
| `berichtenbox` | `BerichtenboxService` and `LogiusConnector`, real HTTPS to Logius | `requiredConfig` names the four keys a dispatch needs. |
| `mail-provider` | `Marketing\Transport\ConnectorSourceTransport`, over an integriq source | One row for the provider route. Pipelinq never tests it, integriq can once a source is linked. |

Not declared:

- **`LogBerichtenboxAdapter`.** It is bound to `BerichtenboxAdapterInterface`
  and injected into nothing (its own header says so, pipelinq#764). A row for
  it would read Simulated forever about a seam no message passes through,
  while the real Berichtenbox path sends for real. The `berichtenbox_adapter`
  app-config key named in the issue brief does not exist in pipelinq.
- **SMS, WhatsApp, payment providers, BI export sinks.** Real adapters, each
  configured per `channelProvider`, PSP or export-sink object. A follow-up.

Keys are frozen once shipped (contract D2), so a later row is additive.

## D2. Statuses, per connection

- **`cti`** has no `requiredConfig` and no `adapter`. The platform is a field
  on the `ctiAdapterConfig` object in OpenRegister, not an app-config key, so
  integriq cannot read it. The row stays on its `unconfiguredMessage` until
  pipelinq reports. `CtiController::testConnection` and a successful
  `updateConfig` report the check, mapped as:
  - a platform is chosen and its adapter loads: `configured`, with a message
    that says the check makes no call to the platform;
  - no platform is chosen: `unconfigured`;
  - the adapter does not load: `error`, with the reason.
- **`social-*`** carry no config keys either. The readiness lives in
  OpenRegister's provider catalogue. `SocialAccountController::index` reports
  it for all seven networks each time the Social accounts page loads:
  - `ready`: `configured`;
  - `preview`: `unavailable`, with the broker's reason (see D5);
  - `not_configured`: `unconfigured`, with the broker's reason.
- **`berichtenbox`** declares `requiredConfig` `logius_client_id`,
  `logius_client_secret`, `pki_cert` and `pki_key`. `BerichtenboxService`
  refuses a dispatch without the PKI pair, and `LogiusConnector` cannot get a
  token without the client pair. Rule 5 then reads Configured once all four
  hold a value. No admin screen writes them, so the row carries no
  `settingsUrl` and its `unconfiguredMessage` names the `occ` command.
- **`mail-provider`** has nothing integriq can read. It reads Not checked yet
  until an admin links the provider's source, after which integriq's health
  job probes it (contract D7).

No row declares `adapter`, because no pipelinq connection picks its adapter
with an app-config key.

## D3. The reporter

`lib/Service/ConnectionReportService.php` follows dossiq's
`IntegrationStatusService` and pipelinq's own `LandingPageProvisioningService`:

- `STATUS_EVENT` is the class name as a string, resolved with `class_exists`
  (ADR-041). Without integriq nothing is sent and nothing is logged.
- `report()` refuses a key outside `KEYS` or a status outside the five, with
  a warning, so a typo never travels to integriq.
- A listener that throws is caught and logged as a warning. It never reaches
  the request that reported.
- `KEYS` equals the declared keys. `ConnectionsDeclarationTest` keeps them
  equal.

The event is built with the parameter names from contract D6, and
`tests/Stubs/Integriq/Event/ConnectionStatusReportedEvent.php` mirrors them.

## D4. The page

- `src/manifest.d/97-connection-registry.json` adds the page and the menu
  entry, so the monolith `manifest.json` and its setup-wizard block called
  Integrations (Shillinq and XWiki URL fields) stay untouched.
- The page is contract D8 verbatim: id `Integrations`, route
  `/settings/integrations`, `requiresApp: integriq`, `integriq/app_connection`,
  `showAdd: false`, the five dossiq columns and the status folder sidebar.
- The menu entry `ConnectionsMenu` is labelled Connections, because it sits
  directly under the existing Integrations caption in the settings foldout
  (order 210, between the caption at 205 and BI export at 215). Two lines
  reading Integrations would say nothing. It carries `query: {app: pipelinq}`,
  `permission: admin` and `visibleIf.appInstalled: integriq`.
- `permission: admin` is advisory in pipelinq today: `App.vue` passes
  `OC.currentUser.permissions`, which is empty, and CnAppNav shows every item
  then. The gate that holds is integriq's admin-only schema (contract D3).
- Add integration is a header action whose handler is
  `openIntegriqConnections` in `src/services/connectionRegistry.js`. CnIndexPage
  resolves a handler name only against `customComponents`, which pipelinq did
  not pass to CnAppRoot, so `App.vue` now passes a map holding that one
  function. CnAppRoot logs a one-time deprecation warning for a
  `customComponents` prop beside a v2 manifest.
- `connectionStatus` and `connectionSettingsLabel` pass to CnAppRoot through
  its `formatters` prop. nextcloud-vue 2.39.0, the version pipelinq's lock
  installs, has neither as a built-in. The local copies go once a release
  that ships them is in the lock.
- `settingsUrl` anchors: `CtiPage.vue` gets `id="section-cti"` and
  `DeliverabilitySettings.vue` gets `id="section-mail-transports"`. The social
  rows link to `/apps/pipelinq/social-accounts`, the route
  `78-social-publishing.json` declares.

## D5. Where the contract does not fit, as proposed amendments

1. **A choice stored outside app config.** CTI picks its adapter with a field
   on an OpenRegister object, not an app-config key naming a class. Neither
   `requiredConfig` nor `adapter.configKey` can express it, so the status only
   ever arrives as a report. Proposed: a declaration field such as
   `reportedOnly: true`, so integriq can say "Waiting for pipelinq to check"
   instead of the generic default.
2. **A connection that works in part.** The broker's `preview` state means a
   post is attempted and the network may refuse it (Bluesky, until DPoP
   lands). The five statuses have no word for that. Pipelinq reports
   `unavailable` with the broker's reason, which understates a network that
   sometimes works rather than overstating one that often fails. Proposed: a
   sixth status, `limited`.
3. **A key set by `occ` has no refresh moment.** The Berichtenbox keys are
   written only with `occ config:app:set`, and the resolver runs after a sync,
   a report, a probe or a link. A row can stay Not configured until the next
   sync after the keys are set. Proposed: the hourly health job resolves every
   row without a source as well, or integriq listens for an app-config change.
4. **One connection family, many objects.** SMS, WhatsApp, payment providers,
   BI export sinks and mail transports are configured per object, and a tenant
   can hold several. A static file can only declare the family. Proposed: a
   declared connection may name the schema whose objects are its instances.

## Risks

- **Seven reports per Social accounts page load.** Each makes integriq write
  one `lastReport`. The page is a marketer's configuration page, not a hot
  path, and the listener never throws into the sender.
- **CTI reads Configured without calling the platform.** The message says so
  plainly. A real call needs credentials the check does not use today.
- **Named arguments on an event pipelinq cannot see.** If integriq ships other
  parameter names, `send()` catches the error and the report is lost, not the
  request.
