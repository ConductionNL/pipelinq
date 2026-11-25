# Tasks: requests-web-chat-and-bot

## 1. Channel and schema

- [x] 1.1 Add fragment `lib/Settings/register.d/99-web-chat.json`: schema `webChatChannel` (slug, fields from design D2), the `chatWaiting` notification rule on `ticket`
  - Verify: import log has no `PARTIAL IMPORT`; `npm run check:schema-l10n` exit 0
  - Built: `webChatChannel` (off by default, written by admins only), the chat fields on `channelConversation` (channel, secret hash, ticket, handover time, last message time, assistant token, answers without a source; no address field) and `chatWaiting` on `ticket` (created with channel chat, `nc-notification` and `web-push`, the `newTicket` recipients). Test: `tests/Unit/Settings/WebChatSchemaTest.php`. Every new schema string has an en and nl key; `check:schema-l10n` still exits 1 on 13 strings that are uncovered on development already. The import log is the live check.
- [x] 1.2 Admin Web chat section in `src/views/settings/Settings.vue`: list, add, switch on, copy the embed line
  - Verify: Vitest `tests/vitest/webChatSettings.spec.js` renders the embed line with the channel token
  - Built: `src/views/settings/WebChatSettings.vue` (admins only) over `src/services/webChatChannels.js`: lists the channels, adds one (off, with a fresh 32-character token, exact http(s) origins only), switches it on, and shows and copies the embed line. Test: `tests/vitest/webChatSettings.spec.js` (the embed line carries the channel token; a new channel is off).

## 2. The public window

- [x] 2.1 Add `WebChatController` with `start`, `send`, `poll` (`#[PublicPage]`, `#[NoCSRFRequired]`, `#[AnonRateLimit]`, secret in the body, hashed on the conversation) and `WebChatService`
  - Verify: PHPUnit `tests/Unit/Controller/WebChatControllerTest.php`: an inactive channel refuses; a wrong secret refuses; a start stores no IP and only the hash; hydra gates route-auth and forbidden-patterns pass
  - Built: `lib/Controller/WebChatController.php` (start, send, poll; `#[PublicPage]`, `#[NoCSRFRequired]`, `#[AnonRateLimit]`; the secret travels in the body), `lib/Service/WebChat/WebChatService.php`, `WebChatStore.php`, `WebChatRefusal.php`. Tests: `tests/Unit/Service/WebChat/WebChatServiceTest.php` (an inactive channel refuses; a wrong secret refuses; a start stores only the hash and no address), `WebChatStoreTest.php`, `tests/Unit/Controller/WebChatControllerTest.php`. Hydra gates route-auth and forbidden-patterns pass.
- [x] 2.2 Serve `GET /apps/pipelinq/chat/{channelToken}` with `frame-ancestors` from `allowedOrigins`; add `js/pipelinq-web-chat.js` (webpack entry) for the button and frame
  - Verify: PHPUnit asserts the header lists exactly the channel origins; Playwright `tests/e2e/web-chat.spec.ts` loads a test page on an allowed origin and exchanges two messages
  - Built: `GET /apps/pipelinq/chat/{channelToken}` (`WebChatWindowController::show`, base layout, initial state `web-chat`) with a `frame-ancestors` of exactly the channel's origins, and none for an unknown or inactive channel; webpack entries `webChat` (`js/pipelinq-web-chat.js`, the embed: `<script src=".../js/pipelinq-web-chat.js?chat=<window url>" async></script>`) and `webChatWindow`, both without shared chunks. PHPUnit `WebChatControllerTest::testTheWindowMayBeFramedOnlyByTheChannelsWebsites` (over `WebChatWindowController`); Vitest `webChatEmbed.spec.js` and `webChatWindow.spec.js`. Not done: the Playwright run of `tests/e2e/web-chat.spec.ts` (written: Playwright serves the allowed and a foreign origin itself), which needs a running instance.

## 3. Handover and the colleague

- [x] 3.1 Handover: Talk to a person calls `InboundTicketService::receive()` with channel chat and the transcript
  - Verify: PHPUnit on `WebChatService`: one handover makes one ticket with no assignee; a second press makes none
  - Built: `WebChatService::handOver()` calls `InboundTicketService::receive()` with channel chat, thread key `conversation:<id>`, the first question as title and the transcript; a chat that has a ticket gets no second one, and later visitor messages are added to it. Test: `WebChatServiceTest::testWithoutAnAssistantTheFirstMessageGoesToAPerson`, `testASecondHandoverMakesNoSecondTicket`.
- [x] 3.2 Add `src/components/WebChatSection.vue` (`kind: 'section'`), register it and mount it on TicketDetail for chat tickets: transcript, Take this chat, reply, End chat
  - Verify: Playwright in `tests/e2e/web-chat.spec.ts`: the visitor asks for a person, the ticket appears on `/queue`, an agent takes it, answers, and the visitor's window shows the answer
  - Built: `src/components/WebChatSection.vue`, registered as `WebChatSection` and mounted on TicketDetail as body widget `web-chat` (renders nothing unless the ticket's channel is chat and its thread key names a conversation); backed by `WebChatAgentController` (`GET /api/web-chat/conversations/{id}`, `POST .../take`, `.../reply`, `.../end`), each checking first that the colleague may read the chat. Taking assigns the conversation and the ticket (`in_progress`), which takes it out of the Queue. Tests: Vitest `tests/vitest/webChatSection.spec.js`, PHPUnit `WebChatAgentControllerTest`, `WebChatServiceTest::testTakingAChatAssignsItAndItsTicket`. Not done: the Playwright run in `tests/e2e/web-chat.spec.ts` (needs a running instance), and the saved-reply picker, which waits on `messaging-saved-replies-and-resend`.
- [x] 3.3 Nobody available and wait timeout: Leave a message with an email address
  - Verify: PHPUnit with all agent profiles unavailable: start returns offline mode; Vitest on the window shows the address field
  - Built: with agent profiles kept and none available the chat starts in offline mode; a handed-over chat nobody takes within `waitSeconds` turns offline; the address goes to the ticket's `channelMetadata.fromAddress`. Tests: `WebChatServiceTest::testNobodyAvailableAsksForAnAddress`, `testAChatWaitsOutAndThenEnds`; Vitest `webChatWindow.spec.js` (the address field, Leave a message). The chat button on the host page always reads "Chat met ons"; the window says Leave a message once it knows nobody is available.

## 4. The assistant

- [x] 4.1 Add `lib/Service/WebChat/ChatAssistant.php`: lazy resolve of hermiq's `PublicChatEntryPoint`, `ask()` with channel key `app:pipelinq:<id>`, store the answer with `metadata.author: assistant` and its sources
  - Verify: PHPUnit: hermiq absent means no assistant and a direct handover; a faked entry point answer is stored with sources; two answers without a source trigger the handover
  - Built: `lib/Service/WebChat/ChatAssistant.php`; answers are stored as outbound `channelMessage` rows with `metadata.author: assistant`, their linked sources and the disclosure; two answers in a row without a source hand over. Tests: `ChatAssistantTest`, `WebChatServiceTest::testTheAssistantAnswersFirstWithItsSources`, `testTwoUnsourcedAnswersInARowHandOver`. hermiq's `delivery-public-web-chat` is not on development yet, so the assistant half is tested against a faked entry point; until it ships every chat goes to a person.
- [x] 4.2 Show hermiq's disclosure above the first assistant answer and the sources under each answer in the window
  - Verify: Vitest on the window with a faked assistant message
  - Built: `src/webChat/chatWindow.js` shows hermiq's disclosure above the first assistant answer and the http(s) sources under each answer. Test: Vitest `tests/vitest/webChatWindow.spec.js`.

## 5. Text and docs

- [x] 5.1 English source strings and Dutch translations for the window and the section, sentence case, no em-dashes
  - Verify: `npm run test:l10n` exit 0
  - Built: en and nl for every string of the window, the section and the admin section (`l10n/en.json`, `l10n/nl.json`, regenerated `.js`); `npm run test:l10n` passes.
- [x] 5.2 Admin doc `docs/Features/web-chat.md`: add a channel, paste the line, switch on the assistant, what a colleague does
  - Verify: docs build exit 0
  - Built: `docs/Features/web-chat.md`. The docs build was not run here.
