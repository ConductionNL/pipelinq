---
kind: code
---

# Proposal: walk-in-counter

## Summary

Board PqInloop: a walk-in ticket names the desk the customer is called to ("Balie 2") and, for a customer who left before being helped, when they left. walkInTicket schema 1.1.0 adds two optional fields:

- `counter`: the desk, as a name.
- `leftAt`: set by the Left action together with status `abandoned`.

Still open: the board's Desks side card (which employee sits at which desk and whom they help) needs desk records; a desk is not a resource type today.
