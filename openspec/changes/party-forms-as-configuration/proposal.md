# Party forms as configuration

## Why

The new-client and new-contact dialogs drew hand-written forms (ClientForm, ContactForm) with their own fields, checks and defaults. The Clients and Contacts index pages already declare `fieldOverrides` for the library form; with the library form naming a wrong value in the app's words (`validation.patternMessage`) and asking for a field the schema hides elsewhere (`hidden: false`), the forms become that configuration, which dossiq can declare the same way for its contact and organisation screens.

## What changes

- The Clients page declares the client form: `includeFields` (name, type, email, phone, website, industry, account owner, correspondence language, timezone, address, notes) and `fieldOverrides` with the old checks and words (Invalid email, phone and URL format; name at most 255), the signed-in user as owner, the reader's language, and readable type labels. Its edit form uses the same.
- ClientCreateDialog is the library form dialog over that config; the save goes through the contacts-sync create with the schema.org type. It still opens the new client, or hands it back to the picker that asked.
- ContactCreateDialog is the library form dialog for name, client, role, email and phone, with the Contacts page's checks, and a New client button that puts the new client in the client field.
- The correspondence language lists the languages pipelinq offers, named as Nextcloud names them (GET /api/correspondence-languages answers `options`), starting with the reader's language.
- Removed: ClientForm, ContactForm and utils/clientFormFields.js.

## Needs

nextcloud-vue `feature/pl-abstract-pl1-form-pattern-message` (`validation.patternMessage`, `hidden: false` on a form).
