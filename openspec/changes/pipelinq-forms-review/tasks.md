# Tasks: pipelinq-forms-review

## Phase 1: Schemas

- [x] 1.1 Field order on product, client, contact, lead, leadProduct, crmTask (B1)
  - **spec_ref**: `specs/client-management/spec.md#requirement-req-cm-forms-001-forms-ask-in-a-deliberate-order`
- [x] 1.2 `x-help` on the master data fields (B2)
  - **spec_ref**: `specs/client-management/spec.md#requirement-req-cm-forms-002-master-data-fields-explain-themselves`
- [x] 1.3 VAT wording, `x-enum-labels` with rates, no EUR in price descriptions (B3, B4)
  - **spec_ref**: `specs/product-catalog/spec.md#requirement-req-pc-vat-001-vat-classes-with-configurable-rates`
- [x] 1.4 Pickers: `nc-user`, `nc-group`, `language` with `x-default`, `timezone`; `x-allow-create` on references (D1-D4, D7)
  - **spec_ref**: `specs/client-management/spec.md#requirement-req-cm-forms-003-pickers-instead-of-typed-ids`
- [x] 1.5 One language field; industry as a list; repair step and readers (D5, D6)
  - **spec_ref**: `specs/client-management/spec.md#requirement-req-cm-forms-004-one-language-field-and-industry-as-a-list`
- [x] 1.6 `parentOrganisation` as a client reference (D8)
  - **spec_ref**: `specs/client-management/spec.md#requirement-req-cm-forms-003-pickers-instead-of-typed-ids`

## Phase 2: Surfaces

- [x] 2.1 VAT rates card on the admin page; catalogue and product form follow it (B4)
  - **spec_ref**: `specs/product-catalog/spec.md#requirement-req-pc-vat-001-vat-classes-with-configurable-rates`
- [x] 2.2 Products Category column via `fkResolve`; Products and Tasks column keys (B5)
  - **spec_ref**: `specs/product-catalog/spec.md#requirement-req-pc-vat-002-the-products-list-names-the-category`
- [x] 2.3 Create client form: industry, account owner, language, timezone (D2-D6)
  - **spec_ref**: `specs/client-management/spec.md#requirement-req-cm-forms-003-pickers-instead-of-typed-ids`
- [x] 2.4 Tour order client, contact, product, lead; the create client dialog signals the tour (C2)
  - **spec_ref**: `specs/walkthrough/spec.md#requirement-req-wt-001-the-tour-starts-with-a-client`

## Phase 3: Tests

- [x] 3.1 `NormalisePartyFormFieldsTest`, `VatRatesTest`, segment `equals` on a list
- [x] 3.2 vitest: `vatClassLabels.spec.js`, `clientFormFields.spec.js`
