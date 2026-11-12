# Proposal: client-panels-fit-content

## Why

pl6 on :8080 (PqKlant, PqOrganisatie): the client page's tab strip sat in a grid cell of a fixed 18 rows. Sanne de
Vries's Overview is taller than that, so the Activity card was cut off under its note field and its entries never
showed. Buurtvereniging Oost's Overview is shorter, so a gap of several hundred pixels opened above Berichten and
Contactmoment registreren.

## What changes

- The `client-panels` layout item sizes to its content (`sizeToContent: true`), as the boards draw the page: the body
  sections follow the open tab directly.

## Reach

ClientDetail manifest only.
