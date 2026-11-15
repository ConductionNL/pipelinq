# commercial-dashboard Specification (delta)

## ADDED Requirements

### Requirement: Money shows in the reporting currency (REQ-RF-001)

An amount that is not tied to one object SHALL show in the reporting
currency chosen in setup (`currency` app config). An amount tied to an
object SHALL show in that object's own currency, and in the reporting
currency when the object has none. No money display in the CRM, forecast or
booking surfaces SHALL hardcode EUR.

#### Scenario: My Work shows a lead value in the chosen currency

- GIVEN setup chose USD as the currency
- AND a lead worth 1000 without a currency of its own
- WHEN the user opens My Work
- THEN the lead shows its value as a USD amount

#### Scenario: A service keeps its own currency

- GIVEN a service priced 25 in GBP
- WHEN the user opens the services list
- THEN the price shows as a GBP amount

#### Scenario: Blast attribution follows the deal

- GIVEN setup chose USD and a won deal without a currency
- WHEN a blast is linked to the deal
- THEN the attribution record carries USD
