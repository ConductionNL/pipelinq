# Design: work-letter-from-filinq-template

## Context (read at pipelinq development cfe0a0a51, dossiq development 96b8ef8fc, filinq development 7af2f9554)

- **pipelinq renders mail, not documents.** `lib/Service/TemplateRenderer.php:77`
  `render()` fills `{{var}}` placeholders in a Berichtenbox or mail template and
  returns subject and body. Nothing in `lib/Service` produces a PDF or docx.
- **The fleet rule.** hydra ADR-075 (proposed): filinq owns document
  generation, apps never embed a PDF engine, and absence is explicit. It asks
  for a published contract; filinq ships none yet (no contract or capability
  class in its `lib/`).
- **How dossiq does it.** `lib/Service/Beschikking/FilinqTemplateEngineAdapter.php`
  resolves `Service\DocumentService` through its `FleetAppId` helper (the id and
  the namespace moved when docudesk became filinq), refuses when filinq or the
  acting user is absent, and calls
  `generateDocument($templateId, $dataRefs, ['format' => 'pdf', 'adHocData' =>
  ..., 'userId' => ..., 'filename' => ..., 'output' => ['mode' => 'both']])`
  (:152-163). It reads `content` and `output.fileId` from the result (:403-416).
  Its dialog, `src/dialogs/BeschikkingComposerDialog.vue`, lists templates and
  posts to dossiq's own backend, never to filinq directly.
- **What filinq offers.** `lib/Service/DocumentService.php:168`
  `generateDocument(string $templateId, array $dataRefs, array $options)`:
  dataRefs are `{register, schema, id}`, output mode `both` stores the file in
  the user's Files and returns the bytes. `lib/Service/DataResolverService.php`
  keys the resolved data by schema name, so a template reads `client.name`.
  `lib/Service/TemplateService.php:358` `getTemplatesByNamespace()` lists the
  templates an app owns; the `template` schema carries `namespace`, `category`
  and `content` (Twig and HTML). filinq's app id is `filinq`
  (`appinfo/info.xml`).
- **pipelinq already has the helper.** `lib/Support/FleetAppId.php:265`
  `getService()` tries `OCA\Filinq` then `OCA\DocuDesk` (:213).
- **Where a letter lands in the CRM.** A ticket with `ticketType: interaction`,
  `direction: outbound` and `channel` (a free string whose vocabulary includes
  `letter`, `99-unify-ticket-supertype.json`) is a logged contact moment.
- **Pages.** ClientDetail (`src/manifest.json:1591`) and TicketDetail (:2232)
  declare no `headerActions`. nextcloud-vue 2.57.1 `visibleWhen` can read a
  field of a same-origin endpoint (`utils/visibleWhen.js`, endpoint mode).

## Decisions

### D1. filinq renders, pipelinq asks from the server

`FilinqLetterAdapter` follows dossiq's adapter: `FleetAppId::getService()` for
`Service\TemplateService` and `Service\DocumentService`, no rendering of its
own. The browser talks only to pipelinq, as in dossiq. When filinq publishes
the ADR-075 contract, the two resolves move to it and nothing else changes.

### D2. Templates belong to pipelinq by namespace

The dialog lists `getTemplatesByNamespace('pipelinq')`. A records officer
writes letter templates in filinq with namespace `pipelinq`; pipelinq seeds
none. The template reads `client`, `contact` and `ticket` keys.

### D3. The data is referenced, not copied

`POST /api/clients/{id}/letters` takes `templateId`, and optionally `contactId`
and `ticketId`. It reads each object through OpenRegister as the caller, so a
user who may not read the client gets 404, then passes them to filinq as
`dataRefs` (`{register: pipelinq, schema: client|contact|ticket, id}`), not as
copied values. filinq's resolver reads them again with its own checks.

### D4. Output: a download and a copy in Files

The adapter asks for `format: pdf` and `output.mode: both`. The response
streams the PDF to the browser and returns the Files id in a header, so the
user prints it at once and the copy stays in their Files.

### D5. The letter is a contact moment

After filinq answers, pipelinq saves an interaction ticket on the client:
`direction: outbound`, `channel: letter`, title "Letter: <template name>",
`parentTicket` when made from a ticket, and a description naming the file.
The client's timeline then shows the letter like any other contact.

### D6. Absent is visible, never faked

`GET /api/letters/templates` answers `{available: false, reason: ...}` when
filinq cannot be resolved, and the header action's `visibleWhen` reads
`available`. The POST answers 503 with the reason. No mock letter, no empty
PDF. This mirrors dossiq: "ABSENCE IS AN ERROR HERE, NOT A FALLBACK".

## Risks

- filinq's internal service signatures can change under this adapter. The
  adapter's PHPUnit test pins the call, and ADR-075's contract is the lasting
  fix; the PR links filinq's tracking issue for it.
- A template that names a field the client lacks renders an empty spot.
  filinq returns resolution warnings; the dialog shows them after the render.
