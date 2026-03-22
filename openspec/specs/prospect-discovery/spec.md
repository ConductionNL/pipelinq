# Prospect Discovery Specification

## Purpose

The prospect discovery capability enables sales teams to find new potential clients by searching public company registries (KVK Handelsregister, OpenCorporates) against a configurable Ideal Customer Profile (ICP). Results are displayed in a dashboard widget, scored by fit, with existing clients excluded. This transforms prospecting from a manual external research task into an integrated, data-driven workflow within the CRM.

**Feature tier**: V1

---

## Requirements

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
| `revenueMin` | integer | Minimum annual revenue in euros (0 = no minimum). |
| `revenueMax` | integer | Maximum annual revenue in euros (0 = no maximum). |
| `foundedAfter` | date | Only include companies founded after this date. Empty = no constraint. |
| `foundedBefore` | date | Only include companies founded before this date. Empty = no constraint. |
| `excludeSbiCodes` | array of strings | SBI codes to explicitly exclude (e.g., exclude "6420" holding companies). |

#### Scenario: Configure ICP via admin settings
- GIVEN the user is an admin
- WHEN they navigate to Pipelinq admin settings -> "Prospect Discovery" section
- THEN the system MUST display form fields for all ICP criteria
- AND the admin MUST be able to save the ICP configuration
- AND saved criteria MUST persist across sessions

#### Scenario: SBI code selection
- GIVEN the ICP configuration form
- WHEN the admin selects SBI codes
- THEN the system MUST provide a searchable multi-select with SBI code descriptions (e.g., "62 - IT-dienstverlening", "72 - Onderzoek")
- AND the system MUST support prefix matching (selecting "62" matches "6201", "6202", etc.)

#### Scenario: SBI code exclusion
- GIVEN the ICP configuration form
- WHEN the admin adds SBI codes to the exclusion list
- THEN companies matching excluded SBI codes MUST be removed from results even if they match inclusion criteria
- AND exclusion MUST take precedence over inclusion (e.g., include "64" but exclude "6420" removes holding companies)

#### Scenario: Empty ICP configuration
- GIVEN no ICP criteria are configured
- WHEN the prospect discovery widget attempts to search
- THEN the system MUST display a message: "Configure your Ideal Customer Profile in admin settings to discover prospects"
- AND the system MUST NOT make API calls without criteria

#### Scenario: ICP template selection
- GIVEN the ICP configuration form
- WHEN the admin clicks "Load template"
- THEN the system MUST offer pre-defined ICP templates for common Dutch government procurement contexts:
  - "ICT-dienstverleners" (SBI 62xx, 10-500 employees, BV/NV)
  - "Advies- en consultancybureaus" (SBI 70xx, 5-200 employees)
  - "Bouwbedrijven" (SBI 41-43, 20-1000 employees)
- AND selecting a template MUST pre-fill all ICP fields (overwriting existing values after confirmation)

#### Scenario: Multiple ICP profiles
- GIVEN an admin wants to prospect in multiple market segments
- WHEN they navigate to ICP configuration
- THEN the system MUST allow saving up to 5 named ICP profiles (e.g., "ICT Midmarket", "Overheid Groot")
- AND each profile MUST be independently selectable for discovery searches
- AND one profile MUST be marked as the default for the dashboard widget

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

#### Scenario: Search KVK by SBI code and location
- GIVEN ICP criteria with SBI codes ["62", "63"] and province "Noord-Holland"
- WHEN the system queries the KVK API
- THEN the system MUST construct a query combining SBI prefix filter with location filter
- AND results MUST only include companies matching both criteria (AND logic)
- AND the system MUST issue separate API calls per SBI code if the API does not support multi-SBI queries, then merge results

#### Scenario: Search KVK by company size class
- GIVEN ICP criteria with employeeCountMin=50 and employeeCountMax=250
- WHEN the system queries the KVK API
- THEN the system MUST map the employee range to KVK size classes (e.g., "0001-0004" for 50-249)
- AND results outside the requested range MUST be filtered out post-query if the KVK API size classes are coarser than the configured range

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

#### Scenario: KVK vestiging data retrieval
- GIVEN a prospect identified by KVK number
- WHEN the system fetches detailed information
- THEN the system MUST retrieve both the maatschappelijke activiteit (company) and all vestigingen (branches)
- AND the hoofdvestiging (main branch) address MUST be used as the primary address
- AND the total number of vestigingen MUST be displayed as a data point on the prospect card

---

### Requirement: OpenCorporates Integration

The system MUST integrate with the OpenCorporates API as a supplementary data source, providing international company data and additional context for Dutch companies.

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

#### Scenario: OpenCorporates enrichment
- GIVEN a prospect found via KVK
- WHEN OpenCorporates is enabled and has matching data
- THEN the system MUST enrich the prospect with additional OpenCorporates fields: incorporation date, company status, registered agent, previous names
- AND the enrichment source MUST be indicated on the prospect card as "Verrijkt via OpenCorporates"

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

#### Scenario: Exact vs prefix SBI scoring differentiation
- GIVEN ICP criteria with SBI code "6201"
- WHEN prospect A has SBI "6201" (exact match) and prospect B has SBI "6209" (same 2-digit prefix "62" but different 4-digit code)
- THEN prospect A MUST receive 30 points for SBI match
- AND prospect B MUST receive 15 points for prefix match
- AND the scoring service MUST distinguish between exact match (full code equals target) and prefix match (code starts with target but is not equal)

#### Scenario: Keyword scoring in trade name
- GIVEN ICP criteria with keywords ["cloud", "security"]
- WHEN a prospect has trade name "CloudSecure IT Solutions B.V."
- THEN the system MUST award +10 for "cloud" match and +10 for "security" match = +20 keyword points
- AND keyword matching MUST be case-insensitive and match partial words (substring)

#### Scenario: City-level location scoring
- GIVEN ICP criteria with cities ["Amsterdam", "Rotterdam"] and provinces ["Noord-Holland"]
- WHEN a prospect is located in Rotterdam, Zuid-Holland
- THEN the system MUST award +20 for city match even though the province does not match
- AND city match and province match MUST be OR logic (either triggers the 20 points)

#### Scenario: Score breakdown visibility
- GIVEN a scored prospect displayed in the widget
- WHEN the user hovers over or expands the fit score badge
- THEN the system MUST display the score breakdown showing points awarded per category
- AND categories with 0 points MUST be shown with a visual indicator of "not matched"

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

#### Scenario: Exclude existing leads
- GIVEN a prospect that was previously converted to a lead (via "Create Lead") but not yet to a client
- WHEN the prospect discovery runs
- THEN the system MUST also exclude prospects that match existing leads by KVK number
- AND the exclusion scope MUST cover both clients and active leads (not archived/lost leads)

#### Scenario: Show exclusion count
- GIVEN prospect results where 3 companies were excluded as existing clients
- WHEN the widget displays results
- THEN the widget MUST show a notice: "3 existing clients excluded from results"
- AND the notice MUST be collapsible to show the excluded company names

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

#### Scenario: Widget prospect count configuration
- GIVEN an admin configuring the prospect widget
- WHEN they set the "Display count" setting (options: 5, 10, 25, 50)
- THEN the widget MUST display the configured number of top prospects
- AND the default MUST be 10

#### Scenario: Prospect detail expansion
- GIVEN a prospect card in the widget
- WHEN the user clicks on the prospect card (not on the "Create Lead" button)
- THEN the card MUST expand to show additional details: full address, all SBI codes with descriptions, legal form, number of vestigingen, incorporation date, website (if available)
- AND the expanded view MUST include a "View on KVK" external link opening `https://www.kvk.nl/bestellen/#/kvk-nummer-{kvkNumber}`

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

#### Scenario: Configurable cache TTL
- GIVEN the admin settings page
- WHEN the admin sets a custom cache duration (options: 15 minutes, 1 hour, 4 hours, 24 hours)
- THEN the APCu cache TTL MUST use the configured value
- AND the default MUST remain 1 hour

---

### Requirement: Prospect Enrichment from Public Sources

The system MUST enrich prospect data with additional information from publicly available sources beyond the initial KVK/OpenCorporates search, giving sales teams richer context for outreach.

#### Scenario: Website detection and scraping
- GIVEN a prospect with a known KVK number
- WHEN the system enriches the prospect
- THEN the system MUST attempt to find the company website via the KVK Handelsregister (website field) or by searching for the trade name
- AND if a website is found, the system MUST extract: page title, meta description, social media links (LinkedIn, Twitter/X)
- AND extracted data MUST be stored as enrichment fields on the prospect

#### Scenario: LinkedIn company page detection
- GIVEN a prospect with a trade name
- WHEN the system enriches the prospect
- THEN the system SHOULD attempt to find the LinkedIn company page URL by matching the trade name
- AND the LinkedIn URL MUST be displayed as a clickable link on the prospect card
- AND the system MUST NOT scrape LinkedIn content (only link detection via search)

#### Scenario: Enrichment data staleness
- GIVEN enrichment data was fetched more than 30 days ago
- WHEN the prospect is displayed
- THEN the system MUST show an indicator: "Enrichment data is {N} days old"
- AND the user MUST be able to trigger a re-enrichment via a "Refresh data" action

#### Scenario: Enrichment failure handling
- GIVEN a prospect for which enrichment sources are unavailable
- WHEN the system attempts enrichment
- THEN the system MUST still display the prospect with base KVK data
- AND the enrichment fields MUST show "Not available" rather than being hidden
- AND the system MUST NOT retry failed enrichments automatically more than once

---

### Requirement: Prospect Deduplication

The system MUST prevent duplicate prospects from appearing in results and MUST detect when a newly discovered prospect matches an existing prospect, client, or lead.

#### Scenario: Deduplicate within search results
- GIVEN a KVK search returns company "Acme B.V." and OpenCorporates also returns "Acme B.V."
- WHEN results are merged
- THEN the system MUST deduplicate by KVK number (primary key)
- AND the merged prospect MUST combine data from both sources, preferring KVK data for conflicting fields

#### Scenario: Deduplicate by trade name variants
- GIVEN search results contain "Acme B.V.", "Acme BV", and "ACME B.V."
- WHEN deduplication runs
- THEN the system MUST recognize these as the same company
- AND the deduplication MUST normalize: case-insensitive comparison, strip common suffixes (B.V., BV, N.V., NV, VOF, CV), strip punctuation and extra whitespace

#### Scenario: Cross-run deduplication
- GIVEN the user ran prospect discovery yesterday and saw prospect "Beta Corp"
- WHEN they run discovery again today
- THEN "Beta Corp" MUST NOT appear as a new prospect if already present in the prospect list
- AND the system MUST update the existing prospect's data with fresh information instead

#### Scenario: Near-duplicate detection warning
- GIVEN an existing client named "Gemeente Amsterdam" with KVK 34366966
- WHEN a prospect "Gemeente Amsterdam Dienst ICT" with KVK 78901234 is found
- THEN the system MUST flag this prospect with a warning: "Possible match with existing client: Gemeente Amsterdam"
- AND the user MUST be able to dismiss the warning or confirm exclusion

---

### Requirement: Bulk Prospect Import

The system MUST support importing a list of prospect companies from external sources (CSV, Excel) to supplement API-based discovery.

#### Scenario: CSV import
- GIVEN a CSV file with columns: company_name, kvk_number, city, sbi_code, employee_count
- WHEN the user uploads the file via the prospect import dialog
- THEN the system MUST parse the CSV and create prospect entries for each row
- AND the system MUST validate required fields (at minimum: company_name)
- AND invalid rows MUST be reported in an import summary (e.g., "Row 5: missing company_name")

#### Scenario: Import deduplication
- GIVEN an imported CSV contains a company that already exists as a client or prospect
- WHEN the import is processed
- THEN duplicates MUST be flagged and excluded from import by default
- AND the user MUST be shown a summary: "12 imported, 3 duplicates skipped, 1 error"
- AND the user MUST be able to review and force-import flagged duplicates

#### Scenario: Import with ICP scoring
- GIVEN a CSV file is imported
- WHEN the import completes
- THEN each imported prospect MUST be scored against the active ICP profile
- AND the fit score MUST be displayed alongside imported prospects
- AND prospects MUST be sorted by fit score in the prospect list

#### Scenario: Column mapping
- GIVEN a CSV file with non-standard column names (e.g., "Bedrijfsnaam" instead of "company_name")
- WHEN the user uploads the file
- THEN the system MUST display a column mapping dialog allowing the user to map CSV columns to prospect fields
- AND the system MUST auto-detect common Dutch column names: "Bedrijfsnaam", "KVK-nummer", "Plaats", "SBI-code", "Medewerkers"

---

### Requirement: Prospect List Management

The system MUST allow users to organize discovered prospects into named lists for targeted outreach campaigns and market segment tracking.

#### Scenario: Create prospect list
- GIVEN the prospect discovery view
- WHEN the user clicks "New list"
- THEN the system MUST create a named prospect list (e.g., "ICT Midmarket Q1 2026")
- AND the list MUST be stored as an OpenRegister object with fields: name, description, createdAt, createdBy, prospects (array of prospect references)

#### Scenario: Add prospects to list
- GIVEN a prospect in the discovery widget
- WHEN the user selects the prospect and clicks "Add to list"
- THEN the system MUST display a dropdown of existing lists
- AND the user MUST be able to select one or more lists
- AND the prospect MUST be added to the selected lists without removing it from discovery results

#### Scenario: Bulk add to list
- GIVEN multiple prospects displayed in the widget
- WHEN the user selects multiple prospects via checkboxes and clicks "Add selected to list"
- THEN all selected prospects MUST be added to the chosen list in a single operation
- AND a success notification MUST show: "{N} prospects added to {listName}"

#### Scenario: List view
- GIVEN a prospect list with 25 prospects
- WHEN the user navigates to the list
- THEN the system MUST display all prospects in the list with their fit scores, sorted by score descending
- AND the list MUST support filtering by score range, SBI code, and location
- AND the list MUST show aggregate stats: total prospects, average fit score, score distribution

#### Scenario: Remove from list
- GIVEN a prospect in a list
- WHEN the user removes the prospect from the list
- THEN the prospect MUST be removed from the list only (not from discovery results or other lists)
- AND the removal MUST be confirmed via a dialog

#### Scenario: Export list
- GIVEN a prospect list
- WHEN the user clicks "Export"
- THEN the system MUST generate a CSV file containing all prospect data: company name, KVK number, SBI code, employee count, city, province, fit score, enrichment data
- AND the CSV MUST use semicolon as delimiter (Dutch Excel default) with UTF-8 BOM encoding

---

### Requirement: Prospect Outreach Tracking

The system MUST track outreach activities performed against prospects to prevent duplicate outreach and measure conversion effectiveness.

#### Scenario: Log outreach activity
- GIVEN a prospect in a prospect list
- WHEN the user clicks "Log outreach" on a prospect card
- THEN the system MUST present a form with fields: outreach type (phone, email, LinkedIn, in-person, other), date, notes, outcome (interested, not interested, no response, follow-up needed)
- AND the activity MUST be stored linked to the prospect

#### Scenario: Outreach status indicator
- GIVEN a prospect with logged outreach activities
- WHEN the prospect is displayed in any list or widget
- THEN the prospect card MUST show an outreach status badge:
  - Gray: "No outreach" (no activities logged)
  - Blue: "Contacted" (at least one outreach)
  - Yellow: "Follow-up needed" (most recent outcome = follow-up needed)
  - Green: "Interested" (most recent outcome = interested)
  - Red: "Not interested" (most recent outcome = not interested)

#### Scenario: Outreach history
- GIVEN a prospect with 3 logged outreach activities
- WHEN the user expands the prospect detail view
- THEN the system MUST display all outreach activities in reverse chronological order
- AND each activity MUST show: type icon, date, outcome badge, notes (truncated with expand)

#### Scenario: Outreach cooldown
- GIVEN a prospect was contacted 2 days ago with outcome "no response"
- WHEN the prospect is displayed
- THEN the system MUST show a cooldown indicator: "Last contacted 2 days ago"
- AND the system SHOULD suggest a follow-up date based on configurable rules (default: 7 days after last contact)

---

### Requirement: GDPR Compliance for Prospecting

The system MUST ensure prospect discovery and data storage complies with GDPR (AVG in Dutch law), particularly regarding processing company data and any associated personal data.

#### Scenario: Data minimization
- GIVEN the system stores prospect data
- WHEN prospect data is cached or persisted
- THEN the system MUST only store business-relevant fields (company name, KVK number, SBI codes, address, employee count, legal form)
- AND the system MUST NOT store personal names, personal email addresses, or personal phone numbers from KVK registrations
- AND the system MUST NOT store contact persons' data from the KVK API unless the user explicitly creates a contact

#### Scenario: Prospect data retention
- GIVEN a prospect has been in the system for more than 12 months without being converted to a lead
- WHEN the system runs the daily cleanup task
- THEN the system MUST flag the prospect for review
- AND the system MUST send a notification to the admin: "{N} prospects older than 12 months require review"
- AND the admin MUST be able to extend retention (12 months), archive, or delete flagged prospects

#### Scenario: Prospect data deletion
- GIVEN a user requests deletion of a prospect's data
- WHEN the admin confirms the deletion
- THEN the system MUST remove all prospect data including cached results, enrichment data, outreach logs, and list memberships
- AND the deletion MUST be logged in an audit trail

#### Scenario: Legal basis display
- GIVEN the prospect discovery admin settings
- WHEN an admin views the settings page
- THEN the system MUST display a notice explaining the legal basis for prospect data processing: "Prospect discovery uses publicly available KVK Handelsregister data (legitimate interest basis, Art. 6(1)(f) GDPR). No personal data is stored without explicit user action."

---

### Requirement: Prospect-to-Lead Conversion

The system MUST provide a structured workflow for converting prospects into qualified leads with full data transfer and tracking.

#### Scenario: Single prospect conversion
- GIVEN a prospect with fit score 85 in the discovery widget
- WHEN the user clicks "Create Lead"
- THEN the system MUST create a new client (type: organization) with fields pre-filled from prospect data: name (trade name), KVK number, address (from hoofdvestiging), SBI codes, employee count, legal form
- AND the system MUST create a new lead linked to the client with: title (trade name), source "prospect_discovery", description (SBI description + fit score), initial stage "New"
- AND the prospect MUST be marked as "converted" and excluded from future discovery results

#### Scenario: Bulk conversion
- GIVEN 5 prospects selected in a prospect list
- WHEN the user clicks "Convert selected to leads"
- THEN the system MUST create 5 clients and 5 linked leads in a single batch operation
- AND a progress indicator MUST be shown during conversion
- AND results MUST be summarized: "5 leads created, 0 failures"

#### Scenario: Duplicate prevention during conversion
- GIVEN a prospect "Acme B.V." with KVK 12345678
- WHEN the user clicks "Create Lead"
- AND a client with KVK 12345678 already exists
- THEN the system MUST NOT create a duplicate client
- AND the system MUST display: "Client already exists: Acme B.V. — lead will be linked to existing client"
- AND the user MUST confirm or cancel the conversion

#### Scenario: Conversion with qualification fields
- GIVEN a prospect being converted to a lead
- WHEN the conversion dialog opens
- THEN the system MUST additionally ask for: estimated deal value, expected close quarter, assigned sales rep, priority (high/medium/low)
- AND these fields MUST be optional (conversion can proceed without them)
- AND the assigned sales rep field MUST default to the current user

---

### Requirement: Market Segment Analysis

The system MUST provide analytical views of the prospect landscape to help sales teams identify high-value market segments and prioritize outreach.

#### Scenario: Segment overview dashboard
- GIVEN prospect discovery results with 200+ prospects
- WHEN the user navigates to "Market Analysis" view
- THEN the system MUST display aggregate statistics:
  - Total prospects by SBI sector (top-level 2-digit codes) as a bar chart
  - Geographic distribution by province as a map or chart
  - Company size distribution (employee count ranges) as a histogram
  - Average fit score per SBI sector

#### Scenario: Segment drill-down
- GIVEN the segment overview shows SBI "62 - IT-dienstverlening" with 45 prospects
- WHEN the user clicks on the SBI segment
- THEN the system MUST show all 45 prospects in that segment
- AND the view MUST support sub-filtering by province, employee count, and fit score
- AND the system MUST show sub-sector breakdown (6201, 6202, etc.)

#### Scenario: Segment comparison
- GIVEN two ICP profiles: "ICT Midmarket" and "Overheid Groot"
- WHEN the user selects "Compare segments" and picks both profiles
- THEN the system MUST display a side-by-side comparison: total prospects, average fit score, geographic spread, SBI distribution
- AND overlapping prospects (appearing in both segments) MUST be highlighted

#### Scenario: Trend tracking
- GIVEN the system has been running prospect discovery for 3+ months
- WHEN the user views the market analysis
- THEN the system MUST show trend data: new prospects discovered per month, conversion rate (prospects -> leads), average time to conversion
- AND the data MUST be presented as a line chart with monthly data points

---

### Requirement: Competitor Intelligence

The system MUST provide basic competitor intelligence by identifying companies in the same SBI sectors as the user's existing clients, helping sales teams understand their competitive landscape.

#### Scenario: Identify competitor clients
- GIVEN the user's clients include companies in SBI "6201" (computer programming)
- WHEN the user navigates to "Competitor landscape"
- THEN the system MUST show other companies in the same SBI sector and similar size range
- AND these companies MUST be grouped by sub-sector and region

#### Scenario: Competitor density map
- GIVEN prospect results for SBI "62" in Noord-Holland
- WHEN the market analysis view loads
- THEN the system MUST show the total number of companies in this sector/region
- AND the system MUST calculate a "market penetration" metric: (existing clients in segment / total companies in segment) * 100
- AND this MUST be displayed as: "Market penetration: 3.2% (4 clients of 125 companies)"

#### Scenario: White space identification
- GIVEN the user's clients are concentrated in Amsterdam and Rotterdam
- WHEN the system analyzes geographic distribution
- THEN the system SHOULD highlight provinces/cities with high prospect counts but zero existing clients as "white space opportunities"
- AND these MUST be surfaced in the market analysis dashboard as actionable recommendations

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
- **ICP templates and multiple profiles:** Not implemented -- single ICP profile only.
- **SBI code exclusion list:** Not implemented.
- **Revenue and founding date criteria:** Not implemented.
- **Prospect enrichment from public sources:** Not implemented -- no website/LinkedIn detection.
- **Prospect deduplication by trade name variants:** Basic fuzzy matching exists but normalization of suffixes (B.V. vs BV) is incomplete.
- **Bulk prospect import (CSV/Excel):** Not implemented.
- **Prospect list management:** Not implemented -- no named lists or list operations.
- **Prospect outreach tracking:** Not implemented -- no activity logging on prospects.
- **GDPR prospect data retention:** Not implemented -- no automated cleanup or retention policies.
- **Prospect-to-lead bulk conversion:** Not implemented -- only single conversion exists.
- **Conversion duplicate prevention:** Not implemented -- no check for existing clients during conversion.
- **Market segment analysis:** Not implemented -- no analytical views.
- **Competitor intelligence:** Not implemented.
- **Score breakdown visibility:** Not implemented -- score is shown as a percentage badge but no breakdown tooltip.
- **Prospect detail expansion:** Not implemented -- cards show summary only.
- **List export (CSV):** Not implemented.
- **Outreach status badges:** Not implemented.
- **Configurable cache TTL:** Not implemented -- hardcoded to 1 hour.
- **Configurable widget display count:** Not implemented -- hardcoded to 10.

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
- **GDPR / AVG:** General Data Protection Regulation (Art. 6(1)(f) legitimate interest for B2B prospecting with public registry data).
- **RFC 4180:** CSV format specification for import/export.

### Specificity Assessment
- The spec is highly specific with scoring rules, ICP criteria definitions, and widget behavior.
- **Mostly implemented** with key gaps in client exclusion execution and SBI scoring granularity.
- **Major new areas** added: prospect enrichment, deduplication, bulk import, list management, outreach tracking, GDPR compliance, market segment analysis, competitor intelligence.
- **Open questions:**
  - Should the KVK API key be a shared organization-level setting or per-user?
  - How should the 10-result limit in the widget be configurable? The spec now proposes 5/10/25/50 options.
  - Should prospect results be persisted (stored as objects) or only cached transiently in APCu? The new list management requirement implies persistence in OpenRegister.
  - How does the "Create Lead" flow handle duplicate leads for the same prospect? The spec now requires duplicate prevention with user confirmation.
  - Should enrichment data (website, LinkedIn URL) be fetched eagerly on discovery or lazily on prospect card expansion?
  - What is the maximum number of prospects the system should store per ICP profile? Consider API cost and storage limits.
