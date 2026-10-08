# prospect-discovery (delta)

## ADDED Requirements

### Requirement: Prospects page

The system SHALL offer a Prospects page at `/prospects` that lists the discovered prospects in a table with the columns Score, Company, Industry, Employees, Location and Actions. Score, Company and Employees SHALL be sortable, the list SHALL default to the highest fit score first, and the table SHALL be paged. Each row SHALL offer "Add as client". The page SHALL offer Refresh, which fetches the prospects again past the cache. The page SHALL be reachable from a menu entry in the Sales group and from a card in the Sales category of the Modules page.

#### Scenario: A user opens the Prospects page

- GIVEN an ideal customer profile is configured
- WHEN the user opens Prospects from the Sales group in the menu
- THEN the page SHALL be `/prospects`
- AND it SHALL list the discovered prospects with their fit score, highest score first
- AND every row SHALL offer "Add as client"

#### Scenario: Sorting orders the whole list

- GIVEN more prospects than fit on one page
- WHEN the user sorts by Company
- THEN the prospects SHALL be ordered by trade name across all pages, not only the page shown

#### Scenario: No profile configured

- GIVEN no ideal customer profile is configured
- WHEN the user opens the Prospects page
- THEN the page SHALL say that no Ideal Customer Profile is configured instead of showing an empty table
