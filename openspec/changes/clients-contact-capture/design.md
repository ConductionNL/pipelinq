# Design: clients-contact-capture

## Context (read at pipelinq development cfe0a0a51, Nextcloud server 35 checkout)

- **Create path.** The Clients and Contacts pages set
  `createOverride: createClientContactAware` and `createContactContactAware`
  (`src/manifest.json:1565`, `:2317`); `src/registry.js:1057-1068` routes both
  through `createWithContact()` and `POST /api/contacts-sync/create`, because a
  client needs a Nextcloud contact (`contactsUid`) first.
- **Existing parsers.** `lib/Service/PhoneNormaliser.php::normaliseForOrg()`
  gives an E.164 number and is what CTI uses (`CtiService.php:265`).
  `lib/Service/KvkResultMapper.php` knows the KvK row shape. There is no free
  text parser.
- **Recognition.** Nextcloud's TaskProcessing API has task type
  `ImageToTextOpticalCharacterRecognition::ID = 'core:image2text:ocr'`
  (`lib/public/TaskProcessing/TaskTypes/`). pipelinq supports Nextcloud 32 to 34
  (`appinfo/info.xml:96`); the task type must be checked for availability at
  run time, since a provider app may be absent.
- **Language model.** pipelinq asks hermiq for model work through a lazy
  resolve that degrades to nothing when hermiq is absent
  (`lib/Service/Competitor/RelevanceScorer.php`, "IT DEGRADES TO UNSCORED,
  NEVER TO ZERO").

## Decisions

### D1. Rules first, model second, person last

`ContactCaptureService::parse(string $text)` first applies rules that cannot be
wrong in a way a person misses: email addresses, phone numbers through
`PhoneNormaliser`, URLs, a KvK number labelled KvK or KVK, and a Dutch postcode
with its city. It then asks hermiq to label the remaining lines as person name,
organisation or job title. Without hermiq, those lines are returned as
unassigned, and the dialog lets the user pick a field for each. The result is a
proposal; only the user's Save writes.

### D2. The photo path is Nextcloud's OCR task

A photo is uploaded to the parse route as a file, handed to
`ITaskProcessingManager` as a `core:image2text:ocr` task, and its text goes
into D1. When no provider serves the task type, the dialog hides the photo
option and says why; the paste option keeps working. The image is not written
to Files or OpenRegister.

### D3. The dialog fills the ordinary form

`ContactCaptureModal` never saves. On Use these fields it opens the page's own
create dialog with the proposed values, so the same validation, the same
contact-first create and the same duplicate warnings apply as for a typed
contact.

### D4. One route, authenticated, no object access

`POST /api/contact-capture/parse` takes `text` or a `file`, returns proposed
fields and unassigned lines, and stores nothing. It carries `#[NoAdminRequired]`
and needs a logged-in user; it touches no existing object, so there is no
per-object guard to add.

## Risks

- Parsing text can put a person's name in the organisation field. D1 keeps the
  model's labels visible and editable, and D3 keeps the person in charge.
- A card photo holds personal data. D2 sends it only to the local TaskProcessing
  provider and keeps nothing. Where the provider is an external service, that is
  the administrator's configuration of Nextcloud AI, named in the user doc.
