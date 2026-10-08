---
kind: code
depends_on: [requests-inbound-to-queue]
---

# Proposal: requests-web-chat-and-bot

## Summary

A visitor on the organisation's website can chat with the team before they
ever fill in a form. When the organisation switches it on, an assistant
answers the common questions first, from content the organisation has
published. When it gets stuck, or the visitor asks for a person, the chat
becomes a ticket in the Queue and a colleague carries on in the same window.

## Motivation

Two rows of the pipelinq capability matrix (`openspec/parity/capabilities.json`),
both decided `build` by the OpenSpec pass of 2026-09-27. They share one
window and one conversation: the bot is the first voice in the chat, and the
colleague is the second.

**`chat-on-website`**, "Chat with a visitor on your website before they ever
fill in a form". Rated no, built.state none. Matrix evidence: "no live chat
found anywhere in lib/ or src/"; provider note: "the only web intake is the
form at lib/Service/EnquiryIntakeService.php:134". Note: "HubSpot's free tier
includes live chat and a chatbot. This is a real gap, not a rating we
missed." No demand row. Two competitors rate it yes:

- hubspot-crm: https://knowledge.hubspot.com/chatflows/create-a-live-chat,
  "The live chat will appear as a widget on your website pages, where
  visitors can start a real-time conversation with someone on your team".
- odoo-crm: source read, `addons/website_livechat/__manifest__.py:5`
  "'summary': 'Chat with your website visitors'" puts the live chat widget on
  the website; operators answer in Discuss, and `crm_livechat` turns a chat
  into a lead.

pipedrive rates it partial (live chat is part of the LeadBooster add-on),
espocrm and kiss no.

**`cm-chatbot`**, "Let a chatbot answer common questions and hand the
conversation to a colleague when it gets stuck". Rated no, built.state none.
Matrix evidence: "no chatbot in lib/ or src/portal/; lib/Service/NaviService.php
is an analytics assistant for colleagues ('Conversational analytics
orchestrator'), not a client-facing bot with handover". Demand: tender
https://www.tenderned.nl/aankondigingen/overzicht/418890, with
intelligence-db requirements#204142 (Gemeente Noordwijk, 2026-04-08:
"chatbot en voicebot, direct doorschakelen naar een CX gebruiker") and
requirements#4339 (Veiligheidsregio Utrecht, 2025-03-17: "chatbot gevoed
vanuit gevalideerde documenten"). Also seen in https://www.hubspot.com/spotlight.
Two competitors rate it yes:

- hubspot-crm: https://knowledge.hubspot.com/customer-agent/deploy-the-customer-agent-to-channels,
  "you can assign it to Facebook, WhatsApp, calling (BETA), live chat, form, or
  email channels"; it "hands off with full context attached" when a question
  needs a human.
- odoo-crm: source read, `addons/im_livechat/models/chatbot_script_step.py:23-28`
  chatbot steps include questions, answer choices and "Forward to Operator"
  (:354 hands the conversation to an available operator).

pipedrive rates it partial (a scripted playbook, not an answering bot),
espocrm and kiss no.

## What changes

- An administrator adds a web chat channel: a name, the websites it may run
  on, a greeting and whether the assistant answers first. pipelinq shows the
  one line to paste into the website.
- The website shows a chat button. The chat opens in a small window that
  pipelinq serves. The visitor needs no account and no cookie.
- With the assistant on and hermiq installed, the assistant answers first from
  the organisation's public content, shows its sources and says it is an AI.
- Talk to a person, or two answers the assistant cannot source, hands the chat
  over. The chat becomes a ticket in the Queue with the whole conversation on
  it, and the team gets a notification.
- A colleague takes the chat on the ticket and answers in the same window the
  visitor is looking at.
- When nobody is available, the window asks for an email address instead, and
  the ticket waits in the Queue for an answer by mail.

## Out of scope

- A voice bot. The Noordwijk requirement names one; this change is text only.
- An assistant that acts for the visitor, such as filing a request. That is
  hermiq's open change `a-conversational-intake-that-files-for-the-citizen`.
- Starting a chat with a visitor who did not ask (Odoo's proactive chat
  request).
- The chat inside the signed-in portal. The portal assistant over public
  content is portaliq's open change `search-assistant-from-public-content`.

## Impact

- New schema `webChatChannel` and a notification rule on `ticket`, in a new
  fragment `lib/Settings/register.d/99-web-chat.json`.
- New public controller `WebChatController` (start, send, poll) and a page
  route that serves the chat window with a per-channel `frame-ancestors`.
- New `lib/Service/WebChat/WebChatService.php` and
  `lib/Service/WebChat/ChatAssistant.php` (the lazy hermiq resolve).
- New section `src/components/WebChatSection.vue` on TicketDetail, an admin
  Web chat settings section, and a small embed script.
- Uses `InboundTicketService::receive()` from `requests-inbound-to-queue` for
  the handover.
- Depends on hermiq's open change `delivery-public-web-chat` for the
  assistant. Without it the chat goes straight to a person.
