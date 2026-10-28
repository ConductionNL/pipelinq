---
kind: code
---

# Proposal: contract-contact-ref

## Summary

pl2 PqContactpersoon: the board's Gerelateerd card on Joke Smit lists "Contract CON-2026-0031". A sales contract only references its client (`clientRef`), so nothing could list the contracts a contact person is the counterpart of.

- **Schema:** `salesContract` gains an optional `contactRef`, a uuid reference to the contact person at the client. Schema version 1.1.0.
- **Gerelateerd card:** the contact page's card (DQ-L1's pq-b5/contact-related-links) lists the contracts whose `contactRef` is the contact, as "Contract {contractNumber}", each linking to the contract page.
- **Data:** the Zuiddrecht set's CON-2026-0031 points at Joke Smit (Sportclub Zuiddrecht 1958). This is a DQ-DATA item.
