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
