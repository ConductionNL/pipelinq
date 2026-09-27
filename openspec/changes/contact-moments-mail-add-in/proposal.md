---
kind: code
depends_on: []
---

# Proposal: contact-moments-mail-add-in

## Summary

Read a mail in Outlook and see, in a side panel, whether the sender is already
in pipelinq. If they are not, add them as a contact from that panel. Log the
mail on their record as a contact moment in one click. Today pipelinq files
mail afterwards, by matching addresses in Nextcloud Mail; nothing works while
you read.

## Motivation

One row of the pipelinq capability matrix (`openspec/parity/capabilities.json`,
compared 2026-09-24), decided `build` by the OpenSpec pass of 2026-09-27.

**`mail-client-plugin`**, "Add a contact to the CRM from inside the mail you are
reading". Rated no, built.state none. Matrix evidence: "no extension or plugin
directory in the repo". Note: "Ours files mail afterwards by matching the
address. Theirs works while you read the mail." Four competitors rate it yes:

- hubspot-crm: https://knowledge.hubspot.com/connected-email/use-contact-profiles-with-the-hubspot-sales-office-365-add-in,
  "review contact information and log sales activities in your inbox's task
  pane" while reading a mail in Outlook; Gmail has the Sales Chrome extension
  (https://knowledge.hubspot.com/connected-email/get-started-with-the-hubspot-sales-chrome-extension).
- pipedrive: https://support.pipedrive.com/en/article/pipedrive-gmail-add-on, in
  Gmail you "access a quick overview of your ongoing deals and activities with an
  email's contact person" and "add and complete activities" from the side panel.
- espocrm: https://docs.espocrm.com/extensions/outlook-integration/email/ and
  source read `client/src/views/email/detail.js:65` action `createLead` and :73
  `createContact` while reading an email inside EspoCRM.
- odoo-crm: source read, `addons/crm_mail_plugin/controllers/crm_client.py:42`
  `/mail_plugin/lead/create` and :13 `log_single_mail_content`, called by the
  Odoo Outlook and Gmail add-ins.

## What changes

- pipelinq serves an Outlook add-in: a manifest an administrator installs for
  the organisation, and a task pane page served by pipelinq.
- The colleague signs in once with their Nextcloud account through Nextcloud's
  login flow; the add-in keeps an app password, which the colleague can revoke in
  Nextcloud's security settings.
- For the open mail, the panel shows the matching contacts and clients, with
  Open in pipelinq; without a match it offers Add as contact, prefilled from the
  sender, and optionally links an organisation by mail domain.
- Log this mail creates a contact moment with channel email on the matched or
  new contact, holding the subject, the date and the first lines of the mail.

## Out of scope

- An add-in inside Nextcloud Mail. Nextcloud Mail offers no extension point in
  its message view (its `src/integration` holds only `oauth.js`; its events are
  backend only). Nextcloud Mail users keep today's automatic filing by
  `EmailMatchService`. An extension point would be an upstream request to
  nextcloud/mail, not work in this repository.
- A Gmail add-on. Outlook covers the organisations pipelinq serves; the same
  endpoints can back a Gmail add-on later.
- Storing the full mail body or its attachments.

## Impact

- New `lib/Controller/MailAddInController.php` (manifest, task pane page, three
  JSON endpoints) and a small task pane bundle under `src/mail-add-in/`.
- Reuses `EmailMatchService::matchEmailToEntities()` and
  `matchDomainToOrganization()`, and the contact-first create of
  `POST /api/contacts-sync/create`.
