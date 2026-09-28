---
kind: code
depends_on: []
---

# Proposal: requests-inbound-to-queue

## Summary

A message a client sends should land where the team already looks: the Queue.
Today a WhatsApp or SMS message sits on one contact's page, mail to the shared
mailbox stays in Mail, and a Facebook or Instagram message never reaches
pipelinq at all. This change turns each of them into a ticket in the Queue,
keeps a follow-up on the same ticket, and lets an administrator route new
tickets to the right team by rules they manage themselves.

## Motivation

Two rows of the pipelinq capability matrix (`openspec/parity/capabilities.json`),
both decided `build` by the OpenSpec pass of 2026-09-27. They share one intake:
a message from outside becomes a ticket.

**`cm-social-media`**, "Answer messages from social media in the same queue as
mail and phone". Rated partial, built.state built. Matrix evidence:
"lib/Service/WhatsAppAdapter.php:602 handleInboundWebhook stores inbound
WhatsApp in channelConversation, shown on contact and client detail by
src/views/messaging/MessagingConversationSection.vue (src/manifest.json:2137);
a ticket's channel can be set to 'social' by hand
(lib/Settings/register.d/99-unify-ticket-supertype.json:235). No inbound reader
for Facebook, Instagram, X or LinkedIn messages: lib/Service/Social/ only
publishes posts". Note: "WhatsApp arrives but lands per contact, not in the
shared queue next to mail and phone; social network direct messages are not
ingested at all". Demand: tender https://www.tenderned.nl/aankondigingen/overzicht/401736,
with intelligence-db requirements#16378 (Gemeente Noordwijk, 2025-11-20:
"telefonie, email, webchat, WhatsApp en berichten van social media") and
requirements#174677 (GGD Groningen, 2025-11-18: "social media naar de juiste
contactcentergroep routeren"). Also seen in the Odoo 20 release notes
(https://www.odoo.com/odoo-20-release-notes). One competitor rates it yes:

- hubspot-crm: https://knowledge.hubspot.com/chatflows/create-a-facebook-messenger-chatflow,
  "When you connect your Facebook Messenger account to help desk or the
  conversations inbox" messages land in the same inbox as email and chat;
  WhatsApp connects the same way.

pipedrive rates it partial (WhatsApp linked to deals, no social queue),
espocrm, odoo-crm and kiss no.

**`req-mail-routing`**, "Route mail arriving in a shared mailbox to the right
team by rules you manage yourself". Rated no, built.state none. Matrix
evidence: "lib/Service/EmailMatchService.php:278 matchAndLinkMessage links a
user's Mail message to the matching client or contact by address or domain;
lib/Service/RoutingService.php:92 suggests agents for an existing ticket by
skill. Nothing reads a shared mailbox, turns mail into a ticket or routes it to
a team by admin-managed rules". Demand: tender
https://www.tenderned.nl/aankondigingen/overzicht/386888, with
intelligence-db requirements#174066 (Kadaster CRM, 2025-07-19: "routering op
basis van regels zoals e-mailadres, domein of afzendergroep, beheerd door
geautoriseerde gebruikers, gelogd") and requirements#210381 (Gemeente
Amsterdam omnichannel, 2022-03-21: "inkomende e-mails intelligent doorzetten
naar de juiste behandelaarsgroep"). Three competitors rate it yes:

- hubspot-crm: https://knowledge.hubspot.com/help-desk/route-tickets-in-help-desk,
  "set up automatic routing rules so that tickets are automatically routed to
  specific users and teams" including "tickets created manually or via email".
- espocrm: source read, `application/Espo/Resources/metadata/entityDefs/EmailFilter.json`
  filters on from, to, subject and body route group inbox mail into team
  folders; group inboxes can assign to a team with round robin
  (`InboundEmail.json:167` `caseDistribution`).
- odoo-crm: source read, `addons/crm/models/crm_team.py:25` each team has an
  email alias that turns incoming mail into leads for that team;
  `addons/base_automation/models/base_automation.py:193` "On incoming message"
  rules reassign team or user without code.

pipedrive rates it partial (a shared team inbox, "automations ... will be
possible in future releases"), kiss no.

## What changes

- One intake turns an inbound message into a ticket of type request, with the
  channel, the sender, the matched client or contact and no assignee. An
  unassigned open ticket is in the Queue already, so the Queue shows it with no
  change to the Queue itself.
- A follow-up on the same WhatsApp or SMS conversation, the same mail thread or
  the same social conversation lands on the open ticket instead of opening a
  new one.
- WhatsApp and SMS: every inbound message goes through the intake, except the
  opt-in and opt-out keywords.
- Shared mailbox: an administrator names one or more Nextcloud Mail accounts as
  ticket mailboxes. A background job reads their inbox and feeds the intake.
- Facebook page and Instagram business messages: a background job reads new
  conversations of each connected account through the credential broker and
  feeds the intake.
- Routing rules: pipelinq ships a flow "Route incoming tickets" that sets the
  category and priority of a new inbound ticket from a table of rules on
  channel, sender address, sender domain, mailbox and client segment. An
  administrator switches it on and edits the rules on a Routing rules page.
- The ticket category becomes a filter on the Queue, so a team opens its own
  part of it.

## Out of scope

- Replying to Facebook or Instagram from pipelinq. The ticket links to the
  conversation; the agent answers in Meta's inbox.
- X and LinkedIn direct messages. This change starts with Facebook and
  Instagram, which read through the one Meta provider the broker already holds
  for posting (`FacebookPageAdapter::brokerProvider()` is `meta-graph`). The
  other networks are a follow-up.
- Rules on words in the subject or the text. OpenRegister's decision table
  grammar has comparisons, ranges and sets, not "contains". Named as a
  follow-up for OpenRegister.
- Assigning a person. Rules set the team (category); skill routing already
  suggests the person on the ticket.

## Impact

- New `lib/Service/InboundTicketService.php`, called from
  `WhatsAppAdapter::handleInboundWebhook()` and `SmsAdapter::handleInboundWebhook()`.
- New `lib/BackgroundJob/MailIntakeJob.php` and `lib/BackgroundJob/SocialInboxJob.php`.
- New fragment `lib/Settings/register.d/99-inbound-ticket-intake.json`: ticket
  properties `inboundThreadKey` and `category` facetable, `socialAccount`
  property `messagingStatus`, the shipped flow "Route incoming tickets".
- Admin settings: a Ticket mailboxes section, and a Routing rules page.
- `src/manifest.json`: category facet on the Queue page, the Routing rules page.
- Depends on OpenRegister's credential broker admitting the Meta conversation
  read paths, and on Meta approving `pages_messaging` and
  `instagram_manage_messages` for the Conduction app.
