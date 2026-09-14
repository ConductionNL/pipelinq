# Proposal: competitor-parity-2026-09

Round 4 discovery sweep of the dossiq competitor programme
(`procest/_round4/discovery/build-plan.md` in ConductionNL/market-intelligence,
written 2026-09-14). 36 systems read, 631 candidates kept, 70 capability clusters.
This is the pipelinq umbrella for wave 3.

## Summary

pipelinq owns the party and the work above the case. The sweep's ownership rule
moves 536 of 631 candidates out of dossiq, and **7 of them land on pipelinq** as a
cluster of their own, with three more arriving from the party cluster openregister
leads. dossiq consumes all of them and owns none.

## Why

There is no parity umbrella in this repo yet. `contact-moments-on-pipelinq-schema`
(#1932) was opened by the small-owner lane against the round 3 gap register, one row
at a time. Round 4 is larger and grouped, so the changes beside this one share a
statement of origin instead of each repeating it.

The rule they all follow is Ruben's: dossiq consumes, the owner holds the logic. A
case app that carries its own project object, its own party fields or its own party
kind list has three copies of a model the CRM already owns, and a schema slug is
global per organisation, so the copies collide rather than coexist.

## The changes under it

| change | cluster | candidates | size |
|---|---|---|---|
| `the-project-above-the-cases` | 69, the project above the cases | C-tasks-and-phases-7, C-tasks-and-phases-18, C-tasks-and-phases-23, C-tasks-and-phases-34, C-reporting-11, C-reporting-16, C-reporting-24 | L |
| `typed-fields-and-indicators-on-a-party` | 14, the party model beyond the requester | C-configuration-105, C-parties-and-contacts-9, C-parties-and-contacts-12 | M |
| `party-kinds-accepted-per-case-type` | 14 | C-configuration-4 | M |

Cluster 14 is owned by openregister, and its stated mechanism is "extend
openregister's contact schemas **and pipelinq's contact registry**; dossiq declares
which kinds a case type accepts". The two changes above are pipelinq's half of it.
openregister's half and dossiq's declaration are written in their own repos.

## The decisions these rest on

- **D5.** All five parked round-3 revivals are built. Cluster 69 names D5.
- **D6.** Promotion is relevance-led and every `must` enters. Two of the ten
  candidates are `must`, and both are in cluster 14: custom fields on parties, which
  is number 14 of the sweep's loudest 25, and party indicators on the case, which is
  number 22. Party kinds per case type is number 25.
- **D21.** A documented candidate is admitted and labelled. Each spec says on its
  face when a requirement rests on a vendor claim rather than on a driven system.
- **D17.** The product serves a broad market including MKB, so a candidate rated
  `not` for a municipality is not disqualified. None of pipelinq's ten is in the
  `not` bucket, so this decision changes nothing here and is recorded for
  completeness.

## Affected projects

- `pipelinq`: three changes, listed above.
- `dossiq`: consumes all three. Links a case to a project, reads party fields and
  indicators through a leaf, declares which party kinds a case type accepts. Not
  changed here.
- `openregister`: owns the contact schemas half of cluster 14 and the survivorship
  that keeps a golden record. Not changed here.
- `humaniq`: owns hours and rostering (decision D19). Cluster 69's effort candidates
  stop at the project's own roll-up and do not become a second time model.
