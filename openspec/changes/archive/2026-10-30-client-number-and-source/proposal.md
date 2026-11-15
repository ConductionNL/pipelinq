---
kind: code
---

# Proposal: client-number-and-source

## Summary

Board PqKlant, Identity card: "Klantnummer K-2019-01184" and "Bron Mijn Zuiddrecht en BRP". client schema 1.3.0 adds the optional `customerNumber` and `source`, both shown on the client page's Identity card.

Not added: a `relations` field. "Partner van" and "secretaris van" are relationship objects (the `relationship` schema, typed and two-way), which the client page's Relationships widget already lists.
