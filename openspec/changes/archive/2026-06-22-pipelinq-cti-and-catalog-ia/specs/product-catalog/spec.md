# Product Catalog — Catalog group relabel delta

**Spec ref**: `product-catalog`

This delta is information-architecture placement only — no feature contract,
data model, or API change.

## ADDED Requirements

### Requirement: Catalog group labelled "Product catalog"

The system MUST label the left-nav group that contains the product list and
product barcode search as "Product catalog" (not the bare "Catalog"). The
underlying group id `Catalog` and the routes of its child entries MUST be
retained so existing relocations and links keep resolving.

#### Scenario: User sees the Product catalog group in the nav

- GIVEN the user opens the Pipelinq app
- WHEN they read the left-nav top-menu groups
- THEN a group labelled "Product catalog" MUST be visible
- AND no group labelled exactly "Catalog" MUST be present
- AND expanding it MUST still show the Products entry under its existing route
