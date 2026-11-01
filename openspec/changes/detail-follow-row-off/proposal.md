# Proposal: detail-follow-row-off

## Why

pl8 on boards PqTaak, PqZRapport and PqSocialBericht (1 Nov): live draws a "Volgen 0" follow row under the page
header, so the tabs and every card below sit 33px lower than drawn. The boards have no follow row, and the simple
profile's ticket page already turns it off (`follow: false`).

## What changes

- The simple profile turns the follow row off on TaskDetail, ZReportDetail and SocialPostDetail (`follow: false`).
- The full profile keeps the follow row on those pages.

## Not in this change

- The card structures and gaps under the tabs: they are measured again once the tabs sit where drawn.
