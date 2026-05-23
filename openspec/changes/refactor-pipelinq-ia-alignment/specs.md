---
name: refactor-pipelinq-ia-alignment
status: draft
version: draft
---

# IA Relocation Deltas

This file consolidates the per-spec deltas for the IA alignment change.
Each section below targets one of the existing specs under
`openspec/specs/{spec-slug}/spec.md` and lists the placement
requirements ADDED and REMOVED.

The deltas describe **information-architecture placement** only — they
do not change feature contracts, data models, or API surfaces. Where a
spec already has placement text in its Purpose section, the existing
prose is implicitly superseded by the ADDED Requirement below.

---

## Spec: dashboard

### ADDED Requirements

#### Requirement: Dashboard MUST be placed under the Mijn werk top-menu

The CRM dashboard page MUST be exposed in the left-nav as a sub-page
under the `Mijn werk` top-menu group (alongside `Werkvoorraad`). The
route path `/` MUST be retained for backwards compatibility.

##### Scenario: User navigates to Dashboard via the Mijn werk menu
- GIVEN the user opens the Pipelinq app
- WHEN they expand the `Mijn werk` top-menu in the left-nav
- THEN a `Dashboard` sub-entry MUST be visible
- AND clicking it MUST navigate to the existing dashboard route
- AND the dashboard page itself MUST render unchanged

##### Scenario: Existing dashboard URL still works
- GIVEN any existing bookmark, notification deep-link, or external link to the dashboard route
- WHEN the link is followed
- THEN the dashboard page MUST render
- AND the `Mijn werk → Dashboard` menu entry MUST be marked active

### REMOVED Requirements

#### Requirement: Dashboard appears as a top-level menu entry

**Reason:** Replaced by the Mijn werk top-menu grouping; Dashboard is now a sub-page, not a sibling of every other domain.

---

## Spec: my-work

### ADDED Requirements

#### Requirement: My Work MUST be placed under the Mijn werk top-menu

The My Work (Werkvoorraad) view MUST be exposed in the left-nav as a
sub-page under the `Mijn werk` top-menu group (alongside `Dashboard`).
The route path `/my-work` MUST be retained.

##### Scenario: User navigates to My Work via the Mijn werk menu
- GIVEN the user opens the Pipelinq app
- WHEN they expand the `Mijn werk` top-menu in the left-nav
- THEN a `Werkvoorraad` sub-entry MUST be visible
- AND clicking it MUST navigate to `/my-work`
- AND the My Work page MUST render unchanged

### REMOVED Requirements

#### Requirement: My Work appears as a top-level menu entry

**Reason:** Replaced by the Mijn werk top-menu grouping; My Work is now a sub-page next to Dashboard.

---

## Spec: client-management

### ADDED Requirements

#### Requirement: Clients MUST be placed under the Contacten top-menu

The clients list MUST be exposed in the left-nav as a sub-page named
`Lijst` under the `Contacten` top-menu group (alongside
`Contactpersonen` and `Synchronisatie`). The route path `/clients`
MUST be retained for backwards compatibility.

##### Scenario: User navigates to Clients via the Contacten menu
- GIVEN the user opens the Pipelinq app
- WHEN they expand the `Contacten` top-menu in the left-nav
- THEN a `Lijst` sub-entry MUST be visible
- AND clicking it MUST navigate to `/clients`
- AND the clients list page MUST render unchanged

##### Scenario: Contacts and Clients share the Contacten parent
- GIVEN the `Contacten` top-menu is expanded
- THEN both the clients list (`Lijst`) and the contacts list (`Contactpersonen`) MUST be visible as sibling sub-entries

### REMOVED Requirements

#### Requirement: Clients appears as a top-level menu entry

**Reason:** Replaced by the Contacten top-menu grouping; Clients is now a sub-page (`Lijst`) under Contacten.

---

## Spec: contacts-sync

### ADDED Requirements

#### Requirement: Sync Settings MUST be placed under the Contacten top-menu

The Nextcloud Contacts sync settings page MUST be exposed in the
left-nav as a sub-page named `Synchronisatie` under the `Contacten`
top-menu group. The route path `/sync-settings` MUST be retained.

##### Scenario: User navigates to Sync settings via the Contacten menu
- GIVEN the user opens the Pipelinq app
- WHEN they expand the `Contacten` top-menu
- THEN a `Synchronisatie` sub-entry MUST be visible
- AND clicking it MUST navigate to `/sync-settings`
- AND the sync settings page MUST render the existing `SyncSettingsView`

### REMOVED Requirements

#### Requirement: Sync settings is reachable only by direct URL

**Reason:** The current `/sync-settings` route has no menu entry, leaving the page orphaned. The new placement gives it a discoverable home under Contacten → Synchronisatie.

---

## Spec: pipeline

### ADDED Requirements

#### Requirement: Pipeline kanban MUST be placed under the Pipeline top-menu

The kanban board MUST be exposed in the left-nav as a sub-page named
`Kanban` under the `Pipeline` top-menu group (alongside `Leads` and
`Prospects`). The route path `/pipeline` MUST be retained.

##### Scenario: User navigates to Kanban via the Pipeline menu
- GIVEN the user opens the Pipelinq app
- WHEN they expand the `Pipeline` top-menu
- THEN a `Kanban` sub-entry MUST be visible
- AND clicking it MUST navigate to `/pipeline`
- AND the existing `PipelineBoardView` MUST render unchanged

### REMOVED Requirements

#### Requirement: Pipeline appears as a top-level menu entry

**Reason:** Replaced by the Pipeline top-menu grouping; the kanban view is now a sub-page (`Kanban`) under the Pipeline parent.

---

## Spec: lead-management

### ADDED Requirements

#### Requirement: Leads MUST be placed under the Pipeline top-menu

The leads list MUST be exposed in the left-nav as a sub-page named
`Leads` under the `Pipeline` top-menu group (alongside `Kanban` and
`Prospects`). The route path `/leads` MUST be retained.

##### Scenario: User navigates to Leads via the Pipeline menu
- GIVEN the user opens the Pipelinq app
- WHEN they expand the `Pipeline` top-menu
- THEN a `Leads` sub-entry MUST be visible
- AND clicking it MUST navigate to `/leads`
- AND the leads list page MUST render unchanged

### REMOVED Requirements

#### Requirement: Leads appears as a top-level menu entry

**Reason:** Replaced by the Pipeline top-menu grouping; Leads is now a sub-page under the Pipeline parent.

---

## Spec: prospect-discovery

### ADDED Requirements

#### Requirement: Prospects MUST be exposed as a sub-page under the Pipeline top-menu

In addition to the existing dashboard widget and admin ICP
configuration, the prospect discovery results MUST be reachable as a
full-page view at `/prospects`, exposed in the left-nav as a sub-page
named `Prospects` under the `Pipeline` top-menu group (alongside
`Kanban` and `Leads`).

##### Scenario: User navigates to Prospects via the Pipeline menu
- GIVEN the user opens the Pipelinq app
- WHEN they expand the `Pipeline` top-menu
- THEN a `Prospects` sub-entry MUST be visible
- AND clicking it MUST navigate to `/prospects`
- AND the page MUST render the discovered prospects with fit scores, source attribution, and a "convert to lead" action per row

##### Scenario: Dashboard widget remains in place
- GIVEN the new Prospects page exists
- THEN the existing dashboard widget for prospect discovery MUST continue to render on the dashboard
- AND the admin ICP configuration MUST continue to live under the admin settings panel (unchanged)

### REMOVED Requirements

(None — this is purely additive for prospect-discovery; no existing
placement is being removed.)

---

## Spec: request-management

### ADDED Requirements

#### Requirement: Requests MUST be placed under the Klachten & Verzoeken top-menu

The requests list MUST be exposed in the left-nav as a sub-page named
`Verzoeken` under the `Klachten & Verzoeken` top-menu group (alongside
`Klachten`). The route path `/requests` MUST be retained.

##### Scenario: User navigates to Verzoeken via the Klachten & Verzoeken menu
- GIVEN the user opens the Pipelinq app
- WHEN they expand the `Klachten & Verzoeken` top-menu
- THEN a `Verzoeken` sub-entry MUST be visible
- AND clicking it MUST navigate to `/requests`
- AND the requests list page MUST render unchanged

##### Scenario: Klachten lives next to Verzoeken
- GIVEN the `Klachten & Verzoeken` top-menu is expanded
- THEN both `Verzoeken` (requests) and `Klachten` (complaints) MUST be visible as sibling sub-entries

### REMOVED Requirements

#### Requirement: Requests appears as a top-level menu entry

**Reason:** Replaced by the Klachten & Verzoeken top-menu grouping; Requests is now a sub-page (`Verzoeken`) under that parent.

---

## Spec: product-catalog

### ADDED Requirements

#### Requirement: Products MUST be placed under the Catalogus top-menu

The product catalog list MUST be exposed in the left-nav as a sub-page
named `Producten & diensten` under the `Catalogus` top-menu group. The
route path `/products` MUST be retained.

##### Scenario: User navigates to Producten & diensten via the Catalogus menu
- GIVEN the user opens the Pipelinq app
- WHEN they expand the `Catalogus` top-menu
- THEN a `Producten & diensten` sub-entry MUST be visible
- AND clicking it MUST navigate to `/products`
- AND the products list page MUST render unchanged

### REMOVED Requirements

#### Requirement: Products appears as a top-level menu entry

**Reason:** Replaced by the Catalogus top-menu grouping; Products is now a sub-page (`Producten & diensten`) under Catalogus.

---

## Spec: product-service-catalog

### ADDED Requirements

#### Requirement: Product & Service Catalog MUST share the single Catalogus sub-page with product-catalog

The PDC functionality MUST be surfaced in the same `Catalogus →
Producten & diensten` left-nav sub-page as `product-catalog`. There
MUST NOT be a separate top-menu entry, separate sub-page, or separate
route for the PDC view.

##### Scenario: Only one Catalogus sub-page exists
- GIVEN the `Catalogus` top-menu is expanded
- THEN exactly one sub-entry — `Producten & diensten` — MUST be visible for the merged product catalog
- AND there MUST NOT be a separate "PDC" or "Service catalog" menu entry

##### Scenario: Data-model reconciliation tracked separately
- GIVEN this change covers IA placement only
- THEN any divergence between the `pipelinq.product` schema and the IPDC/UPL `pdc.product` schema MUST be reconciled in a separate non-IA spec change
- AND this IA-alignment change MUST NOT modify either schema

### REMOVED Requirements

#### Requirement: Product & Service Catalog has an independent menu placement

**Reason:** The IA explicitly merges PDC presentation with the canonical product catalog under one Catalogus sub-page.
