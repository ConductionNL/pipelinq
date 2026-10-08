# Design: requests-inbound-to-queue

## Context (read at pipelinq development cfe0a0a51, OpenRegister development 555af7212, nextcloud/mail main 8e1fb5d44)

- **The Queue is a filter.** `src/manifest.json:4297` page `Queue` is an index
  over `ticket` with base filter `assignee: "IS NULL"` and `status_notIn`
  resolved, completed, rejected, converted, closed (open change
  `retire-queue-concept`). Any ticket created with no assignee and an open
  status is in the Queue. Its columns include `channel`; its quick filters are
  the `ticketType` tabs.
- **Tickets.** `TicketService::save(string $ticketType, array $payload, ?string $uuid)`
  (`lib/Service/TicketService.php:334`) is the write path for tickets. The
  `ticket` schema (`lib/Settings/register.d/99-unify-ticket-supertype.json`)
  has `channel` (free string, facetable, :232), `category` (free string, not
  facetable, :271), `priority` (low, normal, high, urgent), `client`,
  `contact`, `assignee` and `channelMetadata` (a free object).
- **WhatsApp inbound.** `WhatsAppAdapter::handleInboundWebhook()` (:602)
  verifies the signature, finds or creates a placeholder contact by phone,
  opens or reuses a `channelConversation`, stores the message as a
  `channelMessage` (not in `channelConversation`, as the matrix evidence
  says), then handles the STOP and opt-in keywords. It creates no ticket.
  `SmsAdapter::handleInboundWebhook()` (:384) does the same for SMS, with the
  keywords at :429 and :437. The Messages section that shows these messages is
  mounted at `src/manifest.json:2144` (ClientDetail) and :2636
  (ContactDetail), not :2137.
- **Mail.** `EmailMatchService` reads a user's own Mail account from the Mail
  app's tables: `listInboundMessages()` (:912) selects `mail_messages` joined to
  `mail_mailboxes` by account since a cursor, over every mailbox of the
  account; `fetchMailMessageMeta()` (:828) reads uid, subject, sent_at and the
  sender from `mail_recipients` (type 0). `matchEmailToEntities()` (:133) and
  `matchDomainToOrganization()` (:172) find the client or contact;
  `matchAndLinkMessage()` (:278) links through the OpenRegister email leaf
  (`linkEmail`, :347). `EmailMatchJob` runs it per user. Nothing reads a
  mailbox that belongs to a team.
- **Mail app data.** nextcloud/mail `lib/Db/Message.php` carries
  `threadRootId` (:99) and `previewText` (:116); `lib/Db/Mailbox.php:107`
  `isInbox()` is the mailbox named INBOX. The route `/box/{mailboxId}/thread/{id}`
  opens a thread (`appinfo/routes.php:39-40`).
- **Social.** `lib/Service/Social/` holds publish adapters only
  (`SocialNetworkAdapter` has publish, metrics and followers calls). Every call
  goes through `SocialBrokerGateway`, which hands OpenRegister's credential
  broker a method and a path; the broker owns host and token. Facebook and
  Instagram share the broker provider `meta-graph`
  (`FacebookPageAdapter::brokerProvider()`, :72). Posting waits on Meta App
  Review (`FacebookPageAdapter.php` docblock).
- **Routing today.** `RoutingService::getSuggestedAgents()` (:92) matches a
  ticket's `category` against agent skills and ranks agents. The Queue
  removed routing to buckets (`retire-queue-concept` proposal).
- **Flows.** pipelinq already ships a flow declaratively:
  `lib/Settings/register.d/27-enquiry-to-lead-flow.json` puts an
  `x-openregister-flows` entry on the `enquiry` schema with an
  `openregister.trigger-object` node, a JSONLogic `openregister.filter` and
  `openregister.object-write` nodes. OpenRegister's `TriggerObjectNode` fires
  on `object.created`, `object.updated` and `object.deleted` (:80-84) for one
  register and schema. `DecisionTableNode` (`openregister.decision-table`)
  takes `table`, `inputMapping`, `outputMapping`, `defaultOutputs` and
  `resultKey`, and refuses a table it cannot run when the flow is saved; its
  own form edits the table as JSON in a text area (`configForm()`). The cell
  grammar (`UnaryTestEvaluator`) is wildcard, comparisons, ranges, `in (...)`
  and literal equality, with no substring test. A shipped flow has no owner
  and does not run until someone adopts it (`flow#adopt`,
  OpenRegister `appinfo/routes.php:851`); `flow#update` and `flow#publish`
  (:863, :854) save and publish a new version.
- **Pages.** pipelinq has a Flows index and the shared flow canvas
  (`src/manifest.json` pages `Flows`, `FlowDetail`). Admin settings live in
  `src/views/settings/Settings.vue`.

## Decisions

### D1. One intake service for every channel

`InboundTicketService::receive()` takes a channel, a thread key, the sender
(address or phone, domain, display name), the matched `client` and `contact`,
a title, a text, the mailbox or account it came in on, and a link back to the
source. It looks for an open ticket with the same thread key. If there is one,
it adds the message to that ticket; if that ticket is `awaiting_customer`, it
moves it to `in_progress`. If there is none, it calls `TicketService::save('request', ...)`
with `status: new`, no assignee and `priority: normal`. The ticket is in the
Queue from that moment, because the Queue is the unassigned open tickets.

### D2. A thread key as its own property

New ticket property `inboundThreadKey` (string), written by the intake only:
`conversation:<channelConversation id>` for WhatsApp and SMS,
`mail:<account id>:<threadRootId>` for mail, `meta:<conversation id>` for
Facebook and Instagram. A top-level property keeps the lookup a plain
equality filter. The sender, the domain, the mailbox, the client segment and
the source link go in `channelMetadata`, where the routing flow reads them.

### D3. WhatsApp and SMS call the intake after storing the message

Both `handleInboundWebhook()` methods call `receive()` after `persistMessage()`,
with the conversation id as thread key and the contact they already resolved.
A message that matched the STOP or opt-in keyword does not open a ticket. A
failure in the intake is logged and never fails the webhook, so the provider
does not retry a message that was stored.

### D4. Ticket mailboxes are Mail accounts the administrator names

An administrator adds a team mailbox as a Nextcloud Mail account (for example
on a functional user) and names that account in the new Ticket mailboxes
section of the admin settings. The setting holds account ids in app config
`inboundMail.accounts`. `MailIntakeJob` (a `TimedJob`, every five minutes)
reads, per account, the messages of its INBOX mailbox since that account's
cursor, with the same query shape as `listInboundMessages()` plus the mailbox
name. For each message it resolves the sender with `matchEmailToEntities()` and
`matchDomainToOrganization()`, calls `receive()` with the subject as title and
`previewText` as text, and links the Mail message to the ticket through the
email leaf the way `matchAndLinkMessage()` does. Mail sent from one of the
configured mailbox addresses is skipped, so an answer the team sends does not
open a ticket.

### D5. Facebook and Instagram through the broker

`SocialInboxJob` walks the connected page and business accounts
(`socialAccount` records for `facebook` and `instagram`). Per account it asks
the broker for `GET /{version}/{page-id}/conversations` with `platform=messenger`
or `platform=instagram`, newest first, and reads messages newer than the
account's cursor. A message from the visitor goes to `receive()` with the
conversation id as thread key, the sender's name in `channelMetadata` and no
contact (Meta gives a page-scoped id, not an address). A refusal from the
broker or from Meta is stored in a new `messagingStatus` property on the
`socialAccount` record, next to the `status` and `statusReason` that
publishing uses, so the account page says why messages do not arrive without
changing what it says about posting.

### D6. Routing rules are an OpenRegister flow

A new fragment ships the flow "Route incoming tickets" on the `ticket` schema:
trigger `object.created`; a filter that keeps tickets whose `channel` is email,
whatsapp, sms, facebook or instagram and whose `category` is empty; a decision
table with inputs `channel`, `fromAddress`, `fromDomain`, `mailbox` and
`clientSegment` (read from `channelMetadata`), outputs `category` and
`priority`, hit policy first, and default outputs that leave the category empty
and the priority normal; then an object write that updates the ticket. The
table's `resultKey` records which rule matched on `channelMetadata.routing`, so
an agent can see why a ticket went where it went, and OpenRegister keeps the
flow run. The shipped flow has no rules and no owner: routing is off until an
administrator adopts it. This is ADR-022: pipelinq ships no rule engine of its
own.

### D7. A Routing rules page edits the table

The decision table step edits its table as JSON. That is too much to ask of a
functional administrator. A new admin page `RoutingRules` shows the rules of
the shipped flow as rows (channel, sender address, sender domain, mailbox,
client segment, then category and priority), lets the administrator add,
reorder and remove rows, and saves through `PUT /api/flows/{id}` and
`POST /api/flows/{id}/publish`. OpenRegister refuses a table it cannot run and
the page shows that refusal. When the flow canvas gets its own table editor,
this page can become a link to it.

### D8. The Queue filters by team

`category` on the ticket becomes `facetable`, and the Queue page lists it as a
facet, so a team opens the Queue on its own category. Skill routing reads the
same field and suggests a colleague on the ticket.

## Risks

- The Mail app keeps `mail_messages` current only for mailboxes it syncs. The
  Ticket mailboxes section tells the administrator to switch on background
  sync for that account, and the job reports per account when the newest
  message it saw is old.
- A busy social account can return many conversations. The job reads one page
  per account per run and keeps a cursor, so a backlog drains over several
  runs rather than in one.
- A rule that sets a category no skill covers leaves the ticket in the Queue
  with no suggestion. That is the same result as a ticket with no category,
  and the facet shows it.
- The social half does nothing until Meta approves `pages_messaging` and
  `instagram_manage_messages` for the Conduction app, and until the broker's
  `meta-graph` provider admits the conversations paths. Both are outside this
  repository; the tasks for that half say so.
