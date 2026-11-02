# Share a saved view of the tickets list

## Why
Board PqTicketsSelectie (design-system #826) draws the tickets list with the reader's saved view "Klachten deze week" open and "Delen: Klachten deze week" beside Weergave opslaan, opening "the same dialog as in dossiq". pipelinq had no saved-view sharing on the list.

## What changes
Configuration only, no pipelinq code: the Tickets page sets `allowSavedViews: true` and `savedViewShare: true`. Saving, listing and sharing are nextcloud-vue's saved views (saved-views-shared-by-role, saved-view-share-from-toolbar), stored in OpenRegister's views, as on dossiq's case list.

## Depends on
The nextcloud-vue release that carries saved-view-share-from-toolbar. Before it the toolbar has Save view and the side panel's Share, and `savedViewShare` is ignored.
