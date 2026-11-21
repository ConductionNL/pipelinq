## ADDED Requirements

### Requirement: The Advanced page shows the menu as it is set

The Advanced page SHALL show a status on each card, and a panel naming the menu profile and the modules in and out
of the menu, read from `runtime.menu`.

#### Scenario: Two modules on in the simple menu

- **GIVEN** the simple profile with Sales and Products switched on
- **WHEN** a user opens the Advanced page
- **THEN** the Modules card SHALL read "2 van 6 modules aan" and the panel SHALL list Menu "Eenvoudig" and "Verkoop, Producten"
