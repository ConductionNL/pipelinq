<!--
SPDX-License-Identifier: EUPL-1.2
SPDX-FileCopyrightText: 2026 Conduction B.V.
-->

# Marketing blasts — user guide

This guide walks marketing managers through the full Pipelinq marketing
campaign workflow: building a rule-based segment, authoring a compliant
template, scheduling and sending the blast, running an A/B variant
test, and monitoring delivery and revenue attribution after the send.

> A Dutch translation of this guide is available at
> [`marketing-blasts.nl.md`](marketing-blasts.nl.md).

## At a glance

| You want to … | Open … |
| --- | --- |
| Define an audience | **Marketing → Segments** |
| Author an email/SMS body | **Marketing → Templates** |
| Send a campaign | **Marketing → Blasts → New blast** |
| Watch a send unfold | **Marketing → Blasts → (your blast) → Monitor** |
| Compare results across campaigns | **Marketing → Blast performance** |

All five surfaces live under the **Marketing** group in the main
Pipelinq navigation. They are gated to the marketing role
(`pipelinq-marketing`); users without the role do not see the group.

## 1. Create a segment with the rule builder

A **Segment** is a live, rule-based query — not a frozen list. Every
time a blast is sent the segment is re-evaluated against contact data,
so newly-added or newly-qualifying contacts are picked up
automatically.

### 1.1 Open the segment builder

1. Navigate to **Marketing → Segments**.
2. Click **+ New segment** in the top right.
3. Give the segment a clear name (e.g. *Gemeente prospects — Zuid-Holland*).
   The name appears in the **New blast** wizard and on the performance
   dashboard.
4. Choose the **entity type** — *Contact* or *Customer*. The available
   fields in the rule builder change accordingly.

### 1.2 Build the rule tree

The rule builder lets you compose **AND** / **OR** groups of leaf
predicates. Each leaf predicate has three parts:

- **Field** — a property of the chosen entity (e.g. `tags`, `country`,
  `lifecycle_stage`).
- **Operator** — `equals`, `notEquals`, `in`, `notIn`, `contains`,
  `gt`, `lt`, `gte`, `lte`, `between`, `exists`, `notExists`.
- **Value** — typed against the field's JSON-schema type. The builder
  refuses combinations that don't match (you cannot apply `gt` to a
  string field, for example).

You can nest groups: e.g. `(tags contains "vip" AND country = "NL") OR
lifecycle_stage in ["customer", "evangelist"]`.

### 1.3 Estimate the audience

Click **Estimate size**. Pipelinq evaluates the rule tree against the
current contact/customer set and returns a count. The estimate is
cached for one hour (configurable per instance) to avoid hammering the
register on a quick edit-save cycle. Click **Refresh** to bypass the
cache.

You can also click **Preview members** to inspect the first fifty
contacts that match — useful for spot-checking that the rules behave
as you expect before you send to the whole audience.

### 1.4 Save the segment

Click **Save**. Saved segments appear in the segment list and become
selectable in the **New blast** wizard. A segment can be reused across
many blasts.

> **Tip.** Keep rule trees small and reusable. *"German-speaking
> customers"* and *"VIP customers"* as two segments compose better than
> one *"German-speaking VIP customers"* mega-segment.

## 2. Create a compliant template

A **Template** is the body of a marketing message. Pipelinq supports
two channels:

- **Email** — HTML and plain-text bodies with a subject line.
- **SMS** — single text body, 160-char concatenated.

Both channel types enforce compliance checks before they can be saved
or used in a blast (see *Compliance requirements* below).

### 2.1 Open the template editor

1. Navigate to **Marketing → Templates**.
2. Click **+ New template**.
3. Choose the **channel** (email or SMS).
4. Give the template a name (e.g. *Q4 Outreach — Gemeenten*).

### 2.2 Write the body

The body field accepts standard merge fields surrounded by double
braces:

- `{{contact.firstName}}` — the recipient's first name.
- `{{contact.organization}}` — the recipient's organisation, if any.
- `{{unsubscribe_url}}` — the unique unsubscribe link for this
  recipient (required — see *Compliance requirements*).
- `{{view_in_browser_url}}` — the public view-in-browser link.

For **email**, also fill the **Subject** field. Subject lines longer
than 78 characters are flagged but not blocked.

### 2.3 Compliance requirements

Pipelinq enforces the following requirements on every save. The
template editor highlights each requirement and refuses to save the
template if any are missing:

| Channel | Requirement | Why |
| --- | --- | --- |
| Email | `{{unsubscribe_url}}` merge field present in body | CAN-SPAM §316.5(a)(3), GDPR Art. 7(3) |
| Email | Physical sender address (street + city + country) | CAN-SPAM §316.5(a)(5) |
| Email | List-Unsubscribe header is added automatically | RFC 8058 one-click unsubscribe |
| SMS | `STOP` keyword footer (e.g. *"Reply STOP to opt out"*) | TCPA §227(b), GDPR Art. 7(3) |
| Both | Sender identity (organisation name) | CAN-SPAM §316.5(a)(4), GDPR Art. 13 |

The **organisation address** and **default sender identity** are
configured once per instance under **Admin settings → Marketing →
Sender identity** by an admin.

### 2.4 Preview and save

Click **Preview** to render the template with a sample contact's data.
The preview lets you check merge-field substitution, formatting, and
the unsubscribe link rendering. Click **Save** when you are happy with
the body. Saved templates appear in the **New blast** wizard.

## 3. Schedule and send a blast

A **Blast** is the act of sending one template to one segment through
one channel, optionally scheduled for a future time and optionally
split into A/B variants.

### 3.1 Open the New blast wizard

Navigate to **Marketing → Blasts** and click **+ New blast**. The
wizard guides you through six steps. You can move forward and back
freely; the blast is created as a **draft** on the final step.

### 3.2 Step 1 — Name

Give the blast a descriptive name (e.g. *Q4 Outreach — Gemeenten*).
The name is what shows up in the blast list and the performance
dashboard.

### 3.3 Step 2 — Segment

Pick one of the segments you created in section 1. The wizard shows
the estimated audience size so you have a sanity check before you go
any further.

### 3.4 Step 3 — Template

Pick one of the templates from section 2. The wizard filters templates
to the channel you choose in step 4, so if the list is empty step
back and check that you have a template for the chosen channel.

### 3.5 Step 4 — Channel

Pick **Email** or **SMS**. If your instance has more than one provider
configured (e.g. both SendGrid and SES for email), pick the
**Connector source** — the provider that will actually carry the
delivery. Leaving it blank uses the instance default.

### 3.6 Step 5 — Schedule

Pick a **Send at** datetime. The blast will move from `scheduled` to
`sending` automatically at that moment (the dispatch loop runs every
five minutes). Leave blank to send immediately when you submit on
step 6.

### 3.7 Step 6 — A/B split (optional)

Tick **Run an A/B variant test** to split the audience between two
template variants. The split percentage is the share of the audience
that receives **variant A**; the remainder receives variant B. Pick
the second template in the variant slot that appears.

The wizard performs a **consent preflight** when you submit:

- It re-evaluates the segment to get the live recipient list.
- It checks each recipient's consent record for the chosen channel.
- If any recipients lack consent, the **Missing consent** dialog
  appears with three options:
  - **Cancel** — do not send, return to the wizard.
  - **Request consent** — trigger a separate consent-request flow for
    the unconsented contacts; the blast remains a draft.
  - **Skip and send** — exclude the unconsented contacts and send to
    the consented remainder. The excluded contacts are listed in the
    blast's delivery log for audit.

When you confirm, the blast is created as `scheduled` (if you picked a
future time) or transitions straight to `sending`.

## 4. A/B testing workflows

Pipelinq supports a single A/B split per blast: two template variants,
one segment, one channel. The split is configured on step 6 of the
**New blast** wizard.

### 4.1 How the split works

When the blast moves to `sending`, the dispatcher partitions the
recipient list deterministically:

- A hash of the recipient's contact ID is computed.
- The hash is mapped into a `[0, 100)` bucket.
- Recipients in `[0, splitPercent)` get variant A; recipients in
  `[splitPercent, 100)` get variant B.

The same recipient always falls into the same bucket on re-evaluation,
so a paused/resumed blast assigns each recipient consistently.

### 4.2 Reading the comparison

Once both variants have sent **at least 100 deliveries each**, the
**A/B Testing** tab of **Blast performance** unlocks. It shows:

- **Open rate** for A and B side-by-side.
- **Click rate** for A and B side-by-side.
- A **statistical-significance** marker — green if a chi-square test
  rejects the null at *p* < 0.05, grey if not. Below 100 deliveries
  per arm the marker shows *insufficient sample*.

### 4.3 Acting on the result

The winning variant is not auto-promoted — picking the winner is a
human decision. The typical pattern is to clone the winning template
into a new "champion" template and use it as the default for the next
blast.

## 5. Monitor delivery and attribution

### 5.1 Live delivery — Blast monitor

From the blasts list, open a blast and click **Monitor** (or open
**Marketing → Blasts → (your blast) → Monitor** directly). The
monitor view shows:

- The blast's current **status** — `draft`, `scheduled`, `sending`,
  `sent`, `paused`, `canceled`.
- Live **delivery totals** — queued, sent, delivered, bounced,
  unsubscribed, complained.
- A **per-delivery log** — each recipient's individual state with a
  timestamp.

The totals are kept fresh by webhook ingest from the configured
provider (SendGrid, SES, Twilio). Webhook events are HMAC-verified
before they are accepted.

#### First-party open/click tracking (base tier)

Provider webhooks are the default source of open/click telemetry, but
they require a webhook-capable provider. If your instance sends
through a plain per-tenant connector without webhook support, enable
**first-party tracking** so open/click rates still populate:

- An admin turns on **`blast.first_party_tracking`** under **Admin
  settings → Pipelinq**. It is **off by default** — when off, blast
  rendering and delivery telemetry behave exactly as before.
- When on, every outbound link in a sent blast is rewritten to a
  signed Pipelinq redirect and a 1×1 tracking pixel is appended to the
  email body. Both use opaque, HMAC-signed tokens (default 90-day
  validity, admin-overridable) that encode only the delivery id — no
  email address, name, or other personal identifier ever appears in a
  tracking URL.
- Opens and clicks recorded this way feed the **same** `openedAt` /
  `firstClickAt` / `status` fields and the same totals roll-up as
  provider webhooks, so the Overview and A/B testing tabs below work
  identically regardless of which source recorded the event. Running
  both a webhook-capable provider and first-party tracking at once
  does not double-count — each event is recorded once per delivery.
- **Retention**: first-party open/click events are stored on the
  existing `blastDelivery` row (no separate event log), so they are
  retained for exactly as long as the parent blast/delivery record —
  deleting or archiving the blast removes its tracking data with it.
- **Privacy**: the pixel and redirect never capture IP address or
  user-agent; the unsubscribe link and the required physical-address
  footer are never rewritten, so unsubscribing and compliance
  reporting are unaffected by enabling this setting.

#### Reporting to Portaliq traffic

Run a portal in Portaliq next to Pipelinq? Then you can see mail opens
and clicks next to site visits in that portal's traffic reports.

- An admin sets **`blast.traffic_portal`** under **Admin settings,
  Pipelinq** to the portal slug in Portaliq. Leave it empty to keep
  mail tracking inside Pipelinq. Empty is the default.
- Every recorded open or click is then also reported to that portal as
  an `email_open` or `email_click` traffic event. The event carries the
  blast, the contact reference, the campaign name and the clicked link.
  It never carries an email address, IP address or user agent.
- The blast delivery in Pipelinq stays the system of record. The
  report runs after the delivery is written. When Portaliq is not
  installed, or the report fails, the open or click is still recorded
  here and the pixel or redirect still works.
- Portaliq attributes mail traffic by campaign, not by person. A mail
  open and a site visit only link when the site visit carries the same
  campaign parameters.

To stop a blast that is currently sending, click **Cancel**. All
queued deliveries are aborted; deliveries already handed to the
provider continue. The blast moves to `canceled`.

### 5.2 Aggregate performance — Blast performance

**Marketing → Blast performance** is the post-send analytics surface.
It has three tabs:

#### Overview tab

A sortable table of every blast with its KPIs:

- **Open rate** = opened ÷ delivered.
- **Click rate** = clicked ÷ delivered.
- **Unsubscribe rate** = unsubscribed ÷ delivered.
- **Bounce rate** = bounced ÷ sent.
- **Complaint rate** = complained ÷ delivered (spam reports).

Sort by any column to find your best (or worst) campaigns.

#### A/B testing tab

The side-by-side variant comparison described in section 4.2.

#### Attribution tab

For each blast, the **Attribution** tab shows:

- **Attributed deal count** — the number of deals in CRM whose first
  link to the customer was a click on this blast.
- **Attributed revenue (EUR)** — the summed value of those attributed
  deals.

Attribution uses a **first-click** model with a configurable
attribution window (default 30 days). When a recipient clicks a
tracked link, Pipelinq records the click against the blast. If that
contact's organisation later becomes the customer on a deal within
the window, the deal is attributed to the blast.

The attribution window is set per-instance by an admin under **Admin
settings → Marketing → Attribution window**.

### 5.3 Exporting results

Both the **Overview** and **Attribution** tabs offer a **Download CSV**
button for offline reporting. The download includes one row per blast
and one column per KPI.

## Troubleshooting

| Symptom | What to check |
| --- | --- |
| Blast stuck in `scheduled` past the send time | The dispatch loop (`BlastSendJob`) runs every 5 minutes. If you still see no progress after 10 minutes, ask your admin to check the background-job log. |
| Many bounces shortly after send | Your contact list may contain stale addresses. Use the **Delivery log → bounced** view to identify and clean them. |
| Open rate seems very low | Opens are tracked via a 1×1 tracking pixel; mail clients that block remote images (especially Outlook on Windows) will under-report. Click rate is a better signal of engagement. |
| Attribution shows zero | Attribution depends on click tracking + the deal's customer matching the blast recipient's contact organisation. Check that click tracking is enabled on the template's links and that the deal's customer is correctly linked. |
| The **Marketing** group is missing from the navigation | You are not in the `pipelinq-marketing` user group. Ask your admin to add you. |

## See also

- Admin settings for marketing — **Admin → Settings → Marketing**
  (sender identity, default provider, attribution window).
- The **Customer portal** guide for the recipient-side unsubscribe
  flow: [`portal-guide.md`](portal-guide.md).
