# ticket-list Specification (delta)

## ADDED Requirements

### Requirement: A saved view of the tickets list can be shared

The tickets list SHALL let a reader save the current filters as a view and SHALL offer, while one of the reader's own views is open, "Share: {name}" in the toolbar, opening the saved-view share dialog (people and groups, may use or may update), the same dialog dossiq's case list uses.

#### Scenario: Klachten deze week
@e2e exclude configuration of a library feature; tests/vitest/ticketsShareSavedView.spec.js asserts the configuration, nextcloud-vue CnSavedViewsControlShare.spec.js the button

- GIVEN Pieter saved the tickets list filtered on complaints received this week as "Klachten deze week"
- WHEN he opens that view
- THEN the toolbar shows "Delen: Klachten deze week" beside "Weergave opslaan"
- AND the button opens "Weergave delen: Klachten deze week"
