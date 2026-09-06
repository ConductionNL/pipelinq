<!--
SPDX-License-Identifier: EUPL-1.2
SPDX-FileCopyrightText: 2026 Conduction B.V.
-->

# Search and competitor intelligence, user guide

Three pages that answer three questions: which search terms are worth writing for, what the competition published this week, and whether we actually follow the clients we say we work with.

Nothing here goes looking on its own. Every finding is a proposal, and a proposal only becomes a record when you say so.

## At a glance

| You want to know | Open |
| --- | --- |
| Which search terms to write for | **Marketing, Keywords** |
| What a competitor published | **Marketing, Competitors** |
| Whether we follow a client | **Marketing, Connection audit** |
| What Matomo says about a campaign | The campaign report, once Matomo is connected |

## Before anything shows up

The Keywords page reads the Search Console rows the daily import brings in. Connect a property under **Settings, Marketing traffic** first, and give it a day: Google publishes a day about two days later.

The other three surfaces read through OpenConnector sources, which an administrator sets under **Settings, Marketing intelligence**:

| Setting | What it is for | Without it |
| --- | --- | --- |
| Crawl source | Reads your own pages, so the content gap check knows what they say | The gap check does not run, and the page says so |
| Matomo source and credential id | Reads Matomo's reports | The campaign report shows no Matomo numbers |
| Egress source for competitor reads | Reads feeds, sitemaps, page fragments and public timelines | No watch runs |

There is no key to enter anywhere in that section. The one credential this uses is a Matomo token, and a token lives in the credential broker: what you type here is the credential's id. A value that looks like a Matomo token is refused, on purpose.

## Keywords

Four things, all from the same window of Search Console data.

**Where we rank.** Every query grouped into positions 1 to 3, 4 to 10, 11 to 20 and beyond. It is the shape of your visibility in one line, and it is the number to watch across quarters.

**One push from page one.** Queries with real demand sitting between position 8 and 20, earning fewer clicks than that position normally earns. These are the cheapest wins on the page: the demand is already there and the ranking is already close.

**Two pages, one query.** Where two of your pages both show up for one search and, between them, earn less than the better one does alone. Usually the fix is to merge them, or to point one at the other.

**Nothing of ours answers this.** Queries people type where no page of yours carries the terms in its title or headings. Every significant word has to appear, not just one, so a page about "verzoek indienen" does not count as an answer to "woo verzoek indienen".

That last list is empty until a crawl source is set, and the page says so rather than telling you there are no gaps.

### Turning a finding into a target

Every row has **Add as target**. It opens a short dialog: what you want to do with the term, what somebody typing it is trying to do, and which page should win it. Only then does a keyword target exist.

That is deliberate. The four lists are recomputed on every read, so a page that created records from them would add and remove your targets while you looked at them. A target is a commitment somebody is going to write a page against.

Search volume and difficulty stay empty. Nothing in Pipelinq measures them today, and a zero would read as "nobody searches for this" rather than "we have not looked it up".

## Competitors

A competitor is an organisation and the public places it publishes. Give it a watch, and the watch records what changed.

| Kind | Reads | Good for |
| --- | --- | --- |
| Feed | An RSS or Atom feed | A blog or a news page |
| Sitemap | The sitemap, compared to last time | New pages, and pages they quietly rewrote |
| Page fragment | One element of one page, by CSS selector | A pricing table, a customer list, a vacancy page |
| Public timeline | A Mastodon or Bluesky account | What they say in public |
| Saved search | A web search through hermiq | Press releases and awards that are not on their own site |

LinkedIn and Meta are not options and will not become options. Neither offers a legitimate way to read another organisation's posts, and the only way to get them is to scrape, which this product does not do.

### What each column says

The watches table shows when each watch last ran and how it went. That matters more than it looks: "they published nothing" and "we could not read their site" produce the same empty list, and the outcome column is what tells them apart.

Each item shows a relevance score when hermiq scored it, and **not scored** when it did not. Scoring is off until an administrator turns it on, because it sends the headline to whatever model your hermiq is configured with.

A watch records an item once. Running the same watch twice over unchanged sources adds nothing.

## Connection audit

One row per client and network: do we follow them, do they follow us.

Most rows will say **cannot be checked**, with the reason next to them. Only Mastodon and Bluesky publish a follower list that can be read. LinkedIn gives follower counts and not a list, X puts the lookup behind a paid tier, and Meta publishes no list at all.

That is the honest answer, and it is why the column has three values rather than a tick box. Reporting "no" where the truth is "the network will not say" would send you off to follow somebody you already follow.

**Check again** re-runs the audit against the two networks that answer.

## For administrators

Two commands run the same work on demand:

```
occ pipelinq:marketing:competitor-watch:run
occ pipelinq:marketing:connection-audit
```

The scheduled run is not a command and not a background job of this app: it is an OpenRegister flow, shipped with the `competitorWatch` schema and disabled until you enable it. Enable it, and repoint its schedule trigger at a service account of your own rather than leaving it on the administrator.
