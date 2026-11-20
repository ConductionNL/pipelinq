<!--
SPDX-License-Identifier: EUPL-1.2
SPDX-FileCopyrightText: 2026 Conduction B.V.
-->

# Outlook add-in — administrator guide

Pipelinq ships an Outlook add-in. A colleague reading a mail in Outlook opens
the Pipelinq pane next to it, sees whether the sender is already a contact or
client, adds an unknown sender as a contact, and logs the mail as a contact
moment, without leaving the mail.

Nothing changes for anyone until you deploy the add-in for your organisation.

## What the add-in is

- **The manifest**, served by your instance at
  `https://<your-nextcloud>/index.php/apps/pipelinq/mail-add-in/manifest.xml`.
  It holds only your instance's public addresses: the pane, the icons and the
  Pipelinq app. Its add-in id is derived from your instance, so deploying it
  again updates the add-in instead of adding a second one.
- **The pane**, served at `/index.php/apps/pipelinq/mail-add-in/taskpane`. Only
  Outlook on the web and Microsoft 365 (`outlook.office.com`,
  `outlook.office365.com`, `outlook.live.com`) may show it in a frame; it loads
  Microsoft's Office.js and nothing else from outside your instance.

The add-in only asks Outlook to **read the open item**. It reads the sender,
the subject, the date and the first 1,000 characters of the text, and only when
the colleague presses *Log this mail* does any of that reach Pipelinq. Attachments
and the rest of the mail never leave Outlook.

## Requirements

- Your Nextcloud is reachable over HTTPS from the colleagues' Outlook (the pane
  runs in their browser or Outlook client, not on Microsoft's servers).
- Colleagues who may **add contacts** from the pane need the same right as on the
  Contacts page: membership of `admin`, `sales`, or the CRM group configured in
  Pipelinq. Everyone signed in may look senders up and log mails on the records
  they may read.

## Deploy it in Microsoft 365

1. Sign in to the Microsoft 365 admin centre as a global or Exchange
   administrator.
2. Go to **Settings → Integrated apps** and choose **Upload custom apps**.
3. Pick **Office Add-in**, then either *Provide link to the manifest file* and
   paste the manifest URL above, or download the manifest from that URL and
   upload the file.
4. Choose who gets the add-in (everyone, specific users or groups) and finish
   the wizard. Microsoft notes that it can take up to 24 hours before the add-in
   shows in every colleague's Outlook.

For a single test mailbox, a user can instead add it themselves in Outlook on
the web: **Get add-ins → My add-ins → Add a custom add-in → Add from URL**, with
the same manifest URL, if your tenant allows user-installed add-ins.

## Signing in and revoking access

Each colleague signs in once from the pane, through Nextcloud's own login page
(with your usual two-factor or single sign-on). Nextcloud then gives the add-in
an **app password**; the colleague's account password is never stored in Outlook.

- A colleague revokes the add-in in **Nextcloud → Personal settings → Security →
  Devices & sessions** by deleting its app password. The pane asks them to sign
  in again on the next call.
- An administrator removes it for everyone in the Microsoft 365 admin centre
  (**Integrated apps** → the add-in → **Remove app**). App passwords already
  handed out stay valid until they are revoked in Nextcloud, or the account is
  disabled.

## What gets stored

*Log this mail* creates a contact moment with channel *email*, direction
*inbound*, the subject, the date the mail was sent and at most its first 1,000
characters, on the chosen contact or client. It follows the archival term of
every other contact moment.

## Troubleshooting

- **The pane stays blank in Outlook on the web.** Check that the browser can open
  the pane URL directly; a reverse proxy that sets its own `X-Frame-Options` or
  `Content-Security-Policy: frame-ancestors` header overrides the one Pipelinq
  sends and stops Outlook from framing the pane.
- **"Open this page from Outlook"** shows when the pane URL is opened in a plain
  browser tab. That is expected.
- **Signing in did not finish.** The login window was closed before access was
  granted, or took longer than 20 minutes. Press *Sign in* again.
