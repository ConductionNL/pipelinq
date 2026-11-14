# A BRP lookup can verify a client's identity

## Why
Board PqWerkplekKlant's "Wie is het" card shows "Gecontroleerd: Geboortedatum en adres gevraagd" and "Bron: BRP, opgezocht 10:24", with an "Opzoeken in de BRP" button. The client record held no check, and the BRP lookup could not be started from the call.

## What changes
- Client 1.3.0 -> 1.4.0 gains the optional fields `identityVerifiedAt` (date-time), `identityVerifiedVia` and `identityCheck`.
- `POST /api/brp/lookup` takes `verifiedClient` and `identityCheck`. On a match it records them on the client (`identityVerifiedVia: BRP`, now). Purpose binding and the audit trail are unchanged.
- The lookup reads the person whose BSN it asked for. A source in mock mode answers everyone it knows, while a live source answers only the person asked. It also reads HaalCentraal's Dutch name shape (`naam`).
- `BrpClientCheckDialog` opens from the card's "Look up in the BRP" action. The card shows Checked and Source.
- integriq #2751: the `brp-haalcentraal` mock also knows the eight Zuiddrecht residents of the example-set cast.
