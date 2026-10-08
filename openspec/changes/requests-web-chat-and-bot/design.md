# Design: requests-web-chat-and-bot

## Context (read at pipelinq development cfe0a0a51, hermiq development 5ac16d315, portaliq development db4a6cfce, nextcloud/spreed main 9b3cce604)

- **No chat today.** Nothing in `lib/` or `src/` serves a visitor chat. The
  only anonymous web intake is `EnquiryIntakeService::submit()` (:134) behind
  `EnquiryController` (`POST /api/enquiry`, `appinfo/routes.php:197`), which is
  `#[PublicPage]` with `#[AnonRateLimit(limit: 20, period: 60)]` (:89-91) and
  answers CORS by reflecting the Origin without credentials (`cors()`, :146).
  `lib/Service/NaviService.php` is an analytics assistant for colleagues.
- **The channel model already names chat.** In
  `lib/Settings/register.d/80-whatsapp-sms-channel.json`, `channelConversation`
  and `channelMessage` both accept `channel: chat`, and neither requires a
  `contactId`, so an anonymous visitor fits without a schema change to either.
- **pipelinq has no Talk integration.** The lead's note says pipelinq already
  integrates Talk; a search of `lib/` and `src/` finds only
  `lib/Repair/SeedWeeklyReviewAgentTemplate.php:191` (`'deliver' => 'talk'`),
  the schedule of a hermiq agent. Talk itself offers bots that run inside a
  Nextcloud app (`OCA\Talk\Events\BotInvokeEvent`, spreed `docs/bots.md:456-464`),
  but a guest's room session lives in the Nextcloud PHP session
  (`lib/TalkSession.php:14-45` takes `ISession`), so a guest chat depends on
  the Nextcloud session cookie.
- **hermiq's public chat.** hermiq's open change `delivery-public-web-chat`
  (design D2) adds `PublicChatEntryPoint::ask(channelKey, question, locale,
  sourceScope, conversationToken)` for sibling apps, returning an answer, its
  sources, a conversation token and a disclosure text; the call carries no
  user, the agent has no tools, and retrieval is limited to content
  OpenRegister serves anonymously within the declared scope (D4). A sibling
  registers its channel by key, as portaliq does with `app:portaliq:<slug>`.
  It has no handover to a person. It is not on development as code yet.
- **Lazy hermiq resolve.** `lib/Service/Competitor/RelevanceScorer.php`
  resolves a hermiq class by name from the container and degrades to no
  answer when hermiq is absent (`provider()`, :231-245).
- **portaliq.** Its open change `search-assistant-from-public-content` puts an
  AI-only "Ask a question" widget on the public site, with no handover.
  portaliq serves an embeddable intake form with a per-form
  `frame-ancestors` (portaliq matrix `int-embedded-form`,
  `PortalEmbedController::frame()`).
- **Queue, tickets and notification.** A ticket with no assignee and an open
  status is in the Queue (`src/manifest.json:4297`). The `ticket` schema's
  `newTicket` rule notifies the `sales` group by `nc-notification`
  (`99-unify-ticket-supertype.json:149-170`). The intake that turns an inbound
  message into a ticket is `InboundTicketService::receive()` from the
  sibling change `requests-inbound-to-queue`.
- **Availability.** `RoutingService::filterByAvailability()` (:409) keeps the
  agent profiles whose `isAvailable` is not false.

## Decisions

### D1. pipelinq owns the chat channel, and the transport is its own

The chat is one more channel next to WhatsApp and SMS: a `channelConversation`
with `channel: chat` and `channelMessage` rows. Talk was considered and not
chosen for the visitor side. A visitor on the organisation's own website would
join a Talk room from inside a frame of another site, and a Talk guest session
needs the Nextcloud session cookie, which browsers block in that position.
Keeping the chat in pipelinq also keeps the conversation and the ticket one
record for the agent. Talk remains the place where colleagues talk among
themselves.

### D2. A web chat channel is a declaration

New schema `webChatChannel`: `name`, `allowedOrigins` (exact origins),
`greeting`, `offlineText`, `assistantEnabled` (default false),
`waitSeconds` (default 180), `showAgentFirstName` (default false),
`active` (default false) and `channelToken` (random, not secret, part of the
embed line). A Web chat section in the admin settings lists channels and shows
the embed line.

### D3. The window is a pipelinq page in a frame; no cookie

`GET /apps/pipelinq/chat/{channelToken}` serves the chat window with a
`frame-ancestors` header of the channel's `allowedOrigins` only.
`js/pipelinq-web-chat.js` adds a chat button to the host page and opens the
window in a frame. The window calls three public routes on the same origin:
start (creates the conversation and returns its id and a visitor secret),
send, and poll. All three are `#[PublicPage]`, `#[NoCSRFRequired]` and
`#[AnonRateLimit]`, carry the secret in the body, and never read a session.
The conversation stores only a hash of the secret. The secret lives in the
frame's `sessionStorage`, so closing the tab ends the chat. No IP address is
stored.

### D4. The assistant answers first, when it may

`ChatAssistant` resolves `OCA\Hermiq\PublicChat\PublicChatEntryPoint` from the
container the way `RelevanceScorer` resolves its provider. When the channel
has `assistantEnabled`, hermiq is installed and the entry point exists, a
visitor message goes to `ask()` with channel key `app:pipelinq:<channel id>`
and the hermiq conversation token of this chat. The answer is stored as an
outbound `channelMessage` with `metadata.author: assistant` and its sources,
and the window shows hermiq's disclosure text above the first answer. In any
other case there is no assistant, and the first visitor message hands over at
once. pipelinq calls no model itself.

### D5. When the chat goes to a person

The chat hands over when the visitor presses Talk to a person, or when the
assistant answers twice in a row without a source (its way of saying it does
not know, hermiq D4). Handover calls `InboundTicketService::receive()` with
`channel: chat`, thread key `conversation:<id>`, the first question as title
and the transcript so far as text. The ticket is in the Queue at once. A new
rule `chatWaiting` on the `ticket` schema (trigger created, channel chat)
sends `nc-notification` and `web-push` to the same recipients as
`newTicket`, because a chat waits in seconds, not hours. From handover on, the
assistant is not asked again in this chat.

### D6. The colleague answers on the ticket

`WebChatSection` (`kind: 'section'`) mounts on TicketDetail when the ticket's
channel is chat. It shows the transcript with who said what (visitor,
assistant, colleague), a Take this chat button that assigns the ticket to the
current user (which takes it out of the Queue), a reply box with the saved
reply picker for channel `chat` from `messaging-saved-replies-and-resend` when
that change has landed, and End chat. A reply is an outbound `channelMessage`
with `metadata.author: agent`. The window shows it as from "Colleague", or
the colleague's first name when the channel says so. The section and the
window both poll every three seconds while the chat is open.

### D7. When nobody picks up

If no agent profile is available (`filterByAvailability()`), the chat button
reads Leave a message and the window asks for a question and an email
address. If a colleague does not take a handed-over chat within
`waitSeconds`, the window says so and asks for an email address. Either way
the ticket stays in the Queue with the address in `channelMetadata`, and the
colleague answers by mail.

### D8. A chat ends

End chat by the colleague, the visitor closing the window, or thirty minutes
without a message closes the `channelConversation`. The ticket stays as it
is; closing the chat does not resolve the ticket.

## Risks

- Abuse from the public. The routes are rate limited per address, a channel
  runs only on its declared origins, and a channel is off by default.
- A visitor types personal details into a chat the assistant reads. hermiq's
  guardrail filters run on every assistant turn; the window says not to type a
  citizen service number.
- The assistant half waits on hermiq. Until `delivery-public-web-chat` ships,
  every chat goes straight to a person, which is a working live chat on its own.
- Polling at three seconds per open chat is fine for a service desk and heavy
  for a campaign spike. The poll route answers from the conversation's last
  message time only, and a later change can move both sides to push.
