---
kind: code
---

# Proposal: report-pages-on-widgets

## Why

The report and marketing pages were drawn by pipelinq components that fetched their own data: the channel, agent and
lead analytics sections, the SLA breakdown, the satisfaction panels, the loyalty report, the article sections, the social
approval buttons, the connection audit, the competitor watches and the search queries. nextcloud-vue now has chart, stat,
table, facts, markdown and image widgets on app endpoints, so these pages become manifest configuration, as dossiq's
reports did (PL-ABSTRACT lane PL3, Ruben's decisions of 21 October 2026: report endpoints answer row arrays).

Two of those components were wrong, not only custom. The channel comparison filled first-contact resolution with 0 and
SLA attainment with the target itself, so every channel read green at its target. The billing-category donut read a
type that does not exist and always said no hours were recorded.

## What changes

- The report endpoints answer rows: per channel (with real FCR and SLA attainment, and the channel in words), per agent
  (display name, handling time in seconds) with team totals, per satisfaction channel, the SLA breakdown with its
  attainment in percent and the grouping's name, the win/loss slices, the search queries' click-through in percent.
- The CSV export of the contact report works (it was a 501).
- Loyalty reporting reads its programme from a page filter and its period from the page date range; enrolment is a
  header form posting to a new `/api/loyalty/accounts`, which refuses an account without opt-in.
- The pages listed above become dashboard or detail configuration; their components, registry keys and the unreached
  `/articles/new`, `/social-posts/new` and `/loyalty/enroll` routes are removed.

## Depends on

nextcloud-vue `feature/pl-abstract-pl3-widgets` (row tokens, endpoint row sorting, stat note placeholders, markdown and
image `content.field`), `feature/pl-abstract-pl2-facts` (facts `endpointSource`), `feature/dq-abstract-l6-dash`, and
`feature/pl-abstract-pl3-block-list` (CnBlockListEditor for the mail block editor).
