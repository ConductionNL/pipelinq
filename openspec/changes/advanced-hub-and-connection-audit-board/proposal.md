# Proposal: advanced-hub-and-connection-audit-board

## Why

Two boards had no page that matched them. PqGeavanceerdHub draws an Advanced page: link cards for everything that sets the app up and the overviews read now and then, grouped as the Advanced foldout groups them. Pipelinq had no such page. PqVerbindingscontrole draws the connection audit as a board-look list: four counts, quick filters, a table with the client, the handle, the network, the two follow answers as pills and the reason. Pipelinq drew it as a bare custom table without client, counts, filters or download.

## What changes

- A `links` page at /advanced (manifest fragment `99-advanced.json`).
- The connection audit becomes a board-look index over the stored `socialConnection` rows. The reason stays its own column, so an answer the network will not give is never printed as a no. The `auditVerdict` formatter prints yes, no and unknown as Ja, Nee and Niet te controleren. Check again re-runs the audit through the existing endpoint. The custom `ConnectionAudit.vue` is removed.
