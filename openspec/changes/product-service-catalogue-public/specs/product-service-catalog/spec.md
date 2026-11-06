# product-service-catalog Specification (delta)

## ADDED Requirements

### Requirement: A service is a product with catalogue fields, published through OpenRegister (REQ-PSC-001)

A service in the catalogue SHALL be a `product` object with `type: service`, not a separate schema. The `product` schema SHALL carry, additively: `audience` (SDG target groups), `uplReference` (URI from the UPL list), `tariffs` (a table of rows with label, amount and conditions), `publishFrom` and `publishUntil`, `summary`, `contentBlocks`, `applyUrl` and `channels`. A service SHALL be readable without login exactly when it is published in OpenRegister and the current date lies between `publishFrom` and `publishUntil` (or `publishUntil` is empty); otherwise an anonymous read SHALL return 404. The public read SHALL use OpenRegister's public object API with its filters, search, facets and pagination; pipelinq SHALL NOT add a controller that re-serves the objects.

#### Scenario: A published service is public
- GIVEN the service "Passport application" with `publishFrom` yesterday, published, with two tariff rows
- WHEN an anonymous client reads the published products of type service through OpenRegister's public API
- THEN "Passport application" is returned with its summary, audience, UPL reference and both tariff rows

#### Scenario: A service scheduled for next month
- GIVEN a service with `publishFrom` next month
- WHEN an anonymous client asks for it by id
- THEN the response is 404

#### Scenario: Commercial products stay private
- GIVEN a product of type `goods` used at the till
- WHEN an anonymous client lists published products
- THEN it is not returned
