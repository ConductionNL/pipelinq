# Product & Service Catalog (PDC)

## Problem
Implement a government product and service catalog (PDC - Producten- en Dienstencatalogus) as a core Pipelinq capability for CRM-driven citizen service delivery, conforming to the Uniforme Productnamenlijst (UPL) and Single Digital Gateway (SDG) standards. The PDC integrates with Pipelinq's existing product entities and CRM workflows, enabling KCC (Klant Contact Centrum) agents to look up products during citizen interactions, link products to contact moments, and initiate service requests. Products MUST support structured content blocks, publication lifecycle, target audience classification, pricing, multilingual content for cross-border EU access, zaaktype linking, versioning, bundling, and analytics. The catalog MUST expose a public read-only API for integration with municipal websites, citizen portals, and the SDG Your Europe portal.
**Source**: Gap identified in cross-platform analysis; mandated standard for Dutch municipalities. IPDC (Interbestuurlijke Producten- en Dienstencatalogus) is the national reference catalog; municipalities maintain local PDC instances that reference IPDC entries and extend them with local pricing, procedures, and channel information.
**Tender demand**: 65% of analyzed government tenders require a product and service catalog for citizen-facing portals, KCC werkplek integration, and omnichannel service delivery.

## Proposed Solution
Implement Product & Service Catalog (PDC) following the detailed specification. Key requirements include:
- Requirement: Products MUST be stored as register objects with IPDC/UPL-compliant schema
- Requirement: Products MUST support SDG target audience classification and cross-border compliance
- Requirement: Products MUST support structured content blocks
- Requirement: Products MUST support a publication lifecycle with scheduled publishing
- Requirement: Products MUST support pricing with structured tariff tables (leges)

## Scope
This change covers all requirements defined in the product-service-catalog specification.

## Success Criteria
- Create a product linked to UPL and IPDC
- Reject product with invalid UPL reference
- Product schema includes all IPDC-required fields
- Classify product for citizens and businesses
- SDG life event mapping
