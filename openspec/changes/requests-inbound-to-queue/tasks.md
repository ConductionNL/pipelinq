# Tasks: requests-inbound-to-queue

## 1. The intake

- [ ] 1.1 Add fragment `lib/Settings/register.d/99-inbound-ticket-intake.json`: ticket property `inboundThreadKey` (string), `category` set `facetable: true`
  - Verify: import log has no `PARTIAL IMPORT`; `npm run check:schema-l10n` exit 0
- [ ] 1.2 Add `lib/Service/InboundTicketService.php` with `receive()`: find an open ticket by `inboundThreadKey`, add the message or create a request ticket (`status: new`, no assignee, `priority: normal`, `channel`, `client`, `contact`, `channelMetadata`); move `awaiting_customer` to `in_progress` on a follow-up
  - Verify: PHPUnit `tests/Unit/Service/InboundTicketServiceTest.php`: new thread creates one ticket with no assignee; same thread twice creates one ticket; a follow-up on an awaiting ticket moves it to in progress; a closed ticket on the same thread gets a new ticket

## 2. WhatsApp and SMS

- [ ] 2.1 Call `receive()` from `WhatsAppAdapter::handleInboundWebhook()` and `SmsAdapter::handleInboundWebhook()` after the message is stored; skip the STOP and opt-in keywords; catch and log an intake failure
  - Verify: PHPUnit on both adapters: a normal inbound calls the intake once with `conversation:<id>`; a STOP message does not; an intake exception still returns `received`
- [ ] 2.2 End to end: an inbound WhatsApp from the mock provider shows in the Queue
  - Verify: Playwright `tests/e2e/inbound-to-queue.spec.ts` posts a signed mock webhook and finds the ticket on `/queue` with channel whatsapp

## 3. Ticket mailboxes

- [ ] 3.1 Admin endpoint `GET /api/admin/mail-intake/accounts` listing Mail accounts (id, address, owner) and a Ticket mailboxes section in `src/views/settings/Settings.vue` that stores `inboundMail.accounts`
  - Verify: PHPUnit controller test: non-admin gets 403; hydra gates route-auth and semantic-auth pass
- [ ] 3.2 Add `lib/BackgroundJob/MailIntakeJob.php`: per account, INBOX messages since the cursor, sender matching, `receive()` with `mail:<account>:<threadRootId>`, email leaf link to the ticket, skip mail from the configured addresses
  - Verify: PHPUnit with a fake Mail table: two messages in one thread make one ticket; a message from the mailbox's own address makes none; the cursor advances; the Mail message is linked to the ticket

## 4. Facebook and Instagram

- [ ] 4.1 Add the conversations read call to `FacebookPageAdapter` and `InstagramBusinessAdapter` and `lib/BackgroundJob/SocialInboxJob.php` with a per-account cursor; store a broker or network refusal in a new `socialAccount.messagingStatus` property
  - Verify: PHPUnit with a faked `SocialBrokerGateway`: a new visitor message calls `receive()` with `meta:<conversation id>`; a page message is ignored; a `rejected_by_network` lands in `messagingStatus` and leaves `status` alone
- [ ] 4.2 Ask the OpenRegister lane to admit `GET /{version}/{id}/conversations` on the `meta-graph` broker provider; record Meta App Review status for `pages_messaging` and `instagram_manage_messages`
  - Verify: the OpenRegister issue is linked in the PR body; the Social accounts page shows "Messages: waiting on Meta" until the network answers

## 5. Routing rules

- [ ] 5.1 Ship the flow "Route incoming tickets" as `x-openregister-flows` on `ticket` in the new fragment: trigger `object.created`, channel and empty-category filter, decision table (inputs channel, fromAddress, fromDomain, mailbox, clientSegment; outputs category, priority; hit policy first; `resultKey` `channelMetadata.routing`), object write update
  - Verify: after import the flow is listed on `/flows`, unowned; `POST /api/flow/validate` accepts it; PHPUnit on the fragment asserts the node types and keys
- [ ] 5.2 Add admin page `RoutingRules` (custom page, registered in `src/registry.js`) that reads the flow, edits its table as rows, adopts it on first save, then `PUT` and `publish`; show OpenRegister's refusal text
  - Verify: Vitest `tests/vitest/routingRules.spec.js` turns rows into a table and back; Playwright `tests/e2e/mail-routing.spec.ts` adds a rule "domain kadaster.nl sets category Registratie", feeds a mail from that domain and reads the category on the ticket in the Queue
- [ ] 5.3 Add `category` to the Queue page facets in `src/manifest.json`
  - Verify: `npm run check:manifest` exit 0; the Queue sidebar lists the category facet

## 6. Text and docs

- [ ] 6.1 English source strings and Dutch translations, sentence case, no em-dashes
  - Verify: `npm run test:l10n` exit 0
- [ ] 6.2 Admin doc `docs/Features/inbound-to-queue.md`: name a ticket mailbox, switch on routing, write a rule, what the social half waits on
  - Verify: docs build exit 0
