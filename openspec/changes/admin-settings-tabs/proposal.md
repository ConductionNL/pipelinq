# Proposal: admin-settings-tabs

## Why

The admin page at /settings/admin/pipelinq was one long page of about thirty cards, and every card fetched its data on load. The boards PqBeheer, PqBeheerKoppelingen, PqBeheerKassa, PqBeheerMarketing, PqBeheerPortaal and PqIntegrations draw it as six parts. With every read fired at once, several lists (register configuration, lead sources, request channels, product categories, skills, agent profiles) still showed a spinner when the page was read: no request failed, the page was simply still loading.

## What changes

- The admin shell draws a tab strip (underline variant): Algemeen, Koppelingen, Integraties, Kassa, Marketing en gegevens, Klantportaal. Only the open tab is mounted (`lazyTabs`), so a visit loads one part.
- Algemeen holds the version card and the board's sections in the board's order, then the remaining general sections and a wide Ondersteuning card.
- Every existing section keeps working; each moved to the tab its board draws it on.
- The title and the path name the open tab, as the boards do.
