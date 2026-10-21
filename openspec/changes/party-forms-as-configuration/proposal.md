# Party forms as configuration

## Why

The new-client and new-contact dialogs drew hand-written forms (ClientForm, ContactForm) with their own fields, checks and defaults. The Clients and Contacts index pages already declare `fieldOverrides` for the library form; with the library form naming a wrong value in the app's words (`validation.patternMessage`) and asking for a field the schema hides elsewhere (`hidden: false`), the forms become that configuration, which dossiq can declare the same way for its contact and organisation screens.

## What changes

- The Clients page declares the client form: `includeFields` (name, type, email, phone, website, industry, account owner, correspondence language, timezone, address, notes) and `fieldOverrides` with the old checks and words (Invalid email, phone and URL format; name at most 255), the signed-in user as owner, the reader's language, and readable type labels. Its edit form uses the same.
- ClientCreateDialog is the library form dialog over that config; the save goes through the contacts-sync create with the schema.org type. It still opens the new client, or hands it back to the picker that asked.
- ContactCreateDialog is the library form dialog for name, client, role, email and phone, with the Contacts page's checks.
- Removed: ClientForm, ContactForm and utils/clientFormFields.js.

## Not kept

- Creating a client from inside the new-contact form's client field: the field picks an existing client. The request form's own picker still offers New client.
- The correspondence language list narrowed to the languages pipelinq offers: the field lists every language, as the Clients page's edit form already did.

## Needs

nextcloud-vue `feature/pl-abstract-pl1-form-pattern-message` (`validation.patternMessage`, `hidden: false` on a form).
