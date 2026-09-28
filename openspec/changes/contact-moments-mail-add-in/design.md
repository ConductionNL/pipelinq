# Design: contact-moments-mail-add-in

## Context (read at pipelinq development cfe0a0a51, nextcloud/mail main)

- **Matching.** `lib/Service/EmailMatchService.php`:
  `matchEmailToEntities(address)` (:133) returns every `{entityType, entityId}`
  whose `contact.email` or `client.email` matches;
  `matchDomainToOrganization(domain)` (:172) finds an organisation client by a
  corporate domain and skips public providers (`isPublicDomain`, :248);
  `matchAndLinkMessage()` (:278) links a Nextcloud Mail message after the fact.
- **Create.** `POST /api/contacts-sync/create` (`appinfo/routes.php:45`) creates
  the Nextcloud contact first and then the pipelinq record, the path the Clients
  and Contacts pages use (`src/registry.js` `createWithContact`).
- **Contact moments.** A contact moment is a `ticket` with `ticketType`
  `contactmoment` written through `TicketService::save()`.
- **Nextcloud Mail.** `nextcloud/mail` `src/integration/` holds only `oauth.js`;
  `lib/Events/` are backend events (`NewMessageReceivedEvent`,
  `NewMessagesSynchronized`, ...). The sender popover
  (`src/components/RecipientBubble.vue`) offers its own Add to contact into
  Nextcloud Contacts. There is no hook for another app in the message view.

## Decisions

### D1. An Office add-in served by pipelinq

`GET /apps/pipelinq/mail-add-in/manifest.xml` returns an Office add-in manifest
(mail read form, task pane) whose source URL is
`/apps/pipelinq/mail-add-in/taskpane`, on the instance's own domain. An
administrator deploys it for the organisation through the Microsoft 365 admin
centre. The task pane page sets a content security policy that allows the
Outlook hosts as frame ancestors (`ContentSecurityPolicy::addAllowedFrameAncestorDomain`),
and nothing else changes in Nextcloud's policy.

### D2. Sign-in through Nextcloud's login flow

The task pane starts Nextcloud Login Flow v2 in a dialog and stores the returned
app password in the add-in's roaming settings. Every call is Basic auth with that
app password. Revoking it in Nextcloud's security settings signs the add-in out.

### D3. Three endpoints, all as the signed-in user

- `POST /api/mail-add-in/lookup {email}`: `matchEmailToEntities()` plus
  `matchDomainToOrganization()` for the domain, each result read through
  OpenRegister as the user so only records they may see are returned.
- `POST /api/mail-add-in/contact {name, email, client?}`: the contact-first
  create of `contacts-sync/create`.
- `POST /api/mail-add-in/log {entityType, entityId, subject, sentAt,
  preview}`: a contact moment ticket, channel `email`, direction inbound, with
  `preview` capped at 1,000 characters; the authorisation check is on the target
  record.
All three carry `#[NoAdminRequired]` and `#[NoCSRFRequired]` (Basic auth, no
session cookie) and a per-object guard where they touch a record.

### D4. The pane

A small bundle under `src/mail-add-in/` (Office.js plus plain components, no
Nextcloud shell) reads the open item's sender, subject and date through
Office.js, calls lookup, and shows: matches with Open in pipelinq; Add as
contact (prefilled name and email, suggested organisation); Log this mail.

## Risks

- Organisations must allow the add-in in Microsoft 365. The admin doc lists the
  steps; without it nothing changes for anyone.
- A mail preview is personal data. Only the first 1,000 characters are stored,
  on a contact moment that follows the P2Y archival term.
