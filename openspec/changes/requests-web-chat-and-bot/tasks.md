# Tasks: requests-web-chat-and-bot

## 1. Channel and schema

- [ ] 1.1 Add fragment `lib/Settings/register.d/99-web-chat.json`: schema `webChatChannel` (slug, fields from design D2), the `chatWaiting` notification rule on `ticket`
  - Verify: import log has no `PARTIAL IMPORT`; `npm run check:schema-l10n` exit 0
- [ ] 1.2 Admin Web chat section in `src/views/settings/Settings.vue`: list, add, switch on, copy the embed line
  - Verify: Vitest `tests/vitest/webChatSettings.spec.js` renders the embed line with the channel token

## 2. The public window

- [ ] 2.1 Add `WebChatController` with `start`, `send`, `poll` (`#[PublicPage]`, `#[NoCSRFRequired]`, `#[AnonRateLimit]`, secret in the body, hashed on the conversation) and `WebChatService`
  - Verify: PHPUnit `tests/Unit/Controller/WebChatControllerTest.php`: an inactive channel refuses; a wrong secret refuses; a start stores no IP and only the hash; hydra gates route-auth and forbidden-patterns pass
- [ ] 2.2 Serve `GET /apps/pipelinq/chat/{channelToken}` with `frame-ancestors` from `allowedOrigins`; add `js/pipelinq-web-chat.js` (webpack entry) for the button and frame
  - Verify: PHPUnit asserts the header lists exactly the channel origins; Playwright `tests/e2e/web-chat.spec.ts` loads a test page on an allowed origin and exchanges two messages

## 3. Handover and the colleague

- [ ] 3.1 Handover: Talk to a person calls `InboundTicketService::receive()` with channel chat and the transcript
  - Verify: PHPUnit on `WebChatService`: one handover makes one ticket with no assignee; a second press makes none
- [ ] 3.2 Add `src/components/WebChatSection.vue` (`kind: 'section'`), register it and mount it on TicketDetail for chat tickets: transcript, Take this chat, reply, End chat
  - Verify: Playwright in `tests/e2e/web-chat.spec.ts`: the visitor asks for a person, the ticket appears on `/queue`, an agent takes it, answers, and the visitor's window shows the answer
- [ ] 3.3 Nobody available and wait timeout: Leave a message with an email address
  - Verify: PHPUnit with all agent profiles unavailable: start returns offline mode; Vitest on the window shows the address field

## 4. The assistant

- [ ] 4.1 Add `lib/Service/WebChat/ChatAssistant.php`: lazy resolve of hermiq's `PublicChatEntryPoint`, `ask()` with channel key `app:pipelinq:<id>`, store the answer with `metadata.author: assistant` and its sources
  - Verify: PHPUnit: hermiq absent means no assistant and a direct handover; a faked entry point answer is stored with sources; two answers without a source trigger the handover
- [ ] 4.2 Show hermiq's disclosure above the first assistant answer and the sources under each answer in the window
  - Verify: Vitest on the window with a faked assistant message

## 5. Text and docs

- [ ] 5.1 English source strings and Dutch translations for the window and the section, sentence case, no em-dashes
  - Verify: `npm run test:l10n` exit 0
- [ ] 5.2 Admin doc `docs/Features/web-chat.md`: add a channel, paste the line, switch on the assistant, what a colleague does
  - Verify: docs build exit 0
