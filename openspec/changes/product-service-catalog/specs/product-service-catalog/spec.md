# Product & Service Catalog (PDC) - Delta Spec

## Status: planned

## ADDED Requirements

### Requirement: IPDC/UPL-compliant product schema

Products MUST be stored as OpenRegister objects with IPDC-compliant schema including UPL references.

#### Scenario: Create a product linked to UPL and IPDC
- GIVEN the pdc register is provisioned
- WHEN the admin creates a product with uplNaam, uplUri, ipdcUri, publicNaam
- THEN the product MUST be stored and the UPL URI MUST be validated

### Requirement: Publication lifecycle

Products MUST support concept -> gepubliceerd -> gearchiveerd lifecycle.

#### Scenario: Publish a product
- GIVEN a draft product
- WHEN an editor publishes it
- THEN the product MUST become visible in search results and the public API

### Requirement: Public read-only API

The system MUST expose a public API for citizen portal integration.

#### Scenario: Citizen portal fetches products
- GIVEN published products in the PDC
- WHEN an external system queries the public API
- THEN it MUST receive all published products with content blocks and pricing
