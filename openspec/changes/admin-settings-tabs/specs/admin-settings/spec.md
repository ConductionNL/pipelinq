## ADDED Requirements

### Requirement: The admin page splits into tabs
The Pipelinq admin page MUST draw a tab strip with the tabs Algemeen, Koppelingen, Integraties, Kassa, Marketing en gegevens and Klantportaal, MUST mount only the open tab's sections, and MUST keep every section the page had on one of the tabs. The open tab MUST be in the address, and the title and the path MUST name it.

#### Scenario: An admin opens the Kassa tab
@e2e exclude Asserted in tests/vitest/adminSettingsTabs.spec.js; the pixel check against board PqBeheerKassa covers the drawn page.
- **GIVEN** an administrator on /settings/admin/pipelinq
- **WHEN** they open the Kassa tab
- **THEN** the address MUST read `?tab=pos`
- **AND** the page MUST show the payment providers, the tender types, the VAT rates, the till roles, the till staff and a link to the till's customer lookup
- **AND** the sections of the other tabs MUST NOT be mounted

#### Scenario: A link opens a tab
@e2e exclude Asserted in tests/vitest/adminSettingsTabs.spec.js.
- **GIVEN** the address /settings/admin/pipelinq?tab=portal
- **WHEN** the page loads
- **THEN** the title MUST read Klantportaal and the path MUST end in Klantportaal
