---
status: draft
---

# Product & Service Catalog (PDC)

**Owned by**: Pipelinq (CRM product catalog for citizen service delivery)

## Purpose
Implement a government product and service catalog (PDC - Producten- en Dienstencatalogus) as a core Pipelinq capability for CRM-driven citizen service delivery, conforming to the Uniforme Productnamenlijst (UPL) and Single Digital Gateway (SDG) standards. The PDC integrates with Pipelinq's existing product entities and CRM workflows, enabling KCC (Klant Contact Centrum) agents to look up products during citizen interactions, link products to contact moments, and initiate service requests. Products MUST support structured content blocks, publication lifecycle, target audience classification, pricing, multilingual content for cross-border EU access, zaaktype linking, versioning, bundling, and analytics. The catalog MUST expose a public read-only API for integration with municipal websites, citizen portals, and the SDG Your Europe portal.

**Source**: Gap identified in cross-platform analysis; mandated standard for Dutch municipalities. IPDC (Interbestuurlijke Producten- en Dienstencatalogus) is the national reference catalog; municipalities maintain local PDC instances that reference IPDC entries and extend them with local pricing, procedures, and channel information.

**Tender demand**: 65% of analyzed government tenders require a product and service catalog for citizen-facing portals, KCC werkplek integration, and omnichannel service delivery.

## Requirements

### Requirement: Products MUST be stored as register objects with IPDC/UPL-compliant schema

Products MUST be modeled as OpenRegister objects in a dedicated `pdc` register with a `product` schema per ADR-001 (OpenRegister as Universal Data Layer). The schema MUST conform to the IPDC data model and include UPL (Uniforme Productnamenlijst) references. MUST NOT use custom database tables. The `pdc_register.json` template MUST be deployable via `openregister:load-register` CLI command or repair step.

#### Scenario: Create a product linked to UPL and IPDC
- **GIVEN** the `pdc` register is provisioned with the `product` schema
- **AND** the UPL reference list is available as a lookup schema in the `pdc` register
- **WHEN** the admin creates a product with:
  - `uplNaam`: `Paspoort` (from official UPL list)
  - `uplUri`: `http://standaarden.overheid.nl/owms/terms/Paspoort`
  - `ipdcUri`: `https://ipdc.nl/product/12345` (IPDC reference)
  - `publicNaam`: `Paspoort aanvragen`
  - `samenvatting`: `Vraag een nieuw paspoort aan bij uw gemeente.`
  - `bevoegdGezag`: `gemeente` (enum: `gemeente`, `provincie`, `waterschap`, `rijksoverheid`)
  - `thema`: `Identiteit` (from IPDC thema taxonomy)
- **THEN** the product MUST be stored as a register object in the `pdc` register under the `product` schema
- **AND** the UPL URI MUST be validated against the imported UPL reference list
- **AND** the `_name` metadata field MUST be set to the `publicNaam` value
- **AND** the `_summary` metadata field MUST be set to the `samenvatting` value

#### Scenario: Reject product with invalid UPL reference
- **GIVEN** a UPL URI `http://standaarden.overheid.nl/owms/terms/NonExistent` that does not exist in the reference list
- **WHEN** the admin tries to create a product with this URI
- **THEN** the system MUST return a 422 validation error with a warning that the UPL reference is not recognized per ADR-002 error response conventions
- **AND** the admin MAY override the warning with a `_force=true` parameter (new products may not yet be in UPL)

#### Scenario: Product schema includes all IPDC-required fields
- **GIVEN** the `product` schema definition in `pdc_register.json`
- **WHEN** the schema is loaded into OpenRegister
- **THEN** the schema MUST include the following properties at minimum:
  - `uplNaam` (string, required) -- official UPL product name
  - `uplUri` (string, format: uri) -- UPL URI reference
  - `ipdcUri` (string, format: uri) -- IPDC product URI
  - `publicNaam` (string, required) -- display name for citizens
  - `samenvatting` (string, required) -- short description (max 200 chars)
  - `bevoegdGezag` (string, enum) -- responsible authority level
  - `doelgroepen` (array of string) -- SDG target audiences
  - `thema` (string) -- IPDC theme classification
  - `trefwoorden` (array of string) -- search keywords
  - `contentBlokken` (array of object) -- structured content sections
  - `tarieven` (array of object) -- pricing/tariff information
  - `kanalen` (array of object) -- availability channels
  - `vertalingen` (object) -- multilingual content keyed by ISO 639-1 code
  - `publicatieStatus` (string, enum) -- publication lifecycle state
  - `publicatieDatum` (string, format: date) -- scheduled publication date
  - `depublicatieDatum` (string, format: date) -- scheduled depublication date
  - `zaaktypeUris` (array of string) -- linked zaaktype URIs
  - `productVersie` (string) -- product content version identifier
  - `bundelProducten` (array of string) -- UUIDs of bundled sub-products
  - `slaDefinitie` (object) -- service level agreement parameters
  - `wettelijkeGrondslag` (string) -- legal basis (law reference)
  - `aanvraagLinks` (array of object) -- application form URLs per channel

### Requirement: Products MUST support SDG target audience classification and cross-border compliance

Products MUST be classifiable by SDG doelgroep (target audience) for EU cross-border service discovery under Regulation (EU) 2018/1724. The SDG requires that certain "life events" (e.g., moving, starting a business) have products accessible cross-border with multilingual descriptions.

#### Scenario: Classify product for citizens and businesses
- **GIVEN** a product `Omgevingsvergunning`
- **WHEN** the admin sets `doelgroepen`: `["burger", "bedrijf"]`
- **THEN** the product MUST be discoverable for both citizens and businesses in the SDG catalog export
- **AND** the valid doelgroep values MUST be constrained to: `burger`, `bedrijf`, `burger_bedrijf`

#### Scenario: SDG life event mapping
- **GIVEN** a product `Omgevingsvergunning` classified for `bedrijf`
- **WHEN** the admin maps the product to SDG life event `Starting a business`
- **THEN** the product MUST include `sdgLevenGebeurtenis`: `starting_a_business` from the SDG life events taxonomy
- **AND** the product MUST appear in the SDG feed under that life event category

#### Scenario: SDG feed generation
- **GIVEN** 25 published products with SDG doelgroep classifications
- **WHEN** the scheduled `SdgFeedExportJob` (QueuedJob) runs
- **THEN** the job MUST generate a JSON feed conforming to the SDG information exchange format
- **AND** each product entry MUST include: `productName` (multilingual), `productDescription` (multilingual), `targetAudience`, `lifeEvent`, `procedureUrl`, and `competentAuthority`
- **AND** the feed MUST be cached and served at `/api/pdc/sdg-feed` without authentication

### Requirement: Products MUST support structured content blocks

Product information MUST be organized in structured content blocks for consistent citizen-facing presentation. Content blocks follow the IPDC `informatieObjecten` pattern and MUST support both a fixed set of standard block types and custom blocks.

#### Scenario: Configure product with standard content blocks
- **GIVEN** a product `Paspoort aanvragen`
- **WHEN** the admin adds content blocks:
  - `{ "type": "beschrijving", "titel": "Wat is het", "inhoud": "Een paspoort is een reisdocument..." }`
  - `{ "type": "procedure", "titel": "Hoe werkt het", "inhoud": "1. Maak een afspraak...", "stappen": ["Maak een afspraak", "Neem mee...", "Betaal de leges"] }`
  - `{ "type": "kosten", "titel": "Wat kost het", "inhoud": "De kosten zijn afhankelijk van uw leeftijd." }`
  - `{ "type": "voorwaarden", "titel": "Wat heb ik nodig", "inhoud": "U heeft nodig: ...", "documenten": ["Huidige paspoort", "Pasfoto"] }`
  - `{ "type": "contact", "titel": "Contact", "inhoud": "Bel 14 030 of maak een afspraak." }`
  - `{ "type": "aanvraag", "titel": "Direct aanvragen", "url": "https://gemeente.nl/afspraak-maken", "kanaal": "online" }`
- **THEN** each content block MUST be stored in the `contentBlokken` array with `type`, `titel`, `inhoud`, and optional type-specific fields
- **AND** the standard block types MUST include at minimum: `beschrijving`, `procedure`, `kosten`, `voorwaarden`, `termijn`, `contact`, `aanvraag`, `bijzonderheden`, `bezwaar`

#### Scenario: Content block ordering
- **GIVEN** a product with 6 content blocks
- **WHEN** the admin reorders blocks by setting `volgorde` (integer) on each block
- **THEN** the public API MUST return content blocks sorted by `volgorde` ascending
- **AND** blocks without explicit `volgorde` MUST appear after ordered blocks

### Requirement: Products MUST support a publication lifecycle with scheduled publishing

Products MUST have a publication state controlling visibility in the public catalog. Publication and depublication MUST support both immediate action and scheduled dates. The lifecycle MUST be tracked in the audit trail per ADR-001.

#### Scenario: Draft to published transition
- **GIVEN** a product `Paspoort aanvragen` in status `concept`
- **WHEN** the admin sets `publicatieStatus` to `gepubliceerd` with `publicatieDatum` of `2026-04-01`
- **THEN** the product MUST NOT appear in the public API before `2026-04-01`
- **AND** starting `2026-04-01T00:00:00` the product MUST be visible in the public API
- **AND** the status transition MUST be recorded in the audit trail with action `publicatie`

#### Scenario: Depublish a product with redirect
- **GIVEN** a published product `Paspoort aanvragen` accessible at `/api/pdc/products/{uuid}`
- **WHEN** the admin sets `publicatieStatus` to `gearchiveerd`
- **THEN** the product MUST be removed from the public listing API
- **AND** direct URL access MUST return HTTP 410 Gone with header `Location: /api/pdc/products` for graceful degradation
- **AND** the product MUST remain accessible to authenticated admin users with status indicator `gearchiveerd`

#### Scenario: Scheduled depublication
- **GIVEN** a published product with `depublicatieDatum` of `2026-12-31`
- **WHEN** the `PublicationLifecycleJob` (TimedJob, runs hourly) executes after `2026-12-31`
- **THEN** the product `publicatieStatus` MUST be automatically set to `gearchiveerd`
- **AND** an audit trail entry MUST record the automated depublication

#### Scenario: Valid publication statuses
- **GIVEN** the `publicatieStatus` enum definition
- **THEN** the following values MUST be supported: `concept`, `ter_review`, `gepubliceerd`, `gearchiveerd`
- **AND** valid transitions MUST be: `concept` -> `ter_review` -> `gepubliceerd` -> `gearchiveerd`, and `ter_review` -> `concept` (rejection), and `gearchiveerd` -> `concept` (reactivation)

### Requirement: Products MUST support pricing with structured tariff tables (leges)

Product pricing MUST support static prices, age-dependent tariffs, conditional pricing, and references to the gemeentelijke legesverordening. Prices MUST include VAT indication and currency per ISO 4217.

#### Scenario: Simple static price
- **GIVEN** a product `Paspoort`
- **WHEN** the admin adds a tariff:
  - `{ "label": "Paspoort", "bedrag": 75.80, "valuta": "EUR", "btwIndicatie": "vrijgesteld", "geldigVanaf": "2026-01-01" }`
- **THEN** the product MUST display the tariff in the catalog with the amount formatted as EUR 75,80

#### Scenario: Age-dependent pricing table
- **GIVEN** a product `Paspoort` with different prices by age category
- **WHEN** the admin configures multiple tariffs:
  - `{ "label": "Paspoort 18 jaar en ouder", "bedrag": 75.80, "valuta": "EUR", "conditie": "leeftijd >= 18", "geldigVanaf": "2026-01-01" }`
  - `{ "label": "Paspoort jonger dan 18 jaar", "bedrag": 56.55, "valuta": "EUR", "conditie": "leeftijd < 18", "geldigVanaf": "2026-01-01" }`
- **THEN** the `tarieven` array MUST contain both entries
- **AND** the public API MUST return the complete tariff table for client-side display

#### Scenario: Tariff with validity period
- **GIVEN** a tariff `Paspoort 18+` with `geldigVanaf`: `2026-01-01` and `geldigTot`: `2026-12-31`
- **AND** a new tariff `Paspoort 18+ (2027)` with `geldigVanaf`: `2027-01-01` and `bedrag`: `79.50`
- **WHEN** a citizen views the product on `2026-06-15`
- **THEN** the public API MUST return only tariffs where `geldigVanaf <= 2026-06-15` and (`geldigTot` is null or `geldigTot >= 2026-06-15`)

#### Scenario: Free product (no leges)
- **GIVEN** a product `Verhuizing doorgeven`
- **WHEN** the admin sets `tarieven` to `[{ "label": "Gratis", "bedrag": 0, "valuta": "EUR" }]`
- **THEN** the product MUST display `Gratis` in the catalog rather than `EUR 0,00`

### Requirement: Products MUST support multilingual content for SDG compliance

Product content MUST support at minimum Dutch (nl) and English (en) for SDG cross-border compliance. Additional languages MAY be configured. Translations MUST be stored per content block, not as separate product objects.

#### Scenario: Product with Dutch and English content
- **GIVEN** a product `Paspoort aanvragen`
- **WHEN** the admin provides translations:
  ```json
  {
    "nl": {
      "publicNaam": "Paspoort aanvragen",
      "samenvatting": "Vraag een nieuw paspoort aan bij uw gemeente.",
      "contentBlokken": [{ "type": "beschrijving", "titel": "Wat is het", "inhoud": "Een paspoort is..." }]
    },
    "en": {
      "publicNaam": "Apply for a passport",
      "samenvatting": "Apply for a new passport at your municipality.",
      "contentBlokken": [{ "type": "beschrijving", "titel": "What is it", "inhoud": "A passport is..." }]
    }
  }
  ```
- **THEN** both translations MUST be stored in the `vertalingen` property keyed by ISO 639-1 language code
- **AND** the base (Dutch) content MUST also be stored in the top-level product properties

#### Scenario: Content negotiation via Accept-Language header
- **GIVEN** a published product with Dutch and English translations
- **WHEN** an unauthenticated client requests `GET /api/pdc/products/{uuid}` with `Accept-Language: en`
- **THEN** the API MUST return the English translation of `publicNaam`, `samenvatting`, and `contentBlokken`
- **AND** if the requested language is not available, the API MUST fall back to Dutch (`nl`)
- **AND** the response MUST include `Content-Language: en` header per RFC 7231

#### Scenario: Translation completeness indicator
- **GIVEN** a product with full Dutch content and partial English content (missing 2 content blocks)
- **WHEN** the admin views the product in the management UI
- **THEN** the system MUST display a translation completeness indicator per language (e.g., `en: 60% complete`)

### Requirement: The catalog MUST provide a public read-only API

Products MUST be accessible via a public API without authentication for integration with municipal websites, citizen portals, and third-party applications. The API MUST follow ADR-002 REST API conventions for URL patterns, pagination, and error responses.

#### Scenario: Public product listing with pagination
- **GIVEN** 120 published products in the `pdc` register
- **WHEN** an unauthenticated client requests `GET /api/pdc/products?_page=1&_limit=30`
- **THEN** the response MUST return 30 products sorted by `publicNaam` ascending by default
- **AND** each product MUST include: `uuid`, `publicNaam`, `samenvatting`, `doelgroepen`, `thema`, `tarieven` (summary), and `uplUri`
- **AND** the response MUST include pagination headers: `X-Total-Count: 120`, `X-Page: 1`, `X-Limit: 30` per ADR-002

#### Scenario: Public product detail
- **GIVEN** a published product `Paspoort aanvragen` with UUID `abc-123`
- **WHEN** an unauthenticated client requests `GET /api/pdc/products/abc-123`
- **THEN** the response MUST include the full product with all content blocks, tariffs, channels, and application links
- **AND** concept and archived products MUST return HTTP 404 for unauthenticated requests

#### Scenario: Filter products by theme and audience
- **GIVEN** 120 published products across 8 themes
- **WHEN** a client requests `GET /api/pdc/products?thema=Identiteit&doelgroepen[]=burger`
- **THEN** only products matching both filters MUST be returned
- **AND** faceted counts MUST be available via `GET /api/pdc/products?_facets[]=thema&_facets[]=doelgroepen` returning category counts

#### Scenario: Full-text search across product content
- **GIVEN** 120 published products
- **WHEN** a client requests `GET /api/pdc/products?_search=rijbewijs`
- **THEN** the search MUST match against `publicNaam`, `samenvatting`, `trefwoorden`, and content block `inhoud` fields
- **AND** results MUST be ranked by relevance using the existing `SearchBackendInterface`

### Requirement: Products MUST support multi-channel availability

Products MUST declare through which channels they can be accessed or requested by citizens. Channels include online (webformulier), physical (balie/loket), telephone, email, and post. Each channel MAY have its own application link and availability hours.

#### Scenario: Product available via multiple channels
- **GIVEN** a product `Paspoort aanvragen`
- **WHEN** the admin configures channels:
  - `{ "kanaal": "balie", "locatie": "Stadskantoor", "beschikbaar": true, "afspraakNodig": true, "afspraakUrl": "https://gemeente.nl/afspraak" }`
  - `{ "kanaal": "online", "url": "https://gemeente.nl/paspoort", "beschikbaar": true }`
  - `{ "kanaal": "telefoon", "telefoonnummer": "14 030", "beschikbaar": true, "openingstijden": "ma-vr 08:00-17:00" }`
- **THEN** the `kanalen` array MUST contain all three entries
- **AND** the public API MUST return channel information with the product detail

#### Scenario: Channel temporarily unavailable
- **GIVEN** a product with an online channel
- **WHEN** the admin sets `beschikbaar` to `false` with `melding`: `Tijdelijk niet beschikbaar wegens onderhoud`
- **THEN** the public API MUST include the channel with `beschikbaar: false` and the maintenance message
- **AND** the product MUST remain listed in the catalog (only the channel is marked unavailable, not the product)

### Requirement: Products MUST support SLA definitions

Products MUST include service level parameters that inform citizens about expected processing times and quality commitments. SLA definitions follow the IPDC `doorlooptijd` pattern.

#### Scenario: Product with processing time SLA
- **GIVEN** a product `Omgevingsvergunning`
- **WHEN** the admin sets `slaDefinitie`:
  - `{ "doorlooptijdDagen": 56, "wettelijkeTermijnDagen": 56, "type": "regulier" }`
  - `{ "doorlooptijdDagen": 182, "wettelijkeTermijnDagen": 182, "type": "uitgebreid" }`
- **THEN** the public API MUST include the SLA information with the product
- **AND** the SLA MUST distinguish between `regulier` and `uitgebreid` procedure types where applicable

#### Scenario: SLA with service commitment
- **GIVEN** a product `Uittreksel Basisregistratie Personen`
- **WHEN** the admin sets `slaDefinitie`:
  - `{ "doorlooptijdDagen": 5, "directAanBalie": true, "serviceBelofte": "Aan de balie direct meenemen" }`
- **THEN** the public API MUST include the service commitment text for citizen-facing display

### Requirement: Products MUST be linkable to zaaktypen

Products MUST support references to one or more zaaktype URIs from the ZGW Catalogi API, enabling process automation when a citizen initiates a product request. The link connects the PDC (what the citizen sees) to the ZTC (how the municipality processes it).

#### Scenario: Link product to zaaktype
- **GIVEN** a product `Omgevingsvergunning` in the PDC
- **AND** a zaaktype with URI `https://catalogi.gemeente.nl/api/v1/zaaktypen/abc-456` in the ZGW Catalogi
- **WHEN** the admin adds the zaaktype URI to `zaaktypeUris`: `["https://catalogi.gemeente.nl/api/v1/zaaktypen/abc-456"]`
- **THEN** the product MUST store the zaaktype reference
- **AND** when a citizen initiates this product via the online channel, the system MUST be able to trigger zaak creation via the linked zaaktype (integration with Procest/zaakafhandelapp)

#### Scenario: Product with multiple zaaktypen
- **GIVEN** a product `Evenementenvergunning` that requires both an evenementenvergunning zaak and a APV-ontheffing zaak
- **WHEN** the admin links two zaaktype URIs
- **THEN** the product MUST support multiple `zaaktypeUris` entries
- **AND** the admin MUST be able to specify which zaaktype is primary via a `primair` flag

### Requirement: Products MUST support versioning for content changes

Product content changes MUST be tracked with version numbers, enabling rollback and audit of what was published to citizens at any point in time. Versioning leverages OpenRegister's existing content versioning infrastructure per the `content-versioning` spec.

#### Scenario: Product content version on edit
- **GIVEN** a published product `Paspoort aanvragen` at `productVersie` `2.1`
- **WHEN** the admin updates the pricing from EUR 75.80 to EUR 79.50
- **THEN** the `productVersie` MUST increment to `2.2`
- **AND** the previous version MUST be accessible via the audit trail
- **AND** the audit trail entry MUST record the specific field changes: `{"tarieven[0].bedrag": {"old": 75.80, "new": 79.50}}`

#### Scenario: Rollback to previous product version
- **GIVEN** a product `Paspoort aanvragen` at `productVersie` `2.2` with an error in the pricing
- **WHEN** the admin reverts to version `2.1` via `POST /api/objects/{register}/{schema}/{id}/revert?version=2.1`
- **THEN** the product content MUST be restored to the version `2.1` state
- **AND** a new version `2.3` MUST be created with the reverted content (not destructive overwrite)

### Requirement: Products MUST support bundling (samengestelde producten)

Some government services are delivered as a bundle of related products (e.g., "Verhuizen" bundles address change, waste collection registration, and parking permit update). The catalog MUST support product bundles that reference sub-products.

#### Scenario: Create a product bundle
- **GIVEN** three existing products: `Adreswijziging` (uuid-1), `Afvalpas aanvragen` (uuid-2), `Parkeervergunning wijzigen` (uuid-3)
- **WHEN** the admin creates a bundle product `Verhuizen` with:
  - `publicNaam`: `Verhuizen`
  - `bundelProducten`: `["uuid-1", "uuid-2", "uuid-3"]`
  - `bundelType`: `informationeel` (enum: `informationeel`, `procedureel`)
- **THEN** the bundle product MUST be stored as a regular product with references to the sub-products
- **AND** the public API MUST return the bundle with expanded sub-product summaries (name + samenvatting)

#### Scenario: Bundle does not duplicate sub-product content
- **GIVEN** a bundle product `Verhuizen` referencing 3 sub-products
- **WHEN** the sub-product `Adreswijziging` updates its pricing
- **THEN** the bundle MUST reflect the updated pricing when the sub-product is expanded in the API response
- **AND** the bundle itself MUST NOT store copies of sub-product content (references only)

### Requirement: Products MUST support analytics for popular product tracking

The catalog MUST track product view counts and search frequency to surface popular products and identify content gaps. Analytics data MUST be aggregated (no personal data stored) and accessible to catalog administrators.

#### Scenario: Track product page views
- **GIVEN** a published product `Paspoort aanvragen`
- **WHEN** 150 unauthenticated users view the product via `GET /api/pdc/products/{uuid}` in one month
- **THEN** the system MUST increment a view counter for the product (stored as a separate analytics object or metadata, NOT on the product object itself to avoid version churn)
- **AND** the analytics MUST be available to admins via `GET /api/pdc/analytics/products?_sort=views&_order=desc&periode=2026-03`

#### Scenario: Popular products widget
- **GIVEN** product view analytics for the current month
- **WHEN** an admin views the PDC dashboard
- **THEN** the top 10 most-viewed products MUST be displayed
- **AND** products with zero views MUST be flagged for review (potential content or discoverability issues)

#### Scenario: Search analytics for content gaps
- **GIVEN** search queries against the PDC public API
- **WHEN** a search term `hondenbelasting` returns zero results 50 times in one month
- **THEN** the system MUST log the zero-result search term with frequency
- **AND** admins MUST be able to view zero-result search terms via `GET /api/pdc/analytics/searches?results=0`

### Requirement: Products MUST support categorization via IPDC taxonomy

Products MUST be classifiable using the IPDC thema taxonomy and the gemeentelijke taakvelden taxonomy. Categorization enables faceted navigation in citizen portals and KCC werkplek.

#### Scenario: Assign IPDC theme to product
- **GIVEN** a product `Gehandicaptenparkeerkaart`
- **WHEN** the admin sets `thema`: `Zorg en gezondheid`
- **AND** `taakveld`: `6.6 Maatwerkvoorzieningen (WMO)` (BBV taakveld)
- **THEN** the product MUST be filterable by both `thema` and `taakveld` in the public API
- **AND** the IPDC thema values MUST be constrained to the official IPDC thema list

#### Scenario: Product with multiple keywords
- **GIVEN** a product `Gehandicaptenparkeerkaart`
- **WHEN** the admin sets `trefwoorden`: `["parkeerkaart", "gehandicapt", "invalidenparkeerplaats", "GPK"]`
- **THEN** all keywords MUST be indexed for full-text search
- **AND** searching for any keyword MUST return the product in results

### Requirement: Citizen-facing product pages MUST support direct application links

Products MUST include one or more links to application forms or initiation points, keyed by channel. Links MUST support both internal (Nextcloud-hosted forms) and external URLs.

#### Scenario: Product with online application link
- **GIVEN** a product `Paspoort aanvragen` with online channel
- **WHEN** the admin configures:
  - `aanvraagLinks`: `[{ "kanaal": "online", "url": "https://gemeente.nl/formulieren/paspoort", "label": "Direct aanvragen", "extern": true }]`
- **THEN** the public API MUST include the application link with the product detail
- **AND** the link MUST open in a new window indicator (`extern: true`)

#### Scenario: Product with appointment booking link
- **GIVEN** a product `Paspoort aanvragen` that requires a physical visit
- **WHEN** the admin configures:
  - `aanvraagLinks`: `[{ "kanaal": "balie", "url": "https://gemeente.nl/afspraak-maken?product=paspoort", "label": "Afspraak maken", "extern": true }]`
- **THEN** the link MUST be associated with the `balie` channel
- **AND** the public API MUST return both online and balie links when the product supports both channels

### Requirement: UPL reference list MUST be importable and updatable

The UPL (Uniforme Productnamenlijst) maintained by VNG/Logius MUST be importable into OpenRegister as a lookup schema. The list MUST be periodically refreshable to track additions and deprecations.

#### Scenario: Import UPL reference list
- **GIVEN** the official UPL CSV/JSON from `https://standaarden.overheid.nl/upl`
- **WHEN** the admin triggers UPL import via the settings UI or `openregister:load-register` command
- **THEN** each UPL entry MUST be stored as an object in the `pdc` register under a `upl_referentie` schema
- **AND** each entry MUST include: `naam`, `uri`, `synoniem`, `status` (actief/vervallen), and `categorie`

#### Scenario: Periodic UPL sync
- **GIVEN** the UPL reference source URL is configured in `IAppConfig` under key `pdc_upl_source_url`
- **WHEN** the `UplSyncJob` (TimedJob, runs weekly) executes
- **THEN** new UPL entries MUST be added to the `upl_referentie` schema
- **AND** deprecated entries MUST be marked as `status: vervallen` (not deleted, for referential integrity)
- **AND** products referencing deprecated UPL entries MUST be flagged with a warning in the admin UI

## Current Implementation Status
- **Not implemented**: No product/service catalog functionality exists in the OpenRegister codebase. There are no UPL, SDG, product, or catalog-related services, controllers, or entities.
- **Foundation available**: OpenRegister's schema system can store product data as register objects with custom properties. The existing CRUD API, RBAC, and multi-tenancy infrastructure could serve as the foundation. The `ObjectService`, `SchemaMapper`, faceted search, and audit trail are all production-ready.
- **Configuration export/import exists**: `ConfigurationService` (`lib/Service/ConfigurationService.php`) and its handlers (`lib/Service/Configuration/ExportHandler.php`, `ImportHandler.php`) handle register/schema configuration export/import, which would be used to distribute the standard `pdc_register.json` template.
- **Public API support exists**: The existing `ObjectsController` supports public read access for published objects, which would support the public catalog API requirement.
- **Content versioning implemented**: The `content-versioning` spec is implemented, providing version tracking and audit trails that the product versioning requirement builds upon.
- **Search infrastructure implemented**: The `zoeken-filteren` spec is implemented with full-text search, faceted navigation, and multi-backend support (PostgreSQL, Solr, Elasticsearch).
- **Publication lifecycle partial**: Schemas and registers have `published` fields. Products would use object-level `publicatieStatus` property with the `PublicationLifecycleJob` for scheduled transitions.

## Standards & References
- **UPL (Uniforme Productnamenlijst)** -- maintained by VNG/Logius: https://standaarden.overheid.nl/upl. Canonical list of ~1,800 government product names with URIs.
- **IPDC (Interbestuurlijke Producten- en Dienstencatalogus)** -- national reference catalog at https://ipdc.nl. Provides product templates, descriptions, and metadata that municipalities customize for their local PDC.
- **Single Digital Gateway (SDG) Regulation (EU) 2018/1724** -- EU regulation requiring member states to provide cross-border access to public services with multilingual information. Annex I lists mandatory "life events" and procedures.
- **OWMS (Overheid Web Metadata Standaard)** -- government metadata standard using Dublin Core extensions. UPL URIs are OWMS-compliant.
- **SDG doelgroep classification** -- `burger` (citizen), `bedrijf` (business), `burger_bedrijf` (both). Maps to EU SDG user types.
- **SDG life events taxonomy** -- 21 life events for citizens and 8 for businesses, defined in SDG Regulation Annex I.
- **Dutch government PDC standards (Producten- en Dienstencatalogus)** -- VNG-recommended structure for municipal product catalogs.
- **Gemeentelijke legesverordening** -- municipal ordinance governing fees (leges) for government services.
- **BBV taakvelden** -- municipal task field classification (Besluit Begroting en Verantwoording), used for budget allocation and reporting.
- **ZGW Catalogi API** -- Standard API for zaaktype catalog (Zaakgericht Werken). Products link to zaaktypen for process automation.
- **Accept-Language header (RFC 7231)** -- HTTP content negotiation for multilingual responses.
- **ISO 4217** -- currency codes (EUR for Dutch government services).
- **ISO 639-1** -- two-letter language codes for translations (nl, en, de, fr).
- **Common Ground principles** -- API-first, data-at-the-source architecture for Dutch municipalities.
- **ADR-001**: OpenRegister as Universal Data Layer -- all domain data in OpenRegister schemas, no custom tables.
- **ADR-002**: REST API Conventions -- URL patterns, pagination (`_page`, `_limit`, `X-Total-Count`), error responses (422 for validation failures).
- **ADR-006**: OpenRegister Schema Standards -- schema.org vocabulary where applicable, Dutch government fields via native properties (not mapping layer for primary Dutch government data).

## Specificity Assessment

#### Sufficient for implementation
- Product schema is fully defined with 20+ properties covering UPL, IPDC, SDG, content blocks, pricing, channels, translations, and lifecycle.
- Publication lifecycle has explicit states, valid transitions, and scheduled automation.
- Pricing supports static, conditional, time-bounded tariffs with currency and VAT handling.
- Multilingual content uses a nested translation structure with Accept-Language negotiation.
- Public API follows ADR-002 conventions with pagination, filtering, faceting, and full-text search.
- Product bundling, versioning, zaaktype linking, and SLA definitions are specified with concrete scenarios.
- UPL import and sync mechanism is defined with lookup schema approach.
- Analytics requirements are scoped to aggregated view/search data without PII.

#### Missing or ambiguous
- **Admin UI**: The product editing interface (form layout, content block editor, translation manager) is not specified. Should leverage OpenRegister's existing schema-driven form generation.
- **SDG information exchange format**: The exact JSON structure for the SDG feed is not defined. Should be based on the Your Europe SDG technical specification.
- **IPDC sync**: Whether to pull product templates from IPDC API or only use UPL references is not decided.
- **Localization of tariff labels**: Should tariff `label` fields also be translatable, or are they Dutch-only?
- **Product images/media**: Whether products support header images, icons, or document attachments is not specified.
- **Relation to OpenCatalogi**: How the PDC relates to OpenCatalogi's existing catalog functionality (OpenCatalogi catalogs software; PDC catalogs citizen services -- separate domains).

#### Open questions
1. Should the PDC be a separate Nextcloud app or a schema template within OpenRegister? (Recommendation: schema template in OpenRegister with optional dedicated UI views, similar to how DSO is implemented.)
2. Should the IPDC product templates be importable as pre-filled product drafts, or only used as a reference?
3. How should the KCC werkplek (Pipelinq) integrate with the PDC -- embedded widget, API call, or shared register?
4. Should product analytics be stored in OpenRegister objects or in a separate lightweight analytics table to avoid audit trail overhead?
5. How does the DSO `omgevingsvergunning` relate to PDC products -- is it both a product in the PDC and a process in the DSO register?

## Nextcloud Integration Analysis

**Status**: Not yet implemented. No product/service catalog functionality exists. OpenRegister's schema system, public API, content versioning, faceted search, and configuration import/export provide the complete foundation.

**Nextcloud Core Interfaces**:
- `ISearchProvider` (`OCP\Search\IProvider`): Register a `ProductSearchProvider` for Nextcloud's unified search so that products are discoverable through the global search bar. Results link to product detail pages via the deep link registry.
- `routes.php`: Expose a public read-only API endpoint group at `/api/pdc/` (products, analytics, sdg-feed) that serves published products without authentication. Admin endpoints for product management use standard authenticated routes.
- `IAppConfig`: Store PDC configuration (UPL reference list URL, SDG doelgroep options, default content block definitions, IPDC thema taxonomy) in Nextcloud app configuration. Keys: `pdc_upl_source_url`, `pdc_supported_languages`, `pdc_sdg_enabled`.
- `ICapability`: Expose PDC availability, supported languages, and SDG compliance status via Nextcloud capabilities, enabling municipal website integrations to discover the catalog endpoint programmatically.
- `IJobList` / `TimedJob`: Register `PublicationLifecycleJob` (hourly, checks scheduled publish/depublish dates), `UplSyncJob` (weekly, refreshes UPL reference list), and `SdgFeedExportJob` (daily, generates SDG feed).
- `IEventDispatcher`: Fire `ProductPublishedEvent` and `ProductDepublishedEvent` typed events for integration with notification systems and external cache invalidation.

**Implementation Approach**:
- Model products as OpenRegister objects in a dedicated `pdc` register with schemas: `product` (main), `upl_referentie` (UPL lookup), and `product_analytics` (view/search counters). Deploy via `pdc_register.json` template through `openregister:load-register` CLI command or repair step.
- UPL validation: A schema hook (per the `schema-hooks` spec) on the `product` schema validates `uplUri` against `upl_referentie` objects on save. Warns but does not block on unrecognized URIs (new products may precede UPL updates).
- Content negotiation: The public API controller reads `IRequest::getHeader('Accept-Language')`, selects the matching translation from the product's `vertalingen` property, and falls back to Dutch. Response includes `Content-Language` header.
- Publication lifecycle: A `TimedJob` checks hourly for products where `publicatieDatum <= now AND publicatieStatus = concept` or `depublicatieDatum <= now AND publicatieStatus = gepubliceerd`, and transitions statuses automatically.
- Analytics: Product views are counted via a lightweight counter in a separate `product_analytics` schema (not on the product object to avoid version churn). Search queries with zero results are logged similarly.
- SDG feed: A daily `QueuedJob` generates a cached JSON feed of SDG-classified products with multilingual content, served at `/api/pdc/sdg-feed`.
- Zaaktype linking: `zaaktypeUris` stores references to ZGW Catalogi API zaaktype URIs. When integrated with Procest, product initiation can trigger zaak creation via OpenConnector.

**Dependencies on Existing OpenRegister Features**:
- `ObjectService` -- CRUD for product objects with filtering, pagination, and faceted search.
- `SchemaService` / `SchemaMapper` -- schema definitions with property validation for UPL URIs, enums, and structured content.
- `ConfigurationService` / `ImportHandler` -- distribute pre-built `pdc_register.json` template.
- `AuditTrailMapper` -- publication lifecycle tracking and content version history.
- Public API infrastructure -- existing unauthenticated read endpoints for published objects.
- `DeepLinkRegistryService` -- register product detail page URLs for unified search integration.
- `SearchBackendInterface` -- full-text search across product content via PostgreSQL/Solr/Elasticsearch.
- `FacetHandler` -- faceted navigation for theme and audience filtering.
- Content versioning -- version tracking for product content changes and rollback capability.
- Schema hooks -- validation hooks for UPL URI checking on product save.
- `MappingService` -- optional Twig-based property mapping for SDG feed output formatting.
