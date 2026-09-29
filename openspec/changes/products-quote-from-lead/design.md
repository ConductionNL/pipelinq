# Design: products-quote-from-lead

## Context (read at pipelinq development ec6b0277)

- `ProductCatalogService::resolveEffectivePrice($product, $quantity, $variantSku)` returns `unitPrice`, `source`, `tierLabel`, `btwClass` and `taxRate`; `btwClassToRate()` maps a VAT class to a rate. `ProductCatalogController` exposes `POST /api/products/resolve-price`.
- `leadProduct` (register `pipelinq`) has `lead`, `product`, `quantity`, `unitPrice`, `unit`, `discount`, `discountType`, `total` (materialised by `x-openregister-calculations.total`) and `notes`. The lead detail page carries an object-list of these lines with create enabled (`src/manifest.json`, `LeadDetail`).
- `contact` and `client` carry `email`. Mail goes out through Nextcloud's `IMailer` in `AppointmentEmailService` and `EmailFallbackSender`; a logged contact moment is a ticket with `ticketType: interaction`, `direction: outbound`, `channel` (`99-unify-ticket-supertype.json`).
- Documents: pipelinq renders no PDF. The open change `work-letter-from-filinq-template` adds `lib/Service/Letter/FilinqLetterAdapter.php` (templates by namespace `pipelinq`, `generateDocument` with `format: pdf` and `output.mode: both`), and `FleetAppId::getService()` finds filinq. This change reuses that adapter.
- `salesContract` is a different object: a recurring-revenue contract. The onboarding walkthrough calls a signable quotation a contract; that flow is not touched.

## Decisions

### D1. A quote is the lead's lines at the moment of sending

No new schema. Sending freezes what was sent as a PDF in the sender's Files and a contact moment that links the file and carries the quote number and total. Changing a line afterwards does not alter the sent PDF, and sending again produces the next number. A `quote` schema with versions and states is left for the spec's later requirements.

### D2. Numbers are per year and per app

`Q-2026-0001` style, allocated from the highest number found on contact moments of channel `quote` in that year. Allocation runs inside the request and a collision is retried once; two users sending in the same second is the only race and the retry closes it.

### D3. Price comes from the resolver, the user may override

The line picker calls `resolve-price` with the chosen quantity and writes `unitPrice`. Editing the unit price by hand stays possible, because negotiated prices are normal; the line then shows no tier label.

### D4. filinq renders, pipelinq mails

`QuoteService::send($leadId, $templateId, $validUntil)` reads the lead, its lines, the client and the contact through OpenRegister as the caller (404 when unreadable), passes them to `FilinqLetterAdapter` as data references, receives the PDF bytes and file id, and mails through `IMailer` with the PDF attached. The recipient is the lead's contact email, else the client email; with neither, the action refuses with a message. If the mail fails after the PDF was made, no contact moment is written and the file stays in Files.

### D5. Template ownership

A records officer or sales operations writes the quote template in filinq with namespace `pipelinq` and category `quote`; pipelinq seeds none, as with letters. The template reads `lead`, `lines`, `client`, `contact`, `totals` and `quote`.

### D6. VAT is grouped per rate

Totals are computed in PHP from the lines: for each rate the net, the VAT and the gross, rounded per line to two decimals with the same rounding as the `total` calculation. The test checks a lead with lines at 21 and 9 percent.

## Declarative-vs-imperative decision

Line totals stay declarative (existing calculation). Numbering, rendering and mailing are orchestration across filinq, the mailer and OpenRegister, which a schema cannot express, so they are PHP.

