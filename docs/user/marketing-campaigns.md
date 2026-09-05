<!--
SPDX-License-Identifier: EUPL-1.2
SPDX-FileCopyrightText: 2026 Conduction B.V.
-->

# Campaigns

A campaign is the thing that holds one marketing effort together: the
mailings, the posts, the landing page and the leads they brought in. Everything
in it carries one campaign value, so a mailing and a post report as one
campaign instead of two.

## At a glance

| You want to ... | Open ... |
| --- | --- |
| Start a campaign | **Marketing > Campaigns > Add** |
| Give a mailing to a campaign | **Marketing > Blasts > (the blast)**, the Campaign field |
| Publish a landing page with a sign-up form | **Marketing > Campaigns > (the campaign)**, the Landing page section |
| See what a campaign did | **Reports > Campaign report** |

## 1. Start a campaign

Go to **Marketing > Campaigns** and add one. Give it a name and say in one
sentence what it should achieve. Pick a source and a medium: the source is
where the visit comes from (`nieuwsbrief`, `linkedin`, `beurs`), the medium is
how it travels (`email`, `social`, `cpc`).

Both are lowercase, and both come from a list your administrator maintains
under **Settings**. That is not tidiness. `LinkedIn` and `linkedin` count as two
different campaigns in every analytics tool, and you only notice when a report
comes back half the size you expected.

Pipelinq derives the campaign value from the name. "Webinar AI voor gemeenten"
becomes `webinar-ai-voor-gemeenten`, and that value never changes again, even
if you rename the campaign. Links that have already gone out keep working.

## 2. Point a mailing at the campaign

Open a blast and set its campaign. From that moment every link in that mailing
carries the campaign's source, medium and campaign value instead of the
per-blast defaults. The blast's own id stays in `utm_content`, so two mailings
in one campaign can still be told apart.

A blast that belongs to no campaign keeps working exactly as before.

## 3. Publish a landing page

Open the campaign and use **Create landing page**. Fill in the page summary and
the page body first: portaliq refuses a page without them.

Pipelinq asks portaliq to publish the page and to bind a sign-up form to it,
with a name, an email address and an organisation. Portaliq answers with the
route and the public address, and Pipelinq records both on the campaign.

If portaliq refuses, you see its own reason and where to fix it:

| What it says | What to do |
| --- | --- |
| The route is already in use | Type a different route and try again |
| The portal does not exist | Check the portal in the marketing settings |
| The campaign needs a summary and a body | Fill both in on the campaign |
| Portaliq is not installed | Ask your administrator to install it |

## 4. A form submission becomes a lead

Someone fills in the form. Pipelinq looks for a contact with that email
address, creates one if there is none, and writes a lead. The lead remembers
two things: the campaign parameters of the visit that first brought this person
to your site, and the parameters of the visit they submitted on. That is how
you can later see that the newsletter found them and LinkedIn closed them.

Every submission also lands in the campaign's touchpoint log. Submit the same
form twice and you get one lead, not two.

## 5. Read the report

Open **Reports > Campaign report** and pick a campaign. You see reach and
clicks per channel, how many people submitted the form, how many leads came out
of it, what those leads were worth, and what the campaign cost.

**Attributed value is divided three ways, and you choose which one you look
at.** First touch gives a lead's whole value to the first thing that reached
them. Last touch gives it to the last. Linear splits it evenly over everything
in between. Switching between them changes the split, never the total, and it
never changes what is stored: all three are computed from the touchpoint log
every time you open the report.

**Every lead says what it closed on.** A lead whose customer has a paid invoice
in shillinq closes on that invoice, and the report says `Paid invoice`. A lead
with no invoice closes on the value somebody entered on the lead, and the report
says `Won lead`. The first is money that arrived. The second is a forecast, and
the report keeps the two apart so you know which is which.

An invoice is only ever counted once, even when two leads point at the same
customer.

**A cost nobody recorded reads as "not recorded", not as zero.** A campaign that
looks free is a campaign nobody questions.

## Next

Send the campaign's first mailing with the [marketing blasts
guide](marketing-blasts.md), or write the article its landing page opens with
using the [articles guide](articles.md).
