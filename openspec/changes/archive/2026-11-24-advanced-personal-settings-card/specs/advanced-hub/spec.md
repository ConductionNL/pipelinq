# advanced-personal-settings-card Delta: advanced-hub

## ADDED Requirements

### Requirement: The Advanced page opens personal settings

The Advanced page SHALL end its Administrator group with a Personal settings card that every user sees. The card SHALL
open the user settings dialog and SHALL NOT navigate to a settings page. The panel badge SHALL read in the purple tone.

#### Scenario: A user opens personal settings from the Advanced page

- **GIVEN** a user who is not an administrator on the Advanced page
- **WHEN** the user clicks "Persoonlijke instellingen"
- **THEN** the user settings dialog SHALL open on the same page
