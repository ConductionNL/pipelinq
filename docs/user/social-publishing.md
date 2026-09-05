<!--
SPDX-License-Identifier: EUPL-1.2
SPDX-FileCopyrightText: 2026 Conduction B.V.
-->

# Social publishing

Post to your company pages and your colleagues' own profiles from one place, with an approval step before anything leaves, and see the next morning what it did.

Everything here lives in the **Marketing** group of the navigation, under **Social accounts**, **Social posts** and **Social performance**.

## Connect an account

Open **Social accounts**. Each account shows its network, its status and, where something is missing, what is missing.

Press **Connect**. Pipelinq hands you to the network's own consent screen, you approve there, and the network sends you back. What comes back to Pipelinq is a reference, not a token: the grant itself is kept by OpenRegister's credential broker in keepiq, and Pipelinq cannot read it. That is why a leak in Pipelinq cannot post as you.

A personal account belongs to the person who connected it. Only they, or an administrator, can publish as them, ask them to share, or end the connection.

When a connection ends, whether because a token expired or somebody revoked it on the network's side, the account turns to **Reconnect needed** and its owner gets a notification. Scheduled posts to that account stop rather than failing over and over. Press **Reconnect** and the same connection is re-authorised in place, so every post that names it keeps working.

**Revoke** ends a connection. The account stays in the list and the posts that already went out still name it; it simply cannot be chosen for a new post.

## What each network needs

Two networks work as soon as you connect them, because neither needs anyone's approval.

| Network | What it needs |
| --- | --- |
| Mastodon | Nothing. An application is registered at your own server when you connect. |
| Bluesky | Nothing from you. The broker still ships Bluesky as a preview, so the network may refuse a post until that work is finished upstream. |
| LinkedIn | A developer application filed by Conduction. Posting from a personal profile arrives first; company page posting waits on LinkedIn's Community Management approval. |
| X | A developer account with billing. X charges for every post and every read, so your organisation has a spend budget with a hard stop. |
| Facebook page, Instagram business | Meta App Review with Business Verification. |
| Threads | Nothing is filed yet, so a Threads account cannot be connected. The page says so rather than offering a button that fails. |

A network with nothing filed never fails quietly. The account says what is missing, and a post to it is recorded as failed with that reason rather than disappearing.

## Write a post

Open **Social posts** and press **New post**.

Write the body once. It applies to every network the post goes to. Under **Per network** you can write a variant for one network without retyping the rest: a variant that carries only text still uses the post's own link and media. Each network shows how much of its limit you have used, and a variant that does not fit cannot be submitted.

Pick the accounts, add a link and a moment to send, and press **Submit for approval**.

## Approve and publish

A post never goes out on its own. Someone opens it and presses **Approve**, and the record shows who approved it and when. **Reject** sends it back to the writer with a note.

An approved post goes out at its moment. Every account it names gets its own result, so a post to five accounts that reached three shows three publications and two failures, each with the reason it failed. Two kinds of failure can be tried again and offer a **Retry**; the rest name what to do instead, such as reconnecting an account.

A post an agent drafted is marked as written by an agent wherever you read it, and still needs a person to approve it.

## Share a post yourself

Personal Facebook and Instagram accounts cannot be posted to by any application, at any level of approval. Pipelinq does not pretend otherwise.

For those accounts you get a Nextcloud notification with the text already prepared. Open the post, press **Share this myself**, copy the text, open the network and post it, then press **I posted this**. The share is recorded against your account, so it counts in the numbers like any other publication.

Only the owner of the account can confirm a share.

## See what it did

**Social performance** ranks what you published by engagement rate: likes, comments and shares together, against the account's follower count. That is the comparison worth making. A company page with 900 followers and a colleague with 4,000 are not comparable on raw likes.

The numbers are pulled once a day, and every network's own reporting is reduced to the same five: views, likes, comments, shares and clicks. A number a network does not report stays at zero rather than being guessed. An account with no follower count recorded shows no rate rather than a misleading one.
