# Team today names each colleague once

## Why
Board PqWachtrij draws the queue's Team today card with one row per colleague under their name ("Fatma Yildiz"). On :8080 (pl4, 29 Oct) the card listed user ids ("fatma.yildiz", "admin") because those agent profiles carry no `displayName`, and it listed fatma.yildiz twice because two agent profiles hold her user id.

## What changes
- `TeamLoadService` names a row by the profile's `displayName`, else the Nextcloud user's display name, else the user id.
- A user id with more than one agent profile is one row: the first profile read.

## Out of scope
- The board's status lines ("In overleg tot 14:00", "Aan de telefoon"): the agent profile holds only `isAvailable`.
- Removing the duplicate profiles from the data.
