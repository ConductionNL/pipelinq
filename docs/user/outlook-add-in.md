<!--
SPDX-License-Identifier: EUPL-1.2
SPDX-FileCopyrightText: 2026 Conduction B.V.
-->

# Pipelinq in Outlook — user guide

With the Pipelinq add-in you see the sender of a mail in Pipelinq while you read
it in Outlook, add them as a contact, and log the mail as a contact moment. Your
administrator makes the add-in available; ask them if you do not see it.

## Open the pane

Open a mail you received and choose **Pipelinq** in the mail's toolbar (in
Outlook on the web it may be under the **⋯** menu or **Apps**). The pane opens
next to the mail.

## Sign in, once

The first time, press **Sign in**. A window with your Nextcloud login opens: sign
in as usual and press **Grant access**. The window closes and the pane shows the
sender. You stay signed in on every computer where you use Outlook with this
mailbox.

To stop, press **Sign out** at the bottom of the pane, or delete the app password
of the add-in in Nextcloud under **Personal settings → Security → Devices &
sessions**.

## A sender you know

When the sender is a contact or client in Pipelinq, the pane shows them, with the
client a contact belongs to. **Open in pipelinq** opens their record in a new tab.

## A sender who is new

When nobody in Pipelinq has the sender's address, the pane says so and offers
**Add as contact**, with their name and address already filled in. When their
mail domain belongs to a client organisation in Pipelinq, the pane offers to link
the new contact to it (*Link to …*); untick it to add them without an
organisation. Adding contacts needs the same right as the Contacts page.

## Log this mail

Press **Log this mail** under the contact or client the mail belongs to. Pipelinq
records a contact moment with channel *email* holding the subject, the date and
the first lines of the mail (at most 1,000 characters). The full mail and its
attachments stay in Outlook. You find the contact moment on the contact's or
client's page in Pipelinq.

You only see, and can only log mails on, the contacts and clients you may see in
Pipelinq itself.
