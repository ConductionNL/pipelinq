# Lead-Product Link — Quotation System Delta

## ADDED Requirements

### Requirement: Copy LeadProduct Items to Quotation

The system MUST support copying LeadProduct line items from a lead to a new quotation's QuotationLineItems when creating a quotation from a lead.

#### Scenario: Copy line items to quotation

- **WHEN** a user creates a quotation from a lead with 3 LeadProduct line items:
  - Product "Consulting" (qty: 10, unitPrice: 150, discount: 0)
  - Product "Development" (qty: 40, unitPrice: 120, discount: 5)
  - Product "Hosting" (qty: 12, unitPrice: 50, discount: 0)
- **THEN** the system MUST create 3 QuotationLineItems with:
  - `description`: populated from product name
  - `quantity`: copied from LeadProduct
  - `unitPrice`: copied from LeadProduct
  - `discount`: copied from LeadProduct
  - `taxRate`: populated from the product's taxRate
  - `product`: reference to the same product
- AND the QuotationLineItems MUST be independent from the LeadProduct items (snapshot, not live reference)

#### Scenario: Lead product changes after quotation creation

- **WHEN** a LeadProduct's unitPrice is changed from 150 to 175 after a quotation was created
- **THEN** the existing quotation's line items MUST remain at 150
- AND only new quotations created after the change MUST use 175

## MODIFIED Requirements

_(none)_

## REMOVED Requirements

_(none)_
