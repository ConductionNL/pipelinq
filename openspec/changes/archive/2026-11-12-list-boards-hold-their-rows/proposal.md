# Proposal: list-boards-hold-their-rows

## Why

Round pl9 measured eight Pq list boards that miss only, or mainly, on the
pager's y: the live list shows a different number of rows than the board.

- PqCampagnes and PqContacten draw 5 rows a page ("5 van 12", "5 van 186");
  PqAfspraken and PqOrganisaties draw 8 ("8 van 23"). Live shows the default
  20 a page, so it lists every row.
- PqAfspraken counts 23 bookings this week; the Zuiddrecht set seeds 7.
- PqProducten draws Club-app, basis as a draft ("Concept"); the set seeds it
  as discontinued, although its own description says it is still a draft.
- PqKassadiensten draws a row actions button on every shift; live has none, so
  its rows are 52px high where the board's are 63.
- PqSjablonen lists its templates by name; live lists them in creation order.
- PqOrganisaties draws a table wider than its card (1415 against 1118); the
  DqOrganisaties board, which dossiq's list matches, draws Name, KvK number,
  Legal form, Place, Open cases, Open tickets and Last contact, and fits.

PqJourneys and PqProducten hold rows that are not in the set (old residue);
those are purged on the instance, not here.

## What changes

- `pagination.limit` on Campaigns (5), Contacts (5), Bookings (8) and Clients
  (8), the way dossiq's Contacts page sets its page size.
- Templates sort by name, ascending.
- Cash shifts get a View row action, so each row carries the actions button.
- The organisations list drops Industry and Phone, as DqOrganisaties does;
  both stay on the organisation page.
- Example set: sixteen more bookings this week after the board's first page
  (`zuiddrecht.d/85-week-bookings.json`), and Club-app as `draft`.

## Not here

- The PqSjablonen board draws 6 of 6 templates; the set needs 10, because
  four newsletters and the A/B test take their subject from a template. The
  board is redrawn with the 10 (design-system).
- The PqKassadiensten, PqOrganisaties and PqAfspraken boards are redrawn to
  fit their card as the dossiq index boards do (design-system).
