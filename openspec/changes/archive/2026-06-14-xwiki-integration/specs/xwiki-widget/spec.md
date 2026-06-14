## ADDED Requirements

---

### Requirement: XWikiWidget Component

The system MUST provide a reusable `XWikiWidget` Vue component that displays a list of xWiki articles. The widget can be embedded in dashboard grids, detail page cards, or any page layout.

**Feature tier**: V1

#### Scenario: Render widget with default configuration

- GIVEN an `XWikiWidget` is placed on the dashboard with no filter props
- WHEN the component mounts
- THEN it MUST fetch the 5 most recent xWiki pages via the proxy search endpoint
- AND display each result as a clickable list item showing title and space name
- AND show a "Kennisbank" header

#### Scenario: Filter widget by space

- GIVEN an `XWikiWidget` has prop `space="Kennisbank"`
- WHEN the component mounts
- THEN it MUST only display pages from the "Kennisbank" xWiki space

#### Scenario: Filter widget by tags

- GIVEN an `XWikiWidget` has prop `tags="['burgerzaken', 'paspoort']"`
- WHEN the component mounts
- THEN it MUST only display pages that have at least one of the specified tags

#### Scenario: Filter widget by search query

- GIVEN an `XWikiWidget` has prop `query="verhuizing"`
- WHEN the component mounts
- THEN it MUST display pages matching the search query "verhuizing"

#### Scenario: Show search input when enabled

- GIVEN an `XWikiWidget` has prop `showSearch="true"`
- WHEN the user types "paspoort" in the search input
- THEN the widget MUST debounce the input (300ms) and refresh results with the search query
- AND show a loading indicator while fetching

#### Scenario: Click article to open in xWiki

- GIVEN the widget displays a list of articles
- WHEN the user clicks an article title
- THEN the browser MUST open the article's xWiki URL in a new tab
- AND the link MUST use `target="_blank"` with `rel="noopener noreferrer"`

#### Scenario: Widget with no results

- GIVEN the xWiki search returns zero results for the configured filters
- WHEN the widget renders
- THEN it MUST display the message "Geen artikelen gevonden" (translatable)

#### Scenario: Widget when xWiki is unavailable

- GIVEN the xWiki instance is not reachable
- WHEN the widget tries to load
- THEN it MUST display "xWiki integratie niet beschikbaar" (translatable)
- AND the widget MUST NOT crash or throw uncaught errors

---

### Requirement: XWikiWidget Limit Configuration

The system MUST allow configuring the maximum number of articles shown in the widget.

**Feature tier**: V1

#### Scenario: Custom result limit

- GIVEN an `XWikiWidget` has prop `limit="3"`
- WHEN the component fetches articles
- THEN it MUST display at most 3 articles
- AND if more results exist, show a "Meer bekijken" link that opens xWiki in a new tab
