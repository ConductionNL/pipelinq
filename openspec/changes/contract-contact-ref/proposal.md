---
kind: code
---

# Proposal: contract-contact-ref

## Summary

pl2 PqContactpersoon: the board's Gerelateerd card on Joke Smit lists "Contract CON-2026-0031". A sales contract only references its client (`clientRef`), so nothing could list the contracts a contact person is the counterpart of.

- **Schema:** `salesContract` gains an optional `contactRef`, a uuid reference to the contact person at the client. Schema version 1.1.0.
- **renewalLeadOutcome accepts null:** OpenRegister stores the declared default `""` as null, and it widens an optional property to accept null only when its enum already lists null. So a contract saved without an outcome failed every later validation with "should be type 'string' but is 'null'": an edit in the app, or an example-set change. The property now takes string or null, with null in its enum.
- **Gerelateerd card:** the contact page's card (DQ-L1's pq-b5/contact-related-links) lists the contracts whose `contactRef` is the contact, as "Contract {contractNumber}", each linking to the contract page.
- **Data:** the Zuiddrecht set's CON-2026-0031 points at Joke Smit (Sportclub Zuiddrecht 1958). This is a DQ-DATA item.
