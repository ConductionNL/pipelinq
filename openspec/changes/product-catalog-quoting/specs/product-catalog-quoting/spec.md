# Product Catalog Quoting - Delta Spec

## Status: planned

## ADDED Requirements

### Requirement: Quote entity

The system MUST provide a Quote entity stored as an OpenRegister object linked to leads with lifecycle management.

#### Scenario: Create a quote from a lead
- GIVEN a lead "Gemeente ABC digital transformation"
- WHEN the user clicks "Offerte maken" on the lead detail view
- THEN a new Quote MUST be created linked to the lead with status "concept"
- AND a sequential quote number MUST be generated (e.g., "OFF-2026-0042")

### Requirement: Quote line items with calculations

Quotes MUST support line items with quantity, unit price, discount, and tax calculations.

#### Scenario: Add product line items to quote
- GIVEN a quote in "concept" status
- WHEN the user adds products from the catalog
- THEN each line item MUST show: product name, quantity, unit price, discount, line total
- AND the quote MUST auto-calculate subtotal, tax amount, and grand total

### Requirement: PDF proposal generation

The system MUST generate professional PDF proposals from quotes via Docudesk.

#### Scenario: Generate PDF from accepted quote
- GIVEN a complete quote with line items
- WHEN the user clicks "Genereer PDF"
- THEN the system MUST produce a branded PDF using NL Design System tokens
