---
kind: code
---

# Proposal: tender-types-read-their-schema

## Summary

pl3 PqBeheerKassa (DATA-29 654): the POS admin tab's tender type list showed rows such as "Hello World" and "OpenCatalogi", which are not tender types. `PosTenderService::listTenderTypes()` passed register and schema at the top level of `ObjectService::findAll()`, where OpenRegister never reads them (pipelinq#793), so the read took whatever register and schema an earlier call in the same request had left behind, or returned nothing.

The read now passes register and schema inside `filters`. This is one of the eleven #793 sites; the other two in PosTenderService (`listUnpostedTenders`, `countTendersForType`) stay as they are, behind the guards #793 names.

What changes for users: the list shows the tender types. Creating a tender type with a code that already exists is now refused, as REQ-PST-001 says, because the uniqueness check reads through this list; before, it found nothing and let duplicates in. A sale that looks a tender type up by code (for example LOYALTY) finds it regardless of call order.
