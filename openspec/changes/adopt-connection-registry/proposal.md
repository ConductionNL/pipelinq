# Proposal: adopt-connection-registry

## Why

Pipelinq talks to a telephony platform, seven social networks, Logius
Berichtenbox and a bulk mail provider. No page tells an admin which of those
connections are set up, which were checked, and which failed.

The hydra umbrella change `connection-registry` gives every app that page
through integriq. An app declares its connections in one static file,
integriq keeps one row per connection and works out each status, and every
app shows its own rows on the same index page. Dossiq adopted first
(dossiq#2715). This change is pipelinq's adoption, tracked in pipelinq#1939.

## What changes

- New `lib/Settings/connections.json` with ten connections: `cti`, one row
  per social network (`social-mastodon`, `social-bluesky`, `social-linkedin`,
  `social-x`, `social-facebook`, `social-instagram`, `social-threads`),
  `berichtenbox` and `mail-provider`.
- A new admin-only Integrations page at `/settings/integrations`, an `index`
  page over `integriq/app_connection`, preset to `app=pipelinq` through its
  menu entry. The entry sits in the settings foldout under the existing
  Integrations caption and only renders when integriq is installed.
- An Add integration header action that opens integriq's Connections
  overview with `app=pipelinq&link=1`.
- `ConnectionReportService` sends `ConnectionStatusReportedEvent` for the two
  checks pipelinq already runs: the CTI Test connection (also run after a CTI
  settings save) and the social network readiness the Social accounts page
  loads. The event class is named by string and sent only when integriq ships
  it.
- Local `connectionStatus` and `connectionSettingsLabel` formatters, until
  nextcloud-vue ships them in a version pipelinq installs.

## Depends on

- hydra `openspec/changes/connection-registry` (merged, hydra#667): the
  contract, design D2 to D9.
- integriq `connection-registry` (merged, integriq#1996): the schema, the
  sync, the report listener and the Connections overview.

Without integriq the page shows the missing-dependency screen, the menu entry
stays hidden, and pipelinq sends nothing. No pipelinq request fails because
of it.

## Out of scope

- SMS, WhatsApp, payment providers and BI export sinks. They are real
  adapters on `development`, each configured per object rather than per app.
  They are a follow-up issue, named in the PR.
- The `LogBerichtenboxAdapter` seam. It is bound in `Application::register()`
  and injected into nothing, so it answers no call and is not a connection.
- Reporting from the Berichtenbox dispatch job or from a mail send. Both see
  real outcomes, and both carry personal data close to the error text. That
  is its own change.
- A `ConnectionRefreshRequestedEvent` sender. No settings save in pipelinq
  writes a key this file declares, so no save has anything to refresh.

## Rollback

Revert this change. Integriq keeps rows for pipelinq that nothing links to,
and they hold no credentials. Nothing in pipelinq's own register changes.
