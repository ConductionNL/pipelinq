# A record's document as a PDF from a filinq template

## Why

PqOfferte draws "Voorbeeld pdf" beside "Naar klant sturen": the salesperson sees the quote as the client will before
sending it. Pipelinq can make a letter for a client from a filinq template, but no other record has a document of its
own, and a quote has nothing to print.

Which document a record type has is configuration, not code: the same need comes for a contract, an order confirmation
or an event invitation. So this is not a quote feature but a generic one, named by what it does.

## What changes

- App config `record_document_templates`: a JSON map from a schema slug to `{ template, ownerField? }`, the filinq
  template (in pipelinq's namespace) that makes that record's document and, optionally, the field naming its owner.
  Only a schema in the map has a document; every other schema answers 404.
- `GET /api/records/{schema}/document-template` answers `{ available, reason?, template? }`, for a header action's
  `visibleWhen`.
- `GET /api/records/{schema}/{id}/document` reads the record as the caller (OpenRegister RBAC), checks the caller may
  act on it (its owner, or a privileged group, as for letters), and has filinq render the template for it. The PDF is
  returned as a download; nothing is filed in Files and nothing is logged.
- The quote page gets the header action "Preview PDF" (nl "Voorbeeld pdf"), shown only while a template is configured
  and filinq can render it.
- The Zuiddrecht set configures the quote template once filinq's example set holds a pipelinq "Offerte" template (data).

## Not in this change

- Sending the PDF to the client (the lifecycle's send stays as it is).
- Editing templates (filinq's own pages).
