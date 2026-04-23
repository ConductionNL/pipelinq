## MODIFIED Requirements

---

### Requirement: CRM Dashboard Layout

The dashboard grid layout MUST include an xWiki Kennisbank widget in the default configuration.

#### Scenario: Default grid includes xWiki widget

- GIVEN the user has not customized their dashboard layout
- WHEN the user navigates to the dashboard
- THEN the layout MUST include an "xWiki Kennisbank" widget
- AND the widget MUST use the `XWikiWidget` component with `showSearch="true"` and the admin-configured default space
- AND the widget MUST be placed in the grid at 6 columns width

#### Scenario: xWiki widget on dashboard when xWiki unavailable

- GIVEN the xWiki instance is not reachable
- WHEN the dashboard loads
- THEN the xWiki widget MUST display "xWiki integratie niet beschikbaar" (translatable)
- AND the rest of the dashboard MUST render normally without errors
