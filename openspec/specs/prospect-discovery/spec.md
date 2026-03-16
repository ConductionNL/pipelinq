# Prospect Discovery Specification

## Purpose

The prospect discovery capability enables sales teams to find new potential clients by searching public company registries (KVK Handelsregister, OpenCorporates) against a configurable Ideal Customer Profile (ICP). Results are displayed in a dashboard widget, scored by fit, with existing clients excluded. This transforms prospecting from a manual external research task into an integrated, data-driven workflow within the CRM.

**Feature tier**: V1

---

## ADDED Requirements

### Requirement: Ideal Customer Profile Configuration

The system MUST provide an admin-configurable Ideal Customer Profile (ICP) that defines which companies are good prospects. ICP criteria are stored via IAppConfig and used by the prospect discovery service to filter and score results.

| Criterion | Type | Description |
|-----------|------|-------------|
| `sbiCodes` | array of strings | Target SBI (Standaard Bedrijfsindeling) codes. Matches if company's SBI starts with any listed code. |
| `employeeCountMin` | integer | Minimum number of employees (0 = no minimum) |
| `employeeCountMax` | integer | Maximum number of employees (0 = no maximum) |
| `provinces` | array of strings | Target Dutch provinces (e.g., "Noord-Holland", "Zuid-Holland"). Empty = all. |
| `cities` | array of strings | Target cities. Empty = all. |
| `legalForms` | array of strings | Target legal forms (e.g., "BV", "NV", "Stichting"). Empty = all. |
| `excludeInactive` | boolean | Exclude companies with non-active KVK registration. Default: true. |
| `keywords` | array of strings | Additional search keywords to narrow results. |

#### Scenario: Configure ICP via admin settings
- GIVEN the user is an admin
- WHEN they navigate to Pipelinq admin settings → "Prospect Discovery" section
- THEN the system MUST display form fields for all ICP criteria
- AND the admin MUST be able to save the ICP configuration
- AND saved criteria MUST persist across sessions

#### Scenario: SBI code selection
- GIVEN the ICP configuration form
- WHEN the admin selects SBI codes
- THEN the system MUST provide a searchable multi-select with SBI code descriptions (e.g., "62 - IT-dienstverlening", "72 - Onderzoek")
- AND the system MUST support prefix matching (selecting "62" matches "6201", "6202", etc.)

#### Scenario: Empty ICP configuration
- GIVEN no ICP criteria are configured
- WHEN the prospect discovery widget attempts to search
- THEN the system MUST display a message: "Configure your Ideal Customer Profile in admin settings to discover prospects"
- AND the system MUST NOT make API calls without criteria

---

### Requirement: KVK API Integration

The system MUST integrate with the KVK Handelsregister Zoeken API as the primary prospect data source for Dutch companies. The integration runs server-side via a PHP service.

#### Scenario: KVK API key configuration
- GIVEN the admin settings page
- WHEN the admin enters a KVK API key
- THEN the key MUST be stored securely via IAppConfig (sensitive)
- AND the system MUST validate the key by making a test API call
- AND success/failure MUST be displayed to the admin

#### Scenario: Search KVK with ICP criteria
- GIVEN a valid KVK API key and configured ICP
- WHEN the prospect discovery service executes a search
- THEN the system MUST call the KVK Zoeken API with translated ICP criteria
- AND the system MUST request fields: kvkNumber, tradeNames, addresses, SBI activities, employee counts
- AND the system MUST handle pagination (up to 100 results per page)

#### Scenario: KVK API unavailable
- GIVEN the KVK API returns an error or times out
- WHEN the prospect widget requests results
- THEN the system MUST display a user-friendly error message
- AND the system MUST log the error details for admin debugging
- AND previously cached results (if any) MUST still be displayed

#### Scenario: KVK API rate limiting
- GIVEN the KVK API responds with HTTP 429 (Too Many Requests)
- WHEN the system receives this response
- THEN the system MUST respect the Retry-After header
- AND the system MUST serve cached results until the rate limit window expires

---

### Requirement: OpenCorporates Integration

The system SHOULD integrate with the OpenCorporates API as a supplementary data source, providing international company data and additional context for Dutch companies.

#### Scenario: OpenCorporates search
- GIVEN OpenCorporates is enabled in admin settings (optional)
- WHEN the prospect discovery service executes a search
- THEN the system MUST call the OpenCorporates API with company name keywords and jurisdiction filter
- AND results MUST be merged with KVK results (deduplicated by KVK number where possible)

#### Scenario: OpenCorporates disabled
- GIVEN OpenCorporates is not configured
- WHEN the prospect discovery service executes
- THEN the system MUST use KVK results only
- AND no error MUST be shown

---

### Requirement: Prospect Fit Scoring

The system MUST calculate a numeric fit score (0-100) for each prospect based on how well it matches the ICP criteria.

Scoring rules:
- **SBI code match**: +30 points (exact match) or +15 points (prefix match)
- **Employee count in range**: +25 points
- **Location match** (province or city): +20 points
- **Legal form match**: +15 points
- **Active registration**: +10 points
- **Keyword match** in trade name or activities: +10 points per keyword (max +20)

The total is capped at 100.

#### Scenario: High-fit prospect
- GIVEN ICP criteria: SBI "62", employees 10-100, province "Noord-Holland", legal form "BV"
- WHEN a company matches: SBI "6201", 45 employees, Amsterdam (Noord-Holland), BV
- THEN the fit score MUST be: 30 (SBI exact) + 25 (employees) + 20 (location) + 15 (legal form) + 10 (active) = 100

#### Scenario: Partial-fit prospect
- GIVEN the same ICP criteria
- WHEN a company matches: SBI "6209", 200 employees, Utrecht, BV
- THEN the fit score MUST be: 30 (SBI exact) + 0 (employees out of range) + 0 (wrong province) + 15 (legal form) + 10 (active) = 55

#### Scenario: Low-fit prospect
- GIVEN the same ICP criteria
- WHEN a company matches: SBI "4711" (retail), 5 employees, Groningen, Eenmanszaak
- THEN the fit score MUST be: 0 (SBI mismatch) + 0 (employees below min) + 0 (wrong province) + 0 (wrong legal form) + 10 (active) = 10

---

### Requirement: Existing Client Exclusion

The system MUST exclude companies that are already registered as clients in Pipelinq from the prospect results.

#### Scenario: Exclude by KVK number
- GIVEN an existing client with a KVK number stored in a custom field
- WHEN the prospect discovery finds a company with the same KVK number
- THEN that company MUST be excluded from the prospect results

#### Scenario: Exclude by company name
- GIVEN an existing client named "Acme B.V."
- WHEN the prospect discovery finds a company with trade name "Acme B.V."
- THEN that company MUST be excluded from the prospect results
- AND the name match MUST be case-insensitive and ignore common suffixes (B.V., N.V., B.V, etc.)

#### Scenario: No existing clients
- GIVEN no clients exist in Pipelinq
- WHEN the prospect discovery runs
- THEN all matching prospects MUST be shown (no exclusions)

---

### Requirement: Prospect Dashboard Widget

The system MUST provide a dashboard widget displaying the top prospects matching the ICP, integrated into the existing Pipelinq dashboard.

#### Scenario: Widget with prospects
- GIVEN a configured ICP and valid API key
- WHEN the user views the dashboard
- THEN the "Prospect Discovery" widget MUST display the top 10 prospects sorted by fit score (descending)
- AND each prospect card MUST show: company name, fit score (as percentage badge), SBI description, employee count, city, KVK number
- AND each card MUST have a "Create Lead" action button

#### Scenario: Create lead from prospect
- GIVEN a prospect displayed in the widget
- WHEN the user clicks "Create Lead"
- THEN the system MUST open the lead creation dialog pre-filled with:
  - title: company trade name
  - source: "prospect_discovery"
- AND the system MUST create a new Client (type: organization) with the prospect's company details (name, KVK number, address, website)
- AND the new lead MUST be linked to the new client

#### Scenario: Widget loading state
- WHEN the dashboard loads and prospect data is being fetched
- THEN the widget MUST show a loading skeleton
- AND the widget MUST NOT block other dashboard widgets from rendering

#### Scenario: Widget with no results
- GIVEN a configured ICP that returns no matching prospects
- WHEN the user views the dashboard
- THEN the widget MUST display "No prospects found matching your profile"
- AND the widget MUST suggest adjusting ICP criteria

#### Scenario: Widget with no ICP configured
- GIVEN no ICP criteria are set
- WHEN the user views the dashboard
- THEN the widget MUST display a setup prompt: "Configure your Ideal Customer Profile to discover prospects"
- AND the prompt MUST link to admin settings

#### Scenario: Widget refresh
- WHEN the user clicks a refresh button on the prospect widget
- THEN the system MUST clear the cache and re-fetch prospects from the API
- AND the widget MUST show a loading state during refresh

---

### Requirement: Prospect Result Caching

The system MUST cache prospect search results to minimize API calls and improve dashboard performance.

#### Scenario: Cache fresh results
- GIVEN a successful API search
- WHEN results are returned
- THEN the system MUST cache the results in APCu with a TTL of 1 hour
- AND the cache key MUST include a hash of the ICP criteria (so criteria changes invalidate the cache)

#### Scenario: Serve cached results
- GIVEN cached results exist and are less than 1 hour old
- WHEN the dashboard loads
- THEN the system MUST serve cached results without making new API calls
- AND a "Last updated: X minutes ago" indicator MUST be shown

#### Scenario: Cache invalidation on ICP change
- GIVEN the admin changes ICP criteria
- WHEN the prospect widget next loads
- THEN the system MUST discard the old cache and fetch fresh results

---

## MODIFIED Requirements

_(none)_

## REMOVED Requirements

_(none)_

---

### Current Implementation Status

**Implemented:**
- **ICP Configuration:** `lib/Service/IcpConfigService.php` stores and retrieves ICP criteria via IAppConfig. Criteria include sbiCodes, employeeCountMin/Max, provinces, cities, legalForms, excludeInactive, keywords.
- **ICP Admin Settings UI:** `src/views/settings/ProspectSettings.vue` provides form fields for all ICP criteria: SBI codes (comma-separated text), employee count min/max, provinces (multi-select), legal forms (multi-select), KVK API key, OpenCorporates toggle.
- **KVK API Integration:** `lib/Service/KvkApiClient.php` implements KVK Handelsregister Zoeken API calls with ICP criteria translation. Handles pagination.
- **KVK API key configuration:** Stored via IAppConfig (sensitive). Validation on save is handled in `lib/Controller/ProspectSettingsController.php`.
- **OpenCorporates Integration:** `lib/Service/OpenCorporatesApiClient.php` implements supplementary company search. Enabled/disabled via admin settings. Results merged with KVK results, deduplicated by KVK number.
- **Prospect Fit Scoring:** `lib/Service/ProspectScoringService.php` implements the full scoring algorithm:
  - SBI code match: 30 points (prefix match).
  - Employee count in range: 25 points.
  - Location (province) match: 20 points.
  - Legal form match: 15 points.
  - Active registration: 10 points.
  - Total capped at 100 (by nature of the individual scores summing to 100).
- **Existing Client Exclusion:** `ProspectDiscoveryService::excludeExistingClients()` filters prospects by matching trade names (case-insensitive, fuzzy with contains check). However, `getExistingClientNames()` currently returns an empty array (placeholder implementation).
- **Prospect Dashboard Widget:** `src/components/ProspectWidget.vue` displays top prospects with collapsible UI, loading state, error display, no-ICP setup prompt, empty state, and refresh button. `src/components/ProspectCard.vue` shows individual prospect details with "Create Lead" action.
- **Prospect Result Caching:** `ProspectDiscoveryService` caches in APCu with 1-hour TTL. Cache key includes ICP hash for automatic invalidation on criteria change. "Last updated" timestamp stored.
- **Create lead from prospect:** `ProspectDiscoveryService::createLeadFromProspect()` prepares client and lead data from prospect details. Frontend `src/store/modules/prospect.js` manages prospect state.
- **Prospect Controller:** `lib/Controller/ProspectController.php` exposes API endpoints for discovery and lead creation.
- **KVK API rate limiting:** Error handling for API failures with cached results fallback.

**Not yet implemented:**
- **Existing client exclusion by KVK number:** The spec requires exclusion by KVK number stored on client objects. The implementation has a placeholder (`getExistingClientNames()` returns empty array) -- actual client fetching from OpenRegister is not wired up.
- **SBI code searchable multi-select:** The admin UI uses a comma-separated text field instead of a searchable multi-select with SBI descriptions.
- **KVK API key validation test call:** Not verified whether the settings controller validates the key with a test API call.
- **Keyword scoring:** The scoring service does not implement keyword match scoring (+10 per keyword, max +20). Only SBI, employee, location, legal form, and active registration are scored.
- **City matching:** The ICP config supports cities but the scoring service only checks province, not city.

**Partial implementations:**
- Client exclusion exists structurally but the actual client name retrieval is a stub returning empty array. The fuzzy matching logic is implemented but never executed.
- SBI scoring gives 30 points for prefix match but does not distinguish between exact match (30) and prefix-only match (15) as the spec requires.

**Mock Registers (dependency):** This spec depends on mock KVK registers being available in OpenRegister for development and testing of prospect discovery. These registers are available as JSON files that can be loaded on demand from `openregister/lib/Settings/`. Production deployments should connect to the actual KVK Handelsregister API.

### Using Mock Register Data

This spec depends on the **KVK** mock register for prospect data and existing client exclusion testing.

**Loading the register:**
```bash
# Load KVK register (16 businesses + 14 branches, register slug: "kvk", schemas: "maatschappelijke-activiteit", "vestiging")
docker exec -u www-data nextcloud php occ openregister:load-register /var/www/html/custom_apps/openregister/lib/Settings/kvk_register.json
```

**Test data for this spec's use cases:**
- **Prospect scoring**: KVK `69599084` (Test EMZ Dagobert, Eenmanszaak, Amsterdam) -- test ICP scoring with SBI codes, location, legal form
- **Prospect scoring**: KVK `68750110` (Test BV Donald, BV, Lollum) -- test BV legal form match scoring
- **Prospect scoring**: KVK `69599068` (Test Stichting Bolderbast, Stichting, Lochem) -- test Stichting scoring
- **Existing client exclusion**: KVK `68727720` (Test NV Katrien) -- add as existing client, verify it is excluded from prospect results
- **Multiple vestigingen**: KVK `69599084` has both hoofdvestiging and nevenvestiging -- test vestiging data display
- **Inactive business**: Check for businesses with `datumEinde` set -- test `excludeInactive` filter

**Querying mock data:**
```bash
# Search KVK businesses by name or number
curl "http://localhost:8080/index.php/apps/openregister/api/objects/{kvk_register_id}/{business_schema_id}?_search=Dagobert" -u admin:admin

# List all vestigingen for a KVK number
curl "http://localhost:8080/index.php/apps/openregister/api/objects/{kvk_register_id}/{vestiging_schema_id}?_search=69599084" -u admin:admin
```

### Standards & References
- **KVK Handelsregister Zoeken API:** Primary data source for Dutch company discovery.
- **OpenCorporates API:** Supplementary international company data.
- **SBI (Standaard Bedrijfsindeling):** Dutch Standard Industrial Classification codes.
- **Common Ground:** Company discovery supports government procurement workflows.

### Specificity Assessment
- The spec is highly specific with scoring rules, ICP criteria definitions, and widget behavior.
- **Mostly implemented** with key gaps in client exclusion execution and SBI scoring granularity.
- **Open questions:**
  - Should the KVK API key be a shared organization-level setting or per-user?
  - How should the 10-result limit in the widget be configurable? The spec hardcodes top 10.
  - Should prospect results be persisted (stored as objects) or only cached transiently in APCu?
  - How does the "Create Lead" flow handle duplicate leads for the same prospect?
