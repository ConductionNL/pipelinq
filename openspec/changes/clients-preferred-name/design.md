# Design: clients-preferred-name

## Context (read at pipelinq development cfe0a0a51)

- **Schemas.** `lib/Settings/pipelinq_register.json` schema `contact` has `name,
  email, phone, role, client, contactsUid, marketingConsent, doNotContact,
  verifiedBSN, brpPersonId, secrecy`; `client` has `name, type, ...`, with
  fragments adding identity mirrors (`15-unify-client-contact.json`) and typed
  party fields (`17-party-fields-and-indicators.json`, whose `fieldValues`
  description says "Identity stays on the Contact").
- **Sync out.** `lib/Service/ContactVcardPropertyBuilder.php::buildProperties()`
  (:58) writes `FN`, `EMAIL`, `TEL`, `ORG` for a client or contact, used by
  `ContactVcardService::syncToContacts()` (:80).
- **Sync in.** `lib/Service/ContactDataBuilder.php::buildClientImportData()` (:79)
  and `buildContactImportData()` (:134) read `FN` into `name`.
- **Templates.** `lib/Service/TemplateRenderer.php::render()` (:77) fills
  `{{var}}` placeholders from a variables array.

## Decisions

### D1. A shipped property, mirrored on the vCard NICKNAME

`preferredName` (string, at most 100 characters, title "Preferred name") is
added to `contact` and to `client` in a new fragment
`lib/Settings/register.d/19b-preferred-name.json`. On `client` it is shown only
when `type` is `person`. The Nextcloud contact is the identity source, so the
value is written to and read from vCard `NICKNAME`, which RFC 6350 defines as
"the text to be used as the nickname", matching the roepnaam asked for in
KISS-frontend#1469.

### D2. Shown where a person is addressed

- ClientDetail and ContactDetail header: "Full name" with the preferred name
  after it, labelled.
- The CTI screen pop for a matched caller shows the preferred name first, since
  that is the name the agent says.
- Index pages do not get a column by default; it is available as an optional
  column.

### D3. A template variable with a fallback

`TemplateRenderer` gets `preferredName`, filled with the preferred name or, when
empty, the full name, so a template never renders an empty greeting.

## Risks

- A value typed in the Nextcloud Contacts app lands in pipelinq on the next sync;
  that is intended, since the contact is the identity source.
- An organisation that already keeps a roepnaam custom field ends up with two
  places. The doc says which one screens read.
