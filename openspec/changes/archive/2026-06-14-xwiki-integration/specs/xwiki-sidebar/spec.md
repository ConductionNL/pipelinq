## ADDED Requirements

---

### Requirement: XWikiSidebarTab Component

The system MUST provide a reusable `XWikiSidebarTab` Vue component that renders as a tab in detail view sidebars. It MUST include search functionality and an inline article viewer.

**Feature tier**: V1

#### Scenario: Render sidebar tab in client detail view

- GIVEN the user is viewing a client detail page
- WHEN they click the "Kennisbank" tab in the sidebar
- THEN the `XWikiSidebarTab` MUST render with a search input and article list
- AND the initial results MUST be filtered by any `contextQuery` prop value (e.g., the client's industry or category)

#### Scenario: Search articles from sidebar

- GIVEN the sidebar tab is open
- WHEN the user types "bezwaar" in the search input
- THEN the component MUST debounce (300ms) and fetch matching articles via the proxy
- AND display results as a scrollable list with title and space name

#### Scenario: View article content inline

- GIVEN the sidebar displays search results
- WHEN the user clicks an article title
- THEN the sidebar MUST switch to an article viewer mode
- AND display the article's rendered HTML content (fetched from the page content proxy endpoint)
- AND show a back button to return to the search results list
- AND show an "Open in xWiki" link that opens the full article in a new tab

#### Scenario: Context-aware filtering on request detail

- GIVEN the user is viewing a request detail page for a "Vergunning" request type
- WHEN the `XWikiSidebarTab` mounts with `contextQuery="vergunning"`
- THEN the initial article list MUST show articles matching "vergunning"
- AND the user MUST be able to clear the context filter and search freely

#### Scenario: Sidebar with xWiki unavailable

- GIVEN the xWiki instance is not reachable
- WHEN the user opens the "Kennisbank" sidebar tab
- THEN the tab MUST display "xWiki integratie niet beschikbaar" with a retry button
- AND clicking retry MUST re-check xWiki availability and reload if successful

---

### Requirement: XWikiSidebarTab Article Navigation

The system MUST support browsing xWiki pages by space within the sidebar tab.

**Feature tier**: V1

#### Scenario: Browse by space

- GIVEN the sidebar tab is open
- WHEN the user clicks "Blader op rubriek" (browse by category)
- THEN the sidebar MUST display a list of available xWiki spaces
- AND clicking a space MUST show pages within that space

#### Scenario: Navigate back from space view

- GIVEN the user is viewing pages within a space
- WHEN they click the back button
- THEN the sidebar MUST return to the space list or search view
