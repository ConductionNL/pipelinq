<!--
SPDX-License-Identifier: EUPL-1.2
SPDX-FileCopyrightText: 2026 Conduction B.V.
-->

# Inbound messages to the Queue

**Status:** Mail, WhatsApp and SMS are implemented. Facebook and Instagram are built and wait on Meta (see below).

## Overview

A message from outside becomes a ticket in the Queue. A WhatsApp message, an SMS, a mail to a ticket mailbox, a Facebook page message or an Instagram message opens a request ticket with its channel, its sender and, where the sender is known, the client or contact. The ticket has status new and nobody assigned, so it is in the Queue at once.

A follow-up on the same WhatsApp or SMS conversation, the same mail thread or the same social conversation is added to the open ticket instead of opening a new one. A follow-up on a ticket that is awaiting the customer moves it back to in progress. When the earlier ticket is closed, a new message opens a new ticket. An opt-in or opt-out keyword such as STOP opens no ticket.

This page is for administrators. It covers the three things to set up: the ticket mailboxes, routing, and the rules.

## Name a ticket mailbox

A ticket mailbox is a Nextcloud Mail account whose inbox opens tickets.

1. Add the team mailbox (for example `service@gemeente.nl`) as an account in the Nextcloud Mail app, for example on a functional user, and switch on background sync for it. Pipelinq reads what the Mail app has synced; a mailbox the Mail app does not sync brings in nothing.
2. Open **Administration settings > Pipelinq** and go to **Ticket mailboxes**.
3. Tick the account and choose **Save ticket mailboxes**.

Every five minutes Pipelinq reads the new mail in the inbox of each ticket mailbox. It matches the sender to a client or contact by address, and otherwise by domain, and links the mail to its ticket. Mail sent from one of the ticket mailboxes' own addresses opens no ticket, so an answer your team sends does not come back as a new question.

Only administrators can change the list.

## Switch on routing

Without routing, every inbound ticket lands in the Queue with no category, and agents pick it up from there. Routing gives a new inbound ticket a category and a priority from rules you write, so a team can open the Queue on its own category with the category filter.

Routing is a flow in OpenRegister named **Route incoming tickets**. Pipelinq ships it switched off. Saving your first rules on the **Routing rules** section switches it on: the flow is then yours, and it runs as you.

## Write a rule

Open **Administration settings > Pipelinq** and go to **Routing rules**.

1. Choose **Add rule**.
2. Fill in what the rule looks at. Leave a field empty to match anything:
   - **Channel**: email, WhatsApp, SMS, Facebook or Instagram.
   - **Sender address**: the full address, for example `info@bakkerij.nl`.
   - **Sender domain**: for example `kadaster.nl`.
   - **Mailbox or account**: the ticket mailbox or the social account it came in on.
   - **Client segment**: the segment of the matched client.
3. Fill in what the rule sets: **Sets category** and **Sets priority**.
4. Choose **Save routing rules** (the first time: **Save rules and switch routing on**).

A field matches its value exactly. Sender address and sender domain are compared in lower case.

The rules are tried from top to bottom, and the first one that matches is used. Use **Move up** and **Move down** to put a specific rule above a general one. Everything that no rule matches gets no category and normal priority.

A rule needs at least one field to look at, because a rule that matches everything would hide every rule below it, and it needs a category. The page says so before it saves. If OpenRegister refuses the rules, the page shows its reason.

Each routed ticket records which rule matched, under `channelMetadata.routing`, and OpenRegister keeps the run of the flow, so you can see why a ticket went where it went.

Example: the rule "sender domain `kadaster.nl` sets category Registratie and priority high" gives every new mail from someone at Kadaster the category Registratie, and the Registratie team finds it by picking that category in the Queue.

## What the social half waits on

Facebook and Instagram messages are read every five minutes from each connected Facebook page and Instagram business account, through OpenRegister's credential broker. Two things outside Pipelinq have to happen first:

- Meta has to approve the permissions `pages_messaging` and `instagram_manage_messages` for the app the accounts are connected through.
- The broker's Meta provider has to allow the conversations read (`GET /{version}/{id}/conversations`).

Until then the read is refused. The account keeps a messaging status of its own, apart from its posting status, with the reason, so an account that posts fine is not marked as broken because its messages cannot be read yet.

## Related files

- Spec: `openspec/changes/requests-inbound-to-queue/specs/inbound-ticket-intake/spec.md`
- Design: `openspec/changes/requests-inbound-to-queue/design.md`
- Tasks: `openspec/changes/requests-inbound-to-queue/tasks.md`
