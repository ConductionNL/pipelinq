# Proposal: leads-list-board-line

## Why

Board PqLeads opens on "Pijplijn: Verkoop" (the default pipeline as a removable filter, Filter 1), reads "15 van 15 open
leads · € 509.928 in de pijplijn, € 246.122 gewogen · winkans eerst", shows each lead as its title above "client ·
source", and counts its Verouderd lens. Live had none of these.

## What changes

- `defaultFilters: { pipeline: "@config.defaultPipeline?" }` with the default pipeline as the page's app config once it is
  read (nextcloud-vue index-late-config-defaults).
- `countValues`: the open pipeline's value under the list's filters, and the weighted forecast from
  `/api/analytics/commercial` (nextcloud-vue index-count-values). The weighted forecast is of all open leads, not of
  the filtered pipeline: the endpoint takes no filter.
- The title's second line is `{client.name} · {source}`, with the client extended.
- The Verouderd lens counts again: lens counts go through the list endpoint, which takes `@self[updated]`.
