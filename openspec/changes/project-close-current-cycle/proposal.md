# Proposal: project-close-current-cycle

## Why

pl3 PqProject: the board draws "Cyclus sluiten" in the project page's header. A cycle could only be closed through
`POST /api/programme-cycles/{cycleId}/close`, which needs the cycle's id, and the header knows only the project. The
page also drew its four figures inside the body column, the plan as a two-column list, the cycle tasks without their
count line (the manifest used a key the list does not read), an add button on the overview's cycles card the board does
not draw, and read events in its history.

## What changes

- `POST /api/programmes/{programmeId}/close-cycle` closes the project's current cycle: the open cycle that started
  last, over its own tasks, exactly as closing it by id does. A project the caller may not read is refused with 403, a
  project without an open cycle with 409.
- The project page draws "Close cycle" in its header while the project is active, the four figures as a band across
  the page, the plan in three columns, a count line under the cycle's tasks, cycles added from the Cycles tab only, and
  history without read events.
