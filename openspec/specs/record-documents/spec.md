# record-documents Specification

## Purpose
A record type can have a document, made by filinq from a configured template.

## Requirements

### Requirement: A configured record type has a document

Pipelinq SHALL read `record_document_templates` (a JSON map from a schema slug to `{ template, ownerField?, lists? }`,
`template` an id or a slug, `lists` the record's lists `[{ schema, field, as, order? }]`) and SHALL
make a document only for a schema in the map, from a template in pipelinq's filinq namespace. A schema not in the map,
or a template that is not pipelinq's, SHALL answer 404.

#### Scenario: A quote's PDF

- **GIVEN** `record_document_templates` maps `salesQuote` to filinq template T in pipelinq's namespace
- **WHEN** a salesperson asks `GET /api/records/salesQuote/{id}/document` for a quote they may act on
- **THEN** filinq renders T for that quote and the PDF comes back as a download
- **AND** nothing is filed in Files and nothing is logged

#### Scenario: A template named by its slug, with the record's lines

- **GIVEN** `record_document_templates` maps `salesQuote` to `{ template: "offerte", lists: [{ schema: "salesQuoteLine",
  field: "quote", as: "lines" }] }`
- **WHEN** the quote's document is asked for
- **THEN** pipelinq resolves "offerte" through filinq's slug resolver in pipelinq's namespace and renders the template it
  names, with the quote's lines passed as the list `lines`

#### Scenario: A schema without a document

- **GIVEN** `record_document_templates` has no entry for `client`
- **WHEN** someone asks `GET /api/records/client/{id}/document`
- **THEN** the answer is 404 and filinq is not called

### Requirement: The caller may act on the record before anything is made

The document endpoint SHALL read the record as the caller and SHALL check, before filinq is called, that the caller is
the record's owner (`ownerField`, default `ownerId`) or in a privileged group. Otherwise it SHALL answer 404, as for a
record that does not exist.

#### Scenario: Someone else's quote

- **GIVEN** a quote assigned to Pieter Jansen and `ownerField` `assignee`
- **WHEN** a user who is not Pieter and not privileged asks for its document
- **THEN** the answer is 404 and filinq is not called

### Requirement: The document is offered only when it can be made

`GET /api/records/{schema}/document-template` SHALL answer `{ available: true, template }` when the schema has a
configured template and filinq can render it, and `{ available: false, reason }` otherwise, so a header action shows
only when it works.

#### Scenario: Without filinq

- **GIVEN** filinq is not installed
- **WHEN** the quote page asks whether a quote has a document
- **THEN** the answer is `available: false` and the page shows no "Voorbeeld pdf"
