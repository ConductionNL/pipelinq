# Proposal: beta-surface-alignment

## Problem

Pipelinq's public/marketing surfaces (the conduction.nl product page, its NL
translation, and `docs/intro.md`) described a different product than what is
actually shipped. Verified against `lib/`, `src/manifest.json`, and
`lib/Settings/pipelinq_register.json` at HEAD:

- Claimed **DocuDesk integration** ("Quotes via DocuDesk templates, signed
  with your instance certificate") — zero references to DocuDesk anywhere in
  `lib/` or `src/`. Not implemented.
- Claimed **OpenConnector links to Exact, Twinfield, AFAS** — zero references
  to Twinfield or AFAS in the codebase; "Exact" only matches the English word
  "exact" in unrelated prose. Not implemented. The real accounting handoff is
  a `Shillinq` handoff (`ShillinqApService.php`, the onboarding walkthrough's
  `send-to-shillinq` step), an in-house Conduction app, not a third-party
  accounting connector.
- Claimed a **"LaunchPad" BI app** reading Pipelinq registers — no such app
  exists in the Conduction fleet (the closest thing, `mydash`, has no
  OpenRegister/OpenConnector dependency per fleet policy and is never
  referenced by Pipelinq). Pipelinq's dashboards (`Sales overview`,
  `Operational overview`) are built into the app itself
  (`src/manifest.json` pages `Dashboard` / `OperationalDashboard`).
- Claimed **four registers named "customers, prospects, deals, quotes"** —
  the actual OpenRegister schemas are `client`, `contact`, `lead`, `request`,
  `contract`, `contactmoment`, `complaint`, `product`, `pipeline`, `queue`,
  `skill`, `task`, `relationship` (`lib/Settings/pipelinq_register.json`).
  None are named "prospects", "deals", or "quotes".
- Claimed **"per-team filters via Nextcloud groups"** for the lead pipeline —
  no `IGroupManager`/group-filter code found scoped to leads.
- `docs/installation.md` claimed **Nextcloud 29+** while
  `appinfo/info.xml` declares `min-version="28"`.
- `appinfo/info.xml` had no `<dependency>`-style comment documenting the
  real, code-verified dependency on OpenRegister (heavy use throughout
  `lib/`) and Deck (used as the leaf board provider for the pipeline kanban
  via `linkedType: "deck"` entries), even though the description already
  said "Requires: OpenRegister".

This matches a fleet-wide pattern (see other apps' `beta-surface-alignment`
changes): product pages authored ahead of/detached from implementation,
containing fabricated integrations and standards claims that would be beta
blockers if shipped unverified.

## Canonical feature vocabulary (verified against code)

1. **Clients & contacts** — `client`/`contact` OpenRegister schemas, 360°
   client view (`ClientDetail` page with `relatedCollections` +
   `summaryAggregates`), two-way Nextcloud Contacts sync via CardDAV.
2. **Lead pipeline / deal-flow** — `lead` schema, drag-and-drop kanban
   (`Pipeline` page, `Pipelines` admin config page), configurable stages,
   values, and close probabilities; Sales dashboard widgets
   (`pipeline-by-stage`, `revenue-over-time`, `win-rate`).
3. **Quotes (→ contracts)** — `contract` schema, `ContractController` /
   `ContractService`: a quotation and its contract are the same record; once
   accepted it carries lines/price/client into an active contract; recurring
   -revenue metrics (`RecurringRevenueService`) feed the dashboard. No
   cryptographic/PDF signing or DocuDesk template step exists in code — the
   walkthrough's "signable quotation" language is UX copy, not an
   e-signature feature, so marketing copy must not claim instance-certificate
   signing.
4. **Contactmomenten** — `contactmoment` schema, logged per client with
   channel/outcome/agent (`ContactmomentQuickLog`, `ContactmomentDetail`).
5. **Reporting / CSV export** — `ExportDataService` /
   `ExportJobService` / `ForecastExportService`, scheduled exports in
   CSV (RFC 4180), Parquet, or JSONL; `ExportJobs` nav item ("BI export").
6. **Dashboards** — two built-in dashboards, `Dashboard` ("Sales overview":
   revenue, won value, win rate, weighted forecast, pipeline coverage) and
   `OperationalDashboard` ("Operational overview": open leads/requests,
   lead-conversion rate, avg. request resolution, contact-moment volume).

Real, verified integrations kept on the surfaces: Nextcloud Contacts
(CardDAV sync), Deck (pipeline-stage board provider via the OpenRegister
"deck" leaf), and the Shillinq handoff for invoicing a won contract. OpenConnector
is real but a soft/optional dependency (`IAppManager::isInstalled()` checks,
webhook-queue draining) — not a marketing-grade "connects to your accounting
system" claim.

## Reconciliation — edits made

- `appinfo/info.xml`: added an app-dependency comment (OpenRegister hard
  dependency + Deck leaf-provider + OpenConnector optional/soft dependency),
  matching the convention used by other apps (e.g. `procest/appinfo/info.xml`).
  Left `<licence>EUPL-1.2</licence>`, version (`0.5.39`), and the existing
  EN/NL description/summary unchanged — they already matched shipped
  features and did not contain fabricated claims.
- `conduction-website/src/pages/apps/pipelinq.mdx` (EN product page):
  rewrote the tagline, intro, `FeatureList`, `RotatingCards`,
  `WidgetShelf`, `Showcase`, and `PairRow` sections to remove DocuDesk /
  Exact / Twinfield / AFAS / LaunchPad / "four registers" claims and replace
  them with the six canonical features above. Version label changed from
  `v0.7` to `v0.5` to match `info.xml` (`0.5.39`, Beta).
- `conduction-website/i18n/nl/docusaurus-plugin-content-pages/apps/pipelinq.mdx`
  (NL product page): same reconciliation, real Dutch (not a literal
  translation of the fabricated EN copy).
- `pipelinq/docs/intro.md`: rewrote "What you get" bullets to drop DocuDesk/
  Exact/Twinfield/AFAS claims and use the canonical vocabulary.
- `pipelinq/docs/installation.md`: fixed the Nextcloud version prerequisite
  from "29+" to "28+" to match `appinfo/info.xml`'s
  `<nextcloud min-version="28" max-version="34"/>`.

## Claims verified vs removed

| Claim | Verdict | Evidence |
|---|---|---|
| OpenRegister dependency | KEPT (verified) | Pervasive `lib/Settings/pipelinq_register.json`, `ObjectService` usage throughout `lib/` |
| Nextcloud Contacts CardDAV sync | KEPT (verified) | Already accurate in `info.xml` description |
| Lead pipeline / kanban with configurable stages | KEPT (verified) | `Pipeline`/`Pipelines` pages, `lead.stage` field, `pipeline-by-stage` dashboard widget |
| Quotes ↔ contracts one-record model | KEPT (verified, softened) | `ContractController`, `ContractService`, onboarding walkthrough |
| Contactmomenten logging | KEPT (verified) | `contactmoment` schema, `ContactmomentQuickLog.vue`, nav item |
| Scheduled CSV/reporting export | KEPT (verified) | `ExportDataService::formatCsv()`, `ExportJobService`, `ForecastExportService` |
| Sales + Operational dashboards | KEPT (verified) | `src/manifest.json` `Dashboard`/`OperationalDashboard` pages |
| Deck as pipeline board provider | KEPT (verified, reframed as integration not headline feature) | `linkedType: "deck"` entries in `pipelinq_register.json` |
| Shillinq invoicing handoff | KEPT (verified, replaces fabricated Exact/Twinfield/AFAS claim) | `ShillinqApService.php`, walkthrough `send-to-shillinq` step |
| DocuDesk quote templates + instance-certificate signing | **REMOVED** | Zero references to DocuDesk in `lib/`/`src/`; no signing/PDF code in `ContractService` |
| OpenConnector → Exact / Twinfield / AFAS | **REMOVED** | Zero references to Twinfield or AFAS anywhere in the codebase |
| "LaunchPad" BI app reading Pipelinq registers | **REMOVED** | No such app in the fleet; Pipelinq's dashboards are self-contained |
| "Four registers: customers, prospects, deals, quotes" | **REMOVED / RENAMED** | Actual schemas are `client`/`contact`/`lead`/`request`/`contract`/etc. |
| "Per-team filters via Nextcloud groups" on the pipeline | **REMOVED** | No `IGroupManager`/group-filter code scoped to leads found |
| Nextcloud 29+ prerequisite | **CORRECTED to 28+** | `appinfo/info.xml` `<nextcloud min-version="28".../>` |

## Icon status

`img/app.svg` is a white-fill 24×24 SVG — matches the brand app-icon
convention (`app-icon` reference). No change needed. The product-page hero
icons (inline SVGs in the `.mdx` files) are the site's own per-app
illustrative icon, a design-system pattern used across all app pages, not a
factual claim — left as-is.

## Anything still misaligned (needs a decision)

- `pipelinq/docs/Features/*.md` documents a much larger product surface
  (POS/kassa, BRP/BSN lookup, tender posting, loyalty program, budget/cost
  reconciliation, appointment booking, master-data management, government
  compliance) than the "CRM" framing used on the product page and in this
  change. Those feature docs are themselves code-verified (matching
  background jobs, controllers, and schemas actually present) and were left
  untouched — rewriting ~100+ legitimate feature docs was out of scope for
  this alignment pass, which targeted the two marketing surfaces (product
  page, `docs/intro.md`) plus `info.xml`/`installation.md` consistency.
  Whether Pipelinq's *product-page* narrative should stay narrowly
  "CRM" or expand to reflect the POS/compliance/loyalty surface is a product
  decision, not a code-verification one.
- The onboarding walkthrough (`src/manifest.json`) still uses the phrase "a
  signable quotation" for the quote→contract step. It is UX copy inside the
  app (not a public marketing surface) and was left as-is, but if the phrase
  is read as an e-signature claim it should be revisited alongside actual
  e-signature work, if any is ever added.
</content>
