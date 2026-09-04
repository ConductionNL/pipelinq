<!--
SPDX-License-Identifier: EUPL-1.2
SPDX-FileCopyrightText: 2026 Conduction B.V.
-->

# Marketing

Reach the people in your CRM with mailings and social posts, and know what worked. Marketing lives in the **Marketing** group of the Pipelinq navigation and works on the clients, contacts and leads you already have. You keep your own subscriber data, your own unsubscribe list and your own click data, whichever mail server or network carries the message.

This page describes what you can do today and what is planned. The technical design is on the [marketing architecture](../Technical/marketing-architecture.md) page. The step-by-step guide for sending a blast is the [marketing blasts user guide](../user/marketing-blasts.md).

## What you can do today

**Segments.** Define an audience as rules over your contacts and clients, with AND and OR groups. A segment is live: it is evaluated again at every send, so new matching contacts are included and deleted ones drop out.

**Templates.** Write an email or SMS body with variables. An email template is only saved when it carries the unsubscribe link and your postal address.

**Articles.** Write a title, a summary, a hero image and a markdown body once. Embed it in a template's `{{articles}}` block. An article moves through a draft, review, published and archived lifecycle, and each article page shows where it has been used. See the [articles user guide](../user/articles.md).

**Blasts.** Pick a segment, a template, a channel and a moment. Pipelinq checks consent for every contact before it sends and shows you who is missing. Split the audience in two to test a variant. Watch the send progress live and cancel it if you must.

**Tracking and results.** Clicks are recorded by Pipelinq itself, with signed links that carry no personal data. The performance page lists open and click rates per blast, tells you whether an A/B difference is statistically meaningful, and sums the deal value attributed to each blast.

**Consent.** Every contact has a consent record per channel. An unsubscribe or a hard bounce withdraws it within minutes and skips any queued delivery.

**SMS and WhatsApp.** Send one-to-one messages from a client or contact page, with per-channel consent, approved WhatsApp templates and a spend budget. See [outbound messaging](outbound-messaging.md).

## What is planned

The programme runs in six phases, ordered by value. Each phase is a set of openspec changes; the [architecture page](../Technical/marketing-architecture.md#phases) lists them.

| Phase | You will be able to | Status |
| --- | --- | --- |
| 1 · Lists and mailings | Run mailing lists people subscribe to, with double opt-in and a preference centre. Compose a newsletter from articles. Send through your own Nextcloud mail server, your own Mail account, or a bulk provider. Record work, private and mobile numbers, several email addresses and social handles per contact. | Planned |
| 2 · Content hub and AI | Write an article once and reuse it in a newsletter, a social post and a portaliq page. Ask hermiq for a draft, a shorter subject line or a LinkedIn variant, in your organisation's voice. Every AI draft is marked and needs your approval before anything leaves. | Planned |
| 3 · Social publishing | Connect your company pages on LinkedIn, Mastodon, Bluesky, X, Facebook, Instagram and Threads, and let colleagues connect their own profiles. Schedule and approve posts in one calendar. Where a network does not allow posting on someone's behalf, they receive the prepared post and share it themselves. See which posts perform. | Planned |
| 4 · Campaigns and attribution | Group a mailing, posts and a landing page into one campaign. Create the landing page with a form in portaliq from Pipelinq. A form submission becomes a lead that remembers which mailing and which post brought it in. See attributed revenue per campaign, closed on paid invoices in shillinq. | Planned |
| 5 · Search and competitors | Connect Google Search Console and Matomo. See which keywords you rank for, which are within reach, which pages compete with each other, and which terms to use more or less. Follow competitors through their feeds, sitemaps and public social timelines. Check which clients follow you and whom you follow. | Planned |
| 6 · Integrated campaigns | Build audiences from bookkeeping: lapsed customers, top-tier customers, buyers of one product without another. Run journeys that start from a stage change or a renewal window. Get a weekly review from hermiq with what moved and what to try. | Planned |

## How it stays yours

- **Unsubscribes and clicks are always recorded by Pipelinq**, even when Amazon SES, Brevo or another provider sends the mail. A provider's unsubscribe is mirrored back, never relied on.
- **Double opt-in is the default** for anyone who subscribes through a form. Existing customers can be added under the legal soft opt-in, and the record says so.
- **Click tracking is on and the open pixel is off** by default. Both are switches per organisation.
- **Tokens and keys never sit on a Pipelinq object.** Connected accounts and provider credentials live in keepiq behind OpenRegister's credential broker.
- **AI never sends or publishes.** Hermiq drafts and analyses. A person clicks Send and Publish, and the record shows who.

## What the networks allow

Some limits come from the networks themselves and no tool can work around them.

- Personal Facebook and Instagram accounts cannot be posted to by any application. Colleagues on those networks receive the prepared post and share it from their own app.
- LinkedIn company page posting requires LinkedIn's partner approval, which takes months. Posting from a personal LinkedIn profile is available at once.
- X charges per post and per read. A spend budget per organisation keeps this in check.
- WhatsApp is opt-in messaging with approved templates, not a feed. Snapchat has no publishing interface.
- LinkedIn and Meta do not offer legitimate access to other organisations' posts. Competitor monitoring uses feeds, sitemaps, page changes and the open fediverse.

## Standards

| Standard | Relevance |
| --- | --- |
| GEMMA Sociale mediacomponent | Social publishing and engagement, phase 3 |
| GEMMA Mediamonitor- en webcarecomponent | Competitor and mention monitoring, inbox, phase 3 and 5 |
| TEC CRM: Marketing Automation (2) | Lists, campaigns, segmentation, attribution |
| Telecommunicatiewet article 11.7 and AVG | Opt-in, soft opt-in, consent proof, erasure |
| RFC 8058 | One-click unsubscribe header on every mailing |
| Gmail and Yahoo bulk sender requirements | SPF, DKIM, DMARC, one-click unsubscribe, complaint rate |
