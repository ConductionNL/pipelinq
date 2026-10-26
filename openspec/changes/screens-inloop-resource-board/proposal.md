# Proposal: screens-inloop-resource-board

## Why

The drawn boards are the UI canon. The walk-in queue (PqInloop) had a component but no page, so a desk could not see or call its walk-in customers. The resource page (PqResource) showed raw type and status codes in a two-column block with the schedule underneath.

## What changes

- A Walk-in queue page (`/walk-in`, under Appointments): today's walk-in tickets oldest first, the waiting, being-helped and helped-today counts, Call next in the header, and Call in, Help and Left on each row as the status allows. Status labels come from `lib/Settings/register.d/99-zz-walk-in-board.json`.
- The resource page: the type and status as pills, a breadcrumb to Resources, the resource details in three columns with labels, and working hours and vacations side by side.

Not yet: the board's Desks card (which employee sits at which desk) and average waiting time have no data behind them; the third count is the customers being helped. The resource page's This week card needs a count of bookings by resource, which the booking's nested assignments cannot be filtered on.
