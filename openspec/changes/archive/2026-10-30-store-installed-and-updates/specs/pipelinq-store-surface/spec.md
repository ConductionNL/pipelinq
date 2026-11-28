# pipelinq-store-surface: delta for store-installed-and-updates

## ADDED Requirements

### Requirement: The store knows what this instance installed

After an install that wrote every component, pipelinq MUST record the item's slug and version. A store search MUST mark each card as installed or not. For an installed card it MUST give the installed version, whether the registry's version is newer, and where an item of that kind is used. It MUST also return when the registry last answered.

#### Scenario: an installed item with an update

@e2e exclude Asserted in tests/Unit/Service/Store/StoreInstallLedgerTest.php and tests/Unit/Controller/StoreControllerTest.php; the pixel check against board PqStore covers the drawn page.

- GIVEN Routering voor de servicedesk installed at 2.0.1 and the registry offering 2.1.0
- WHEN the store page searches
- THEN that card is installed, with installed version 2.0.1 and an update available

#### Scenario: a refused install is not recorded

@e2e exclude Asserted in tests/Unit/Controller/StoreControllerTest.php.

- GIVEN a store item whose only component is a lead
- WHEN an administrator installs it
- THEN nothing is written and the item is not recorded as installed
