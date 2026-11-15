## ADDED Requirements

### Requirement: My work lists all my items in one list

`GET /api/worklist/items` MUST return the session user's open tickets (requests, complaints, reports, contact moments), leads that are not in a closed stage, and open follow-up tasks in one shape: `{ kind, id, title, priority, channel, clientName, routeName, record, dueAt, bucket, dueGroup }`.
- `bucket` is `waiting-on-customer` for a ticket waiting on the customer, `answer-today` for an item due today or earlier, and `waiting-on-me` otherwise.
- `dueGroup` is `late`, `today`, `week`, `later` or `none`.
- `?bucket=`, `?lens=tickets|leads|tasks`, `?sort=deadline|waiting|newest|priority` and `?done=1` narrow and order the items.
- `?format=csv` downloads them.

My work's three lists MUST read this endpoint. On a phone the page MUST draw one list grouped by `dueGroup`.

#### Scenario: A lead and a follow-up stand in the lists with the tickets
- GIVEN Pieter has a request due yesterday, a lead closing yesterday, and a follow-up due in three days
- WHEN My work opens
- THEN "Vandaag beantwoorden" lists the lead and the request, each with its kind pill
- AND "Wacht op mij" lists the follow-up

#### Scenario: The phone groups by due
- GIVEN the same items
- WHEN My work opens 375px wide
- THEN one list shows "Te laat" with the lead and the request, then "Deze week" with the follow-up
