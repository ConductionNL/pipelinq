# Proposal: Pipelinq IA Alignment

## Why

The current Pipelinq left-nav menu is a flat list of 15 top-level entries
(Dashboard, Clients, Contacts, Leads, Requests, Tasks, Contactmomenten,
Complaints, Products, Pipeline, Surveys, Queues, Kennisbank, MyWork,
Rapportage), plus a Settings section. This forces users into a wide
"all-siblings" navigation that does not reflect the conceptual domain
groupings the product is actually organised around (klant, pipeline,
klacht/verzoek, catalogus, mijn werk, beheer).

A fresh IA proposal collapses these into six top-menu domains with
sub-pages, and pulls "loose" features (e.g. notifications, activity
timelines, lead-product link) into either widgets or detail tabs rather
than dedicated top-level routes. This proposal aligns the implementation
with that target IA.

## What Changes

This change refactors the left-nav topology and the manifest routes:

- **Mijn werk** becomes a top-menu wrapper for `Dashboard` and `MyWork`
  (today both are top-level siblings).
- **Contacten** becomes a top-menu wrapper for `Clients` (renamed
  "Lijst") and `Sync settings` (renamed "Synchronisatie") — the latter
  currently exists as an orphaned `/sync-settings` route with no menu
  entry.
- **Pipeline** becomes a top-menu wrapper for `Kanban` (today's
  `Pipeline`), `Leads`, and a new `Prospects` page (prospect-discovery
  currently has only a dashboard widget + admin config).
- **Klachten & Verzoeken** becomes a top-menu wrapper for `Verzoeken`
  (today's `Requests`) and `Klachten` (today's `Complaints`).
- **Catalogus** becomes a top-menu wrapper for `Producten & diensten`
  (today's `Products`). `product-catalog` and `product-service-catalog`
  are explicitly merged into a single canonical list per the IA.
- **Beheer** stays as it is today (Nextcloud admin settings panel +
  in-app settings section), unchanged structurally but with an
  Observability pane added later for prometheus-metrics status.

## Drifted specs (require relocation)

| Spec | Current placement | Target placement | Reason |
|------|-------------------|------------------|--------|
| `dashboard` | Top-menu `Dashboard` at `/` | Sub-page under `Mijn werk` | IA: SUB_PAGE → Mijn werk → Dashboard |
| `my-work` | Top-menu `My Work` at `/my-work` | Sub-page under `Mijn werk` | IA: SUB_PAGE → Mijn werk → Werkvoorraad |
| `client-management` | Top-menu `Clients` at `/clients` | Sub-page under `Contacten` (renamed "Lijst") | IA: SUB_PAGE → Contacten → Lijst |
| `contacts-sync` | Orphan route `/sync-settings` (not in menu) | Sub-page under `Contacten` (renamed "Synchronisatie") | IA: SUB_PAGE → Contacten → Synchronisatie |
| `pipeline` | Top-menu `Pipeline` at `/pipeline` | Sub-page under `Pipeline` (renamed "Kanban") | IA: SUB_PAGE → Pipeline → Kanban |
| `lead-management` | Top-menu `Leads` at `/leads` | Sub-page under `Pipeline` | IA: SUB_PAGE → Pipeline → Leads |
| `prospect-discovery` | Dashboard widget + admin settings only | New sub-page under `Pipeline` | IA: SUB_PAGE → Pipeline → Prospects |
| `request-management` | Top-menu `Requests` at `/requests` | Sub-page under `Klachten & Verzoeken` (renamed "Verzoeken") | IA: SUB_PAGE → Klachten & Verzoeken → Verzoeken |
| `product-catalog` | Top-menu `Products` at `/products` | Sub-page under `Catalogus` (renamed "Producten & diensten") | IA: SUB_PAGE → Catalogus |
| `product-service-catalog` | Conceptually overlapping `Products` page | Merged into the single `Catalogus → Producten & diensten` sub-page | IA: explicitly merged with `product-catalog` |

## Verified aligned (no change required)

| Spec | Why it's aligned |
|------|------------------|
| `activity-timeline` | IA: DETAIL_TAB. Currently embedded as a card on entity detail views (and recommended for the generic `CnDetailPage` tabs config); no separate top-level route exists. |
| `entity-notes` | IA: DETAIL_TAB. `EntityNotes.vue` component exists for embedding on detail views; no separate top-level route exists. |
| `lead-product-link` | IA: DETAIL_TAB on Deal/Lead detail. `LeadProducts.vue` is embedded as a card on the Lead detail view; no separate route. |
| `notifications-activity` | IA: WIDGET (header bell + Mijn werk). Implementation uses Nextcloud `INotificationManager` + activity stream; no dedicated menu entry. |
| `admin-settings` | IA: SETTING → Beheer → Algemeen. Already registered as a Nextcloud admin settings panel (`OCA\Pipelinq\Settings\AdminSettings`); not in main nav. |
| `openregister-integration` | IA: INFRA, no UI. No UI surface to misplace. |
| `register-i18n` | IA: INFRA, no UI. Locale formatting only. |
| `prometheus-metrics` | IA: INFRA+SETTING. Backend endpoint exists (`/api/metrics`); no UI currently exists, so no current placement to drift from. An Observability pane under Beheer is additive and out of scope for this IA-alignment change — track separately. |

## Impact

- **Manifest:** `src/manifest.json` — the `menu` array is reduced from 15
  flat entries to ~6 top-level wrappers; menu items gain a `children`
  array (or equivalent parent/order grouping per the manifest schema)
  for grouping.
- **Routes:** Existing route paths under `/clients`, `/leads`,
  `/pipeline`, `/requests`, `/complaints`, `/products`, `/my-work`,
  `/sync-settings` are retained as-is for backwards compatibility (no
  bookmark breakage); only the **menu** structure changes. New entries
  under `Pipeline → Prospects` get a new `/prospects` route.
- **Vue:** No file renames or component moves are required for the
  menu reorg itself — components stay under their current
  `src/views/{domain}/` directories. The Prospects sub-page needs a
  new `ProspectsView.vue` (or declarative `type: "index"` if the schema
  supports it).
- **No data model changes.** No backend / schema impact.
- **Out of scope:** The Tasks, Contactmomenten, Surveys, Queues,
  Kennisbank, Rapportage, and Settings-section entries (Pipelines,
  Forms, Automations, Features & roadmap) are NOT in the audited
  spec list and remain untouched by this change.
