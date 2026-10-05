# Design: simple-contact-dashboard

## D-1 Add on top, drop nothing

The dashboard overlay sets `layout` (ten new entries, then the six old ones
with `gridY` + 15) and appends ten widgets. The spec asserts every old widget
equal, every old layout entry equal except `gridY`, and no two cards in one
grid cell.

## D-2 One filter, three places

`Wacht op mij` is `{ assignee: @me, status: in_progress }` as the number's
source, as the list card's filter, as the number's link query and as the view
on the tickets list. The spec compares them. The same holds for Nieuw, Wacht op
klant and Terugbellen.

Equality and one date comparison are the only filter shapes used. Those are the
shapes the count endpoint and the list are both known to read the same way.

## D-3 Views are added, never taken

`configPatch` adds `showCount` to the four views, `configAppend` adds three,
`configOrder` leads with five and `quickFilterMaxVisible` is 5.

## D-4 Late is `lte 0`

Today counts as late, on the list's deadline cell and in the attention card's
filter (`slaDeadline` before tomorrow).

## D-5 Two endpoints, two filter shapes

The attention card's `visibleWhen` source is read through the objects list. The
library's query builder writes a nested operator (`slaDeadline: { lt }`) as one
JSON value, and OpenRegister answers that with a 500. So that filter uses the
flat key `slaDeadline[lt]`. The stacked bar goes to the aggregation endpoint,
whose own flattener reads the nested shape, so `occurredAt: { gte }` stays
nested there. The spec builds the card's request with the library's own two
functions and fails on a `{` in the address.

## D-6 Dates in list cards

A list card's column prints the stored value unless it says `format`. The three
date columns say `format: date-time`, which renders a relative time.
