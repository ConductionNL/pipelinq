# product-price-lists Specification (delta)

## Purpose

Price lists per customer segment, per country or per client set the price of a
quote line, decided on the server. From pipelinq matrix row `prod-price-lists`.

## ADDED Requirements

### Requirement: A price list holds prices for a segment, a country or one client (REQ-PPL-001)

The system SHALL let a sales administrator keep price lists with a currency, a
validity period, the segments and countries they apply to, a priority and a price
per product, optionally per variant and from a minimum quantity. A client SHALL
have a country and MAY be given its own price list.

#### Scenario: A sales administrator makes a public sector list

- GIVEN a sales administrator on the Price lists page
- WHEN they create the list Overheid 2027 in EUR for segment public-sector with product Licentie at 95
- THEN the list's detail page shows the entry Licentie at 95

### Requirement: A quote line is priced from the matching list (REQ-PPL-002)

When a product is added to a lead, the server SHALL price it from, in order, the
client's own list, the highest-priority active list for the client's segment, the
highest-priority active list for the client's country, and otherwise the
product's own tiers and base price. Only lists in the lead's currency SHALL
count. The line SHALL show which list priced it.

#### Scenario: A public sector client gets the list price

- GIVEN product Licentie with base price 120 and list Overheid 2027 pricing it at 95 for segment public-sector
- WHEN a salesperson adds Licentie to a lead of Gemeente Zeist, segment public-sector
- THEN the line's unit price is 95
- AND the line says the price comes from list Overheid 2027

#### Scenario: A client's own list wins

- GIVEN Gemeente Zeist has its own list pricing Licentie at 90
- WHEN a salesperson adds Licentie to one of its leads
- THEN the line's unit price is 90

#### Scenario: No list matches

- GIVEN a client in segment SMB and country BE with no list for either
- WHEN a salesperson adds Licentie to its lead
- THEN the line's unit price is the product's own price for that quantity

### Requirement: A typed price stays (REQ-PPL-003)

A price the salesperson types on a line SHALL be kept, SHALL be marked as set by
hand, and SHALL NOT be replaced when the quantity changes.

#### Scenario: A negotiated price

- GIVEN a line priced from a list at 95
- WHEN the salesperson types 88 and then changes the quantity to 3
- THEN the unit price stays 88 and the line says it was set by hand
