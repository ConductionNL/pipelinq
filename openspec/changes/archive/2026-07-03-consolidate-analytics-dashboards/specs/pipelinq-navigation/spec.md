## MODIFIED Requirements

### Requirement: REQ-NAV-PQ-002 — All Reports Live Under A Reports & Compliance Group

The navigation SHALL present a single "Reports & Compliance" group that contains every
reporting and analytics entry — `Rapportage` (Reporting), `BillingCategories`,
`SlaAttainment` and `MdmDataQuality` — with each entry's report page remaining
routable. The MDM steward views (`MdmMasterEntities`, `MdmDuplicates`) SHALL remain
under the Settings foldout.

#### Scenario: The group holds every report and each opens

- **GIVEN** the navigation has rendered
- **WHEN** the "Reports & Compliance" group is expanded
- **THEN** it SHALL list Reporting, SLA attainment, Billing categories and Data quality
- **AND** activating each SHALL navigate to its report route
- `@e2e exclude` verified live by expanding the group and asserting child routes; no standing Playwright spec in this app.
