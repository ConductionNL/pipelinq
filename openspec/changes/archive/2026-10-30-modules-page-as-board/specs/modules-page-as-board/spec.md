# modules-page-as-board Delta

## ADDED Requirements

### Requirement: The Modules page draws module cards and list cards

The Modules page SHALL declare the six modules (sales, marketing, products, point of sale, contracts, loyalty) as module
cards with their pages, read whether each is in the menu from `runtime.menu.enabled`, and list the pages that belong to no
module in two list cards, Also in Pipelinq and More. Every page that had a card SHALL stay reachable from the page.

#### Scenario: Three modules in the menu

- **GIVEN** an administrator put sales, marketing and products in the menu
- **THEN** the page says "3 of 6" are in the menu and shows Also in Pipelinq and More as list cards
