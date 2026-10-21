---
kind: code
---

## Why

Four components answered customers in the browser: CustomerReplySection wrote `customerMessage`, `portalAnswers` and the waiting status with a full PUT; TicketConversationSection merged the ticket's lists and patched in a legacy answer; MessagingConversationSection read messages per contact and resolved a client's contacts itself; SavedReplyPicker filtered, ordered and filled saved replies. PL-ABSTRACT (lane PL1) moves the rules to the server so the screens are nextcloud-vue `conversation` config.

## What changes

- `POST /api/tickets/{id}/answer` (`message`, `waitForCustomer`) saves the answer as the signed-in user.
- `GET /api/saved-replies?channel=&ticket=&contact=&client=` answers the active saved replies of a channel, the party's language first, placeholders filled, as template options.
- `ChannelMessageClientListener` stamps `clientId` (new optional property) on a message from its contact.
- Repair step `BackfillConversationFields`: legacy `customerMessage` into `portalAnswers`, and `clientId` on existing messages.
- The ticket's Customer contact section, the simple page's conversation, and the messages on contact and client pages are `conversation` config. TicketAnswerDialog, SendMessageModal and WriteEmailModal use the two endpoints. SendMessageModal reads the preflight itself and, opened for a client, asks which contact.

## Impact

Deleted: CustomerReplySection, TicketConversationSection, MessagingConversationSection, SavedReplyPicker, `utils/ticketConversation.js`, the reply helpers in `services/savedReplies.js`. Needs nextcloud-vue `feature/pl-abstract-pl1-conversation` (and `feature/pl-abstract-pl1-handoff` for the body-section `type`/`visibleWhen`).

The contact page shows the latest conversation's Open/Assigned/Closed badge (`statusBadge`); the client page shows none, since a conversation thread names a contact, not a client. The consent line is a facts card on the contact page; Send message shows consent per channel. On a closed WhatsApp window, Send again opens the composer on WhatsApp for a template (`onStatus`), as before.
