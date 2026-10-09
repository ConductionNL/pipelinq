# Design: messaging-saved-replies-and-resend

## Context (read at pipelinq development cfe0a0a51, nextcloud-vue 2.57.1, nextcloud/mail main 8e1fb5d44)

- **The Messages section shows no messages today.**
  `src/views/messaging/MessagingConversationSection.vue:409` calls
  `objectStore.fetchCollection('message', ...)`. Commit 6eb7d8e2b (#1827)
  renamed the object type to `channelMessage` (`src/config/objectTypes.js:372`)
  and left this call on the old slug. nextcloud-vue's `_getTypeConfig()`
  (`src/store/useObjectStore.js:295-300`) throws for an unregistered type,
  `fetchCollection()` catches it and returns `[]` (:641-650), so the section
  always says "No messages yet". The matrix evidence for `cm-failed-send` says
  a failed message is "shown red on the Messages section"; the red class exists
  (`deliveryStatusClass()`, :319) but no row reaches it. This was read in the
  code, not driven in a browser.
- **The composer.** `src/modals/SendMessageModal.vue` offers SMS and WhatsApp.
  The approved template picker (:74-100, NcSelect at :84) shows only when
  `whatsappNeedsTemplate()` (:212) is true: WhatsApp with the 24-hour session
  window closed. SMS and WhatsApp inside the window get a free-text
  `NcTextArea` (:101) with no saved text. The matrix evidence says the template
  picker serves "WhatsApp or SMS"; it serves WhatsApp outside the window only.
- **Sending.** `MessagingController::send()` checks `ObjectOwnerAccessPolicy::isPrivileged()`
  and loads the contact through the register RBAC before
  `MessagingService::send()` (:200-240) calls `SmsAdapter::send()` or
  `WhatsAppAdapter::send()`. Consent, budget and session-window checks live in
  the adapters.
- **Failed messages.** `WhatsAppAdapter::persistFailureAndAlert()` (:831-879)
  and `SmsAdapter::persistFailureAndAlert()` (:486) store an outbound
  `channelMessage` with `deliveryStatus: failed`, the body (or
  `[template:<name>]` for a template send, `bodyForSend()` :556) and the
  `templateId`. The WhatsApp failure row does not store `templateParameters`,
  so a failed template send cannot be repeated from the row alone. The schema
  (`lib/Settings/register.d/80-whatsapp-sms-channel.json`, `channelMessage`)
  has `deliveryStatus` enum `queued, sent, delivered, read, failed, expired`
  and a free `metadata` object.
- **Berichtenbox retry.** `appinfo/routes.php:557` (not :556)
  `berichtenboxAdmin#retry` resets a `berichtenboxMessage` to `queued`.
  `BerichtenboxAdminController` is admin only by the framework default; no file
  in `src/` mentions Berichtenbox.
- **Mail templates.** `lib/Service/TemplateRenderer.php` renders
  BerichtenboxTemplate subject and body for the Berichtenbox bridge only
  (its docblock, :6-14; callers: `BerichtenboxService` only). pipelinq has no
  composer for one-to-one mail: `src/components/ContactChannelsSection.vue:63`
  renders each email address as a `mailto:` link.
- **Nextcloud Mail compose.** `PageController::compose(string $uri)`
  (nextcloud/mail `lib/Controller/PageController.php:465`, route `/compose`)
  parses a `mailto:` URI with `subject` and `body` and opens the composer.
- **The portal reply.** The `ticket` schema
  (`lib/Settings/register.d/99-unify-ticket-supertype.json:351-368`) has
  `customerMessage` ("Shown to the customer on this request in the customer
  portal") and `portalReplies` (the customer's replies, written by
  `PortalRequestService::addReply()` :298). The bespoke portal reads
  `customerMessage` (`PortalRequestService::customerNotes()` :521). No page in
  `src/` writes it except the generic data widget on TicketDetail. portaliq
  reads tickets through `lib/Portal/PortalContributionProvider.php`, whose
  `clientRequests` whitelist (:189-195) and `clientComplaints` whitelist
  (:226-232) leave `customerMessage` out, so a portaliq user never sees the
  agent's answer.
- **Where sections mount.** TicketDetail declares `bodyWidgets` at
  `src/manifest.json:2293` (RoutingSuggestionSection). Sections are registered
  with `kind: 'section'` in `src/registry.js` (:806).
- **Mail availability.** `Application::resolveDependencyStatuses()`
  (`lib/AppInfo/Application.php:995`) reports `enabled` per manifest dependency
  into the `dependency_statuses` initial state. `mail` is not a manifest
  dependency yet.

## Decisions

### D1. Fix the Messages section first

Task 1 changes the fetch to `channelMessage`. Every other part of this change
reads that list, and a resend button on a list that never fills is a guard
with no call site. A Vitest spec asserts the registered slug and the fetched
slug are the same string.

### D2. A saved reply is a pipelinq record

New schema `savedReply` in `lib/Settings/register.d/81-saved-replies.json`,
added to the `pipelinq` register's schema list (unioned by
`ConfigFileLoaderService`, `components.registers.*.schemas`). Properties:
`title` (required), `body` (required, plain text), `channels` (array of
`sms`, `whatsapp`, `email`, `portal`), `language` (optional, same codes as
`correspondenceLanguage`) and `active` (boolean, default true). A declarative
index page `SavedReplies` (`/saved-replies`) with a menu entry next to
Queue and All tickets lets the team add and edit them. No controller: the store talks to
OpenRegister (ADR-022).

### D3. Placeholders are filled in the browser, then edited

A reply body may use `{{client.name}}`, `{{contact.name}}`, `{{ticket.title}}`
and `{{agent.name}}`. The picker fills them from the records the page already
holds and from `getCurrentUser().displayName`. A placeholder with no value
stays in the text as written, so the agent sees it. The text lands in the
composer; nothing is sent by picking. This is how snippets work at HubSpot and
canned responses at Odoo, and it keeps consent and window checks where they are.

### D4. One picker, three hosts

`SavedReplyPicker.vue` is an NcSelect with `inputLabel` "Saved reply" that
lists active replies whose `channels` include the host's channel, searchable by
title and text. Replies in the party's `correspondenceLanguage` sort first.
Hosts: `SendMessageModal` (channel `sms` or `whatsapp`, free-text branch only;
an approved WhatsApp template stays the only option outside the window),
`CustomerReplySection` (channel `portal`) and `WriteEmailModal` (channel
`email`).

### D5. Reply to the customer section on TicketDetail

`CustomerReplySection` (`kind: 'section'`) shows the ticket's `portalReplies`
oldest first, then a text area bound to `customerMessage` with the picker, a
checkbox "Also set the ticket to waiting for the customer" (ticked by default)
and one Send answer button, as the PqTicketAntwoord board draws it (decision
130; this replaced the two buttons Save answer and Save and wait). With the box
ticked, saving also sets `status` to `awaiting_customer` (the status the portal reply path already
resumes from, `PortalRequestService` :326). It renders for `ticketType`
`request` and `complaint` only.

### D6. portaliq shows the answer

Add `customerMessage` to the `clientRequests` and `clientComplaints` `fields`
whitelists and to the request `detail.fields` in
`PortalContributionProvider.php`. Without this the bespoke portal shows the
answer and portaliq, which replaces it (open change `portal-contribution`),
does not.

### D7. Email goes through Nextcloud Mail

`WriteEmailModal` opens from a Write email button next to each address in
`ContactChannelsSection`. It holds a subject field and the picker, then opens
`/apps/mail/compose?uri=mailto:<address>?subject=<s>&body=<b>` in a new tab.
pipelinq sends no mail itself. Add `mail` to `manifest.dependencies` as
optional; when `dependency_statuses.mail.enabled` is false, the modal opens a
plain `mailto:` link with the same subject and body instead.

### D8. Send again is a server action with the normal checks

New route `POST /api/messaging/messages/{id}/resend` on `MessagingController`
(`#[NoAdminRequired]`, same `isPrivileged()` check as `send()`). The controller
loads the `channelMessage` through the register RBAC, refuses anything that is
not outbound with `deliveryStatus` `failed` or `expired`, loads its contact the
same way, and calls a new `MessagingService::resend()`. That calls the normal
`send()` with the row's channel, body, `templateId` and `templateParameters`,
so consent, budget and window checks run again. On success the new row carries
`metadata.resendOf` and the failed row gets `metadata.resentAs`; the failed
row keeps its status, so the history stays true. Both `persistFailureAndAlert()`
methods start storing `templateParameters` so a failed template send can be
repeated.

### D9. A closed window turns Send again into the composer

When the resend answers `template-required` (a free-text WhatsApp whose
24-hour window has closed), the section opens `SendMessageModal` on WhatsApp so
the agent picks an approved template. A second failure shows the server's
reason on the row and adds no new button until the agent tries again.

## Risks

- A double click on Send again could send twice. The button disables while the
  request runs, and the controller refuses a row that already has
  `metadata.resentAs`.
- `customerMessage` holds one answer. A second answer replaces the first on the
  portal. That is the current model; the section says so under the text area.
- Opening Mail in a new tab loses the link to the ticket. The Mail message is
  linked to the client afterwards by the existing matcher
  (`EmailMatchService::matchAndLinkMessage()` :278) when the agent has matching
  switched on.
