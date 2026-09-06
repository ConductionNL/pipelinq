<!--
SPDX-License-Identifier: EUPL-1.2
SPDX-FileCopyrightText: 2026 Conduction B.V.
-->

# Mailing lists and double opt-in

A segment selects people you already hold. A mailing list holds people who
asked to hear from you. This guide covers both sides of that list: yours, as
the marketer who runs it, and the subscriber's, who joins and leaves it
without ever having a Nextcloud account.

## At a glance

| You want to ... | Open ... |
| --- | --- |
| Create or edit a list | **Marketing > Lists** |
| See who is on a list | **Marketing > Lists > (your list)** |
| See the lists one person is on | **Contacts > (the person) > Subscriptions** |
| Publish a signup form | **Settings > Mailing list signup form** |
| Mail a list | **Marketing > Blasts > New blast**, then pick the list |

## The four states a membership can be in

Every membership is one row with one state. Only one of them may receive a
mailing.

| State | Chip | May receive a mailing |
| --- | --- | --- |
| `pending` | Awaiting confirmation | No |
| `confirmed` | Subscribed | Yes |
| `unsubscribed` | Unsubscribed | No |
| `bounced` | Bounced | No |

A signup starts as `pending`. It never starts as `confirmed`. That is what
double opt-in means, and it is why a fresh list looks emptier than the number
of signups suggests.

## 1. Create a list

1. Go to **Marketing > Lists** and click **New list**.
2. Give it a name your subscribers will recognise. It appears on the
   confirmation page and in the preference centre.
3. Fill in the sender name, the sender address and the reply-to address. Each
   mailing to this list uses them, so a list can have a different voice from
   the rest of your marketing.
4. Fill in the postal address for the footer. Pipelinq refuses to store a list
   without one, because a bulk mailing without a postal address is not lawful.
5. Leave the opt-in mode on **double** unless you have read section 4.
6. Turn on **public signup** if you want to publish a form. Leave it off and
   the public subscribe endpoint answers as if the list did not exist.

## 2. Publish a signup form

Open **Settings > Mailing list signup form** and pick your list. Pipelinq
writes the form for you. Paste it into any page.

The form posts straight at Pipelinq. It needs no script, no library and no
CORS configuration. It carries one hidden field that a person never sees and a
form-filling bot does: a submission that fills it is discarded, and the
response looks exactly like an accepted one.

Every submission gets the same answer, whether the address is new, already
waiting or already subscribed. Nobody can use your form to find out who is on
your list.

## 3. What the subscriber sees

1. They submit the form and get a short page saying to check their mail.
2. They receive one mail with one link.
3. They open the link. The membership moves to `confirmed`, a consent record
   is written, and they see a page naming the list they joined.
4. Every later mailing carries an unsubscribe link and a link to their
   preference centre.

The link is single use. Opening it a second time changes nothing and shows the
same page a broken link shows. That is on purpose: a link that still works
after it was used is a link somebody else can use.

If the mail never arrives, the membership stays `pending` and you will see it
on the list detail page. Check the instance mail settings first.

## 4. Soft opt-in, for customers you already have

Soft opt-in lets you mail an existing customer about similar products without
asking again. It is narrower than it sounds, and Pipelinq holds you to it.

- The list's opt-in mode must be **soft**. An import into a double opt-in list
  is refused, with a message telling you to use the signup flow instead.
- The import must record that you offered an objection: when you offered it,
  and in what words. An import without that evidence is refused.
- The membership is stored as `confirmed` with lawful basis `soft-opt-in`.

A consent record that claims soft opt-in without the evidence does not permit
a send. It fails the check and the reason lands in the audit log. Storing the
claim is not the same as having the ground.

## 5. Unsubscribe

Every mailing carries an unsubscribe link served by Pipelinq, never by a mail
provider. It is yours whatever transport sent the mail.

- Opening the link shows a page naming the list and a button. Nothing changes
  yet, because mail clients follow links to make previews.
- Pressing the button closes the membership, records the withdrawal in the
  consent ledger and skips any mailing already queued for that person.
- The same page offers to leave every list at once.

You can also close a membership yourself from the list detail page or from the
Subscriptions section on a contact. It is recorded the same way.

Unsubscribe links stay valid for two years, because they sit in mail archives.
Confirmation links expire after seven days, because nobody confirms a signup a
week later.

## 6. The preference centre

The preference centre is one page listing every list a person may join, with
their current state on each. They tick what they want and save once. Pipelinq
confirms what was ticked, closes what was not, and writes the ledger for each
change.

It only ever shows that person's own memberships. No address and no contact
identifier belonging to anyone else appears on it.

## 7. Mail a list

In **New blast**, the audience step now takes either a segment or a list. Pick
one. The wizard refuses to move on with neither.

When you pick a list, the recipients are resolved at send time and are exactly
the confirmed memberships whose consent still stands. A pending membership is
never mailed. Neither is one that unsubscribed after you drafted the blast.

The send summary counts the rest as skipped for want of consent, which is the
same wording a segment send uses.

## What to watch for

**A key you cannot lose.** Confirmation, unsubscribe and preference links are
signed with a key this instance mints once and keeps. Lose it and every
unsubscribe link in every archived mail stops working. Restoring the instance
restores the key. If it is ever genuinely gone, send the preference centre
link again: that is the documented way back.

**Two lists, one person, one ledger.** A consent record scoped to a list gates
that list only. Your channel-wide consent records keep working for segment
sends exactly as before. Neither leaks into the other.

**A list with no confirmed members.** The blast stays in draft and reports
nothing queued. That is not a failure, it is a list you have not filled yet.
