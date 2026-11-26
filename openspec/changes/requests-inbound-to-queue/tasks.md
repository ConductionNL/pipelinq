# Tasks: requests-inbound-to-queue

## 1. The intake

- [x] 1.1 Add fragment `lib/Settings/register.d/99-inbound-ticket-intake.json`: ticket property `inboundThreadKey` (string), `category` set `facetable: true`
  - Verify: import log has no `PARTIAL IMPORT`; `npm run check:schema-l10n` exit 0
- [x] 1.2 Add `lib/Service/InboundTicketService.php` with `receive()`: find an open ticket by `inboundThreadKey`, add the message or create a request ticket (`status: new`, no assignee, `priority: normal`, `channel`, `client`, `contact`, `channelMetadata`); move `awaiting_customer` to `in_progress` on a follow-up
  - Verify: PHPUnit `tests/Unit/Service/InboundTicketServiceTest.php`: new thread creates one ticket with no assignee; same thread twice creates one ticket; a follow-up on an awaiting ticket moves it to in progress; a closed ticket on the same thread gets a new ticket

## 2. WhatsApp and SMS

- [x] 2.1 Call `receive()` from `WhatsAppAdapter::handleInboundWebhook()` and `SmsAdapter::handleInboundWebhook()` after the message is stored; skip the STOP and opt-in keywords; catch and log an intake failure
  - Verify: PHPUnit on both adapters: a normal inbound calls the intake once with `conversation:<id>`; a STOP message does not; an intake exception still returns `received`
  - Built through the shared trait `OpensInboundTickets` and `InboundTicketService::tryReceive()`. A message from an unknown number has no conversation (the adapters open none for it), so it opens no ticket and still goes to the unknown-sender contact moment as before. The thread lookup reads past RBAC: the webhook writes as the messaging account, which may create and update tickets but not list them.
- [x] 2.2 End to end: an inbound WhatsApp from the mock provider shows in the Queue
  - Written, runs in nightly: the code and its Vitest are in development and the Playwright spec is written; not yet verified against a live instance (BUILDER-LANE UNBLOCK rule 3, 24 Nov 2026).
  - Verify: Playwright `tests/e2e/inbound-to-queue.spec.ts` posts a signed mock webhook and finds the ticket on `/queue` with channel whatsapp
  - `tests/e2e/inbound-to-queue.spec.ts` is written: it seeds a WhatsApp provider with a known secret and a contact, posts a signed delivery and a STOP, and reads the ticket through the API and on `/queue`. Not run against a live instance yet, so this stays unticked.
  - Live 26 Nov 2026 (sandbox NC 34 + MariaDB, OpenRegister main e07d9e1b): 1 passed. Red first, all in the spec: contacts now need `contactsUid`; the webhook answers 503 without a messaging service account, so the spec now makes a dedicated account in `pipelinq-messaging-service`, picks it in the admin setting and puts the previous one back afterwards; and the Queue lists the longest-waiting first, so the spec finds the new ticket through the list's search box. The app needed no change: the signed delivery opened one conversation and one open WhatsApp ticket with no assignee, and the STOP opened none.

## 3. Ticket mailboxes

- [x] 3.1 Admin endpoint `GET /api/admin/mail-intake/accounts` listing Mail accounts (id, address, owner) and a Ticket mailboxes section in `src/views/settings/Settings.vue` that stores `inboundMail.accounts`
  - Verify: PHPUnit controller test: non-admin gets 403; hydra gates route-auth and semantic-auth pass
  - Built as `MailIntakeAdminController` (`GET` and `PUT /api/admin/mail-intake/accounts`, admin by Nextcloud's default and checked again in the body, the `MessagingAdminController` pattern) over `MailIntake\MailIntakeAccounts`, which stores only ids that name a Mail account. The section is `TicketMailboxSettings.vue`. Tests: `MailIntakeAdminControllerTest`, `MailIntakeAccountsTest`.
- [x] 3.2 Add `lib/BackgroundJob/MailIntakeJob.php`: per account, INBOX messages since the cursor, sender matching, `receive()` with `mail:<account>:<threadRootId>`, email leaf link to the ticket, skip mail from the configured addresses
  - Verify: PHPUnit with a fake Mail table: two messages in one thread make one ticket; a message from the mailbox's own address makes none; the cursor advances; the Mail message is linked to the ticket
  - Built as a thin five-minute `MailIntakeJob` over `MailIntake\MailIntakeService` (one pass: per mailbox the INBOX rows above `inboundMail.cursor.<account>`, sender matched by address then domain, `tryReceive()`, the mail linked to the ticket with `EmailMatchService::linkMessageTo()`), reading through `MailIntake\MailIntakeReader` (INBOX rows with thread root, subject and preview; the type-0 sender). A message without a thread root is its own thread. Tests: `MailIntakeServiceTest`, `MailIntakeReaderTest`, `MailIntakeJobTest`; "one ticket per thread" is asserted as both messages reaching the intake on one thread key, which `InboundTicketServiceTest` already proves is one ticket.

## 4. Facebook and Instagram

- [x] 4.1 Add the conversations read call to `FacebookPageAdapter` and `InstagramBusinessAdapter` and `lib/BackgroundJob/SocialInboxJob.php` with a per-account cursor; store a broker or network refusal in a new `socialAccount.messagingStatus` property
  - Verify: PHPUnit with a faked `SocialBrokerGateway`: a new visitor message calls `receive()` with `meta:<conversation id>`; a page message is ignored; a `rejected_by_network` lands in `messagingStatus` and leaves `status` alone
  - Built as the trait `Social\ReadsMetaConversations` on `FacebookPageAdapter` (platform messenger; `InstagramBusinessAdapter` inherits it with platform instagram): one brokered `GET /v21.0/{page}/conversations` of 25 conversations with their 10 newest messages, and the visitor messages in the answer (the page's own are left out). `Social\SocialInboxService` reads each active Facebook and Instagram account with a credential, keeps a per-account cursor in `socialInbox.cursor.<account>`, calls `tryReceive()` with `meta:<conversation id>`, the sender's name and the account handle as `mailbox`, and stores `receiving` or the gateway's refusal in the new `socialAccount.messagingStatus` and `messagingStatusReason` through `SocialAccountService::recordMessagingStatus()`, which leaves `status` alone. `SocialInboxJob` runs it every five minutes. Tests: `SocialInboxServiceTest`, `SocialInboxJobTest`. Nothing arrives until 4.2 is done outside this repository.
- [ ] 4.2 Ask the OpenRegister lane to admit `GET /{version}/{id}/conversations` on the `meta-graph` broker provider; record Meta App Review status for `pages_messaging` and `instagram_manage_messages`
  - Verify: the OpenRegister issue is linked in the PR body; the Social accounts page shows "Messages: waiting on Meta" until the network answers

## 5. Routing rules

- [x] 5.1 Ship the flow "Route incoming tickets" as `x-openregister-flows` on `ticket` in the new fragment: trigger `object.created`, channel and empty-category filter, decision table (inputs channel, fromAddress, fromDomain, mailbox, clientSegment; outputs category, priority; hit policy first; `resultKey` `channelMetadata.routing`), object write update
  - Verify: after import the flow is listed on `/flows`, unowned; `POST /api/flow/validate` accepts it; PHPUnit on the fragment asserts the node types and keys
  - Built in `99-inbound-ticket-intake.json`: trigger, filter, decision table, object write, end, shipped disabled and unowned. OpenRegister refuses a table without rules, so the table ships one catch-all rule `everything-else` (no category, normal priority); the Routing rules page adds rules above it. PHPUnit `tests/Unit/Settings/InboundRoutingFlowTest.php`. The import and `POST /api/flow/validate` checks need a live instance and have not run yet.
- [x] 5.2 Add admin page `RoutingRules` (custom page, registered in `src/registry.js`) that reads the flow, edits its table as rows, adopts it on first save, then `PUT` and `publish`; show OpenRegister's refusal text
  - Verify: Vitest `tests/vitest/routingRules.spec.js` turns rows into a table and back; Playwright `tests/e2e/mail-routing.spec.ts` adds a rule "domain kadaster.nl sets category Registratie", feeds a mail from that domain and reads the category on the ticket in the Queue
  - Built as the admin settings section `src/views/settings/RoutingRulesSettings.vue` in `Settings.vue`, next to Ticket mailboxes, not as a router page: admin settings live on the Nextcloud admin page in this fleet, never in the vue-router. It reads the shipped flow from `GET /apps/openregister/api/flows?app=pipelinq`, edits its rules as rows (add, move up and down, remove; the catch-all stays last), refuses a row without a condition or a category, and on save adopts the flow when it is off, then `PUT /api/flows/{id}` and `POST /api/flows/{id}/publish`, showing OpenRegister's refusal text. The rows-to-table logic is `src/services/routingRules.js`. Tests: `tests/vitest/routingRules.spec.js`, `tests/vitest/routingRulesSettings.spec.js`. `tests/e2e/mail-routing.spec.ts` is written (it creates the ticket the way the mail intake writes one rather than sending real mail) but has not run against a live instance yet.
- [x] 5.3 Add `category` to the Queue page facets in `src/manifest.json`
  - Verify: `npm run check:manifest` exit 0; the Queue sidebar lists the category facet
  - The Queue's sidebar draws its facets from the schema's facetable properties, so 1.1 (`category` facetable) already puts it there; the page now also shows `category` as a column, so a row says which team it is for.

## 6. Text and docs

- [x] 6.1 English source strings and Dutch translations, sentence case, no em-dashes
  - Verify: `npm run test:l10n` exit 0
  - The Routing rules strings are in `en.json` and `nl.json` and their generated `.js`; `npm run test:l10n` and `npm run check:l10n-js` pass.
- [x] 6.2 Admin doc `docs/Features/inbound-to-queue.md`: name a ticket mailbox, switch on routing, write a rule, what the social half waits on
  - Verify: docs build exit 0
  - Written and listed in `docs/Features/README.md`; `npm run build` in `docs/` exits 0 with the page in the build.
