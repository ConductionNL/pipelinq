---
kind: code
depends_on: []
---

# Proposal: messaging-saved-replies-and-resend

## Summary

An agent types the same answer many times a day. Give them saved replies they
pick from a list, in every place they answer a client: a WhatsApp or SMS
message, the message to the customer on a ticket, and an email. And when a
WhatsApp or SMS message fails, let them send it again from the message itself,
in one click.

## Motivation

Two rows of the pipelinq capability matrix (`openspec/parity/capabilities.json`),
both decided `build` by the OpenSpec pass of 2026-09-27. They share one screen,
the Messages section on the client and contact detail pages.

**`req-saved-replies`**, "Answer with a saved reply instead of typing the same
text again". Rated partial, built.state built. Matrix evidence:
"src/modals/SendMessageModal.vue:83 NcSelect `v-model="templateId"` lets an
agent answer on WhatsApp or SMS with an approved template
(TemplateApprovalSyncService), opened from the Messages section on ClientDetail
and ContactDetail; there are no saved replies for tickets, mail or the portal
reply". Note: "templates cover messaging channels only". Demand: feature
request https://github.com/odoo/odoo/issues/120198. Four competitors rate it
yes:

- hubspot-crm: https://knowledge.hubspot.com/conversations/use-snippets,
  "Snippets are short, reusable text blocks" for emails, chats and notes; help
  desk macros are "prewritten responses that can be quickly applied from the
  reply editor"
  (https://knowledge.hubspot.com/help-desk/create-and-use-macros-in-help-desk).
- pipedrive: https://support.pipedrive.com/en/article/email-templates, "Email
  templates are one of the easiest ways to save time as a salesperson by
  streamlining the process of emailing clients"; WhatsApp approved templates
  too (https://support.pipedrive.com/en/article/whatsapp-manage-templates).
- espocrm: source read, `client/src/views/email/fields/select-template.js:72`
  triggers `insert-template` so an agent picks an email template when composing
  a reply, filled with placeholders from the case and contact.
- odoo-crm: source read, `addons/mail/models/mail_canned_response.py:10`
  `_name = 'mail.canned.response'` with a shortcut and substitution text,
  inserted in any chatter or chat composer by typing `::`
  (`addons/mail/static/src/core/common/composer.js:833`).

kiss rates it no: "KISS sends no replies to clients".

**`cm-failed-send`**, "See that an email to a client failed to send and send it
again in one click". Rated partial, built.state built. Matrix evidence: "a
failed WhatsApp or SMS is stored and flagged (lib/Service/WhatsAppAdapter.php:831
persistFailureAndAlert) and shown red on the Messages section
(src/views/messaging/MessagingConversationSection.vue:319), but there is no
resend button; Berichtenbox has a retry API (appinfo/routes.php:556) that no
page calls; an email to a client is sent from Nextcloud Mail, whose outbox
handles failed sends". Note: "the failure is visible, the one click resend is
not". Demand: changelog, https://www.pipedrive.com/en/product-updates. One
competitor rates it yes:

- pipedrive: https://www.pipedrive.com/en/product-updates, "Failed send
  visibility and live retry: ... tracking failed email sends and initiating
  one-click retries directly from deal, person, organization, project and lead
  detail views".

espocrm and odoo-crm rate it partial (a failed mail is marked, resending takes
several steps), hubspot-crm unknown, kiss no.

## What changes

- The Messages section loads its messages again. It asks the object store for
  the slug `message`, which is no longer registered since the slug became
  `channelMessage`, so the list is always empty today (design.md, Context).
- A saved reply is a record with a title, a text, the channels it fits and a
  language. A Saved replies page lists them for the team to maintain.
- One saved reply picker appears in three places: the Send message dialog, a
  new Reply to the customer section on the ticket, and a new Write email action
  next to each email address of a client or contact.
- Picking a reply puts its text in the composer with the client, contact,
  ticket and agent names filled in. The agent can still edit it before sending.
- The Reply to the customer section shows what the customer wrote in the portal
  and saves the agent's answer as the ticket's message to the customer.
  portaliq shows that message on the request.
- A failed or expired WhatsApp or SMS message gets a Send again button. It
  sends the same text through the same checks as a new message, and links the
  new message to the failed one.

## Out of scope

- Personal saved replies visible to one agent only. Every saved reply is shared
  by the team in this change.
- A resend of a failed Berichtenbox message by an agent. The retry route is
  admin only by design (`BerichtenboxAdminController::retry()`), and no agent
  screen lists Berichtenbox messages yet. The admin side is the open change
  `berichtenbox-admin-stats-pagination-and-tests`.
- Resending a failed email. Agents send one-to-one email from Nextcloud Mail,
  and the Mail outbox keeps and retries a failed send.
- A conversation thread on the ticket. The message to the customer stays one
  field, as today; a new answer replaces the previous one.

## Impact

- `src/views/messaging/MessagingConversationSection.vue`: fetch `channelMessage`,
  add Send again on failed and expired outbound rows.
- `src/modals/SendMessageModal.vue`: saved reply picker above the free-text body.
- New schema `savedReply` in a new fragment `lib/Settings/register.d/81-saved-replies.json`,
  a Saved replies index page and menu entry.
- New components `src/components/SavedReplyPicker.vue` and
  `src/components/CustomerReplySection.vue`, new modal
  `src/modals/WriteEmailModal.vue`, all registered in `src/registry.js`.
- New route `POST /api/messaging/messages/{id}/resend` on `MessagingController`,
  with the send logic in `MessagingService`.
- `lib/Portal/PortalContributionProvider.php`: `customerMessage` on the
  `clientRequests` and `clientComplaints` whitelists.
- `src/manifest.json`: Mail as an optional dependency, the new section on
  TicketDetail.
