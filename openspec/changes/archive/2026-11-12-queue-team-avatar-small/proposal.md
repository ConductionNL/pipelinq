# Proposal: queue-team-avatar-small

## Why

pl8 on board PqWachtrij (items 7-9): the colleagues in "Team today" carry a 36px avatar on live, the board draws 32px.
Dossiq draws the same card (team workload) at 32px, and the library offers the small circle as `avatar.size: 32`.

## What changes

- The queue's "Team today" record list sets `avatar: { text: "{displayName}", size: 32 }`.

## Not in this change

- The gap between avatar and name follows from the library's small-circle rule.
