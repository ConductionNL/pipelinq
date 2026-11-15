---
kind: code
---

# Proposal: newsletter-list-audience-line

## Summary

pl3 PqNieuwsbrieven: under each newsletter's name the list now names its audience as the board draws it: "Segment: verenigingen in Zuiddrecht · 412 contacten" for a newsletter sent to a segment, "Mailinglijst: Afvalnieuws · 2.180 abonnees" for one sent to a mailing list. It was the channel ("email").

Manifest config only: the name column's `secondary` lists the two templates and `secondaryRefs` reads the blast's segment or mailing list, with the list's confirmed subscriptions counted (nc-vue `secondaryRefs`, feat/pl3-secondary-line-from-references). Before that nc-vue release the line is not drawn.
