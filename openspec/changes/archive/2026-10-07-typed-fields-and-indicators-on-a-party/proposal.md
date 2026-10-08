---
kind: code
depends_on: [contact-moments-on-pipelinq-schema]
---

# Proposal: typed-fields-and-indicators-on-a-party

Round 4 discovery sweep, cluster 14 "the party model beyond the requester"
(`procest/_round4/discovery/build-plan.md` in ConductionNL/market-intelligence,
2026-09-14). Sixteen candidates, thirteen passers, eleven driven. Cluster owner
openregister, size L. Its stated mechanism: "extend openregister's contact schemas
**and pipelinq's contact registry**; dossiq declares which kinds a case type
accepts". This change is pipelinq's half of three of those candidates. Umbrella:
`competitor-parity-2026-09`.

## Summary

A party carries typed fields of its own, and it carries standing indicators that show
on every case and every contact moment of theirs. An organisation is a tree, and
carries its own fields at each node. dossiq reads all of it through a leaf and
declares none of it.

## Why

Two of the three candidates are `must`, both are marked **matrix holes** by the
sweep, and both sit in its loudest twenty-five: custom fields on parties is number
14, party indicators surfaced on the case is number 22. A matrix hole is a `must` for
a municipality with two or more driven passers and **no row in the corpus to hold
it**, which is the sweep's sharpest finding: the 225 rows never asked.

The indicator candidate's clause names the failure exactly: "writing to a deceased
person or publishing a protected address is the failure this prevents, and our row
asks only about address protection". That is not a convenience. A brief sent to
somebody who died last month is the kind of mistake a wethouder hears about.

pipelinq owns it because pipelinq owns the party. `unify-client-contact` already
keys `client` and `contact` by a Nextcloud Contact and demotes the identity fields to
denormalised mirrors, and `master-data-management` already keeps a golden record per
master entity. A Nextcloud Contact cannot hold arbitrary typed fields, so the typed
half belongs on pipelinq's relationship record, beside the mirrors that are already
there.

## The candidates, with their lane citations

| id | capability | relevance | driven passers | lane |
|---|---|---|---|---|
| C-configuration-105 | Typed custom fields are added to parties and other records, not only to the case. | must, matrix hole | freescout, osticket | `cross-area.tsv:7` |
| C-parties-and-contacts-9 | An indicator held on the party is shown on every case of theirs and changes how it is handled. | must, matrix hole | dimpact-zac, itop | `parties-and-contacts.tsv:8` |
| C-parties-and-contacts-12 | Organisations are nested as a tree and the organisation itself carries custom fields. | should | zammad | `parties-and-contacts.tsv:21` |

osTicket proves that a form attaches to more than the case: `ost_help_topic_form`
with form types U for user, O for organisation, A for asset and L1 for a list item.
Freescout is the second driven passer, with jira-service-management and youtrack
documented. The sweep's note: "Four systems, and the parties lane and the
configuration lane asked the same question about the same field. Lanes disagree,
rated must, should, could. Matrix hole."

Dimpact ZAC proves the indicator with Betrokkenen (`docs/user-manual-features.md`),
iTop is the second. The sweep's note: "Both put a standing flag on the person rather
than on the case, read at case level."

Zammad proves the organisation tree with hierarchical groups: `app/models/group.rb:27`
carrying parent, path and cycle and depth guards, and `HasObjectManagerAttributes` at
`:12`. The sweep's note separates it from what we already have: "Row 5.7 is the
internal organisation; this is the customer organisation."

**Decision D21** is not needed on any of the three: each has at least one driven
passer. The documented claims from jira-service-management and youtrack are recorded
above as upper bounds and are not the basis of any requirement below.

## What the sweep found already, and what it did not

The sweep read dossiq `partial` on the indicator candidate on
`register.d/25-brp-kvk.json`, which is a BRP and KvK lookup, not a standing flag with
a handling effect. On the custom fields candidate it read `no`, noting that "11.3 and
dq 11.37 are both per case type" and that "OpenRegister schemas exist per object; a
per-party form attached at configuration level not found". On the organisation tree
it read `partial` on `roleType.ncGroupId`, which "is flat".

So the gap is real in all three, and in each case it is the same gap: the
configuration exists per case type and nothing exists per party.

## What pipelinq builds

- **A party field set, administered.** `partyFieldSet` declares typed fields for a
  party kind: text, number, date, choice from a code list, boolean, reference. The
  values live on the party's own record, not on a case. An administrator adds
  "vestigingsnummer" without a release.
- **An indicator, standing on the party.** `partyIndicator` declares the vocabulary:
  a code, a label, a severity, and what it means for handling. `partyIndicatorValue`
  holds one on one party, with a period and the source that set it. "Overleden",
  "geheimhouding adres", "bewindvoering", "agressie-registratie".
- **The indicator travels to every case and every contact moment.** Any surface
  showing the party shows its indicators, resolved live. An indicator set today shows
  on a case opened last year.
- **A handling effect, declared and enforceable.** An indicator may declare that
  outbound communication is blocked, that an address must not be published, or that a
  warning must be acknowledged. pipelinq answers the question; the app about to send
  asks it.
- **An organisation tree with fields per node.** Organisations nest, with a parent, a
  materialised path, and guards against a cycle and against unbounded depth, following
  Zammad's shape. Each node carries its own field values.

## How dossiq consumes it

1. A case detail page places a pipelinq leaf showing the party's fields and
   indicators. dossiq declares no field and holds no indicator.
2. Before sending anything to a party, dossiq asks pipelinq whether an indicator
   blocks it. A blocked send is refused with the indicator named, wherever the send
   was started.
3. Before publishing a case, dossiq asks the same question about the address.
4. dossiq's `25-brp-kvk.json` lookup stays what it is: a lookup. It may set an
   indicator value and it does not become the indicator model.

## The existing specs this extends

- `contact-moments-on-pipelinq-schema` (#1932), which is open and is a declared
  dependency of this change. Its contact-moment panel gains the party's indicators,
  so a KCC agent taking a call sees "agressie-registratie" before they speak. The
  four requirements it already carries are untouched.
- `unify-client-contact`. The typed values hang on the relationship record it
  defines, beside the denormalised identity mirrors. The Nextcloud Contact stays
  authoritative for identity, and this change does not move that line.
- `master-data-management`. A merge of two parties must carry field values and
  indicators across, and its reversible merge stays reversible.
- `client-management` and `customer-360` render the new surfaces. Unchanged in
  substance.

## Size and dependencies

**Size: M.** Four schemas, one tree with its guards, one resolution question, and
surfaces on three existing panels.

**Depends on:** `contact-moments-on-pipelinq-schema`, for the panel the indicators
appear on. Nothing else in this umbrella.

## What this change does not do

- It does not merge two party records. `C-parties-and-contacts-20` is a separate
  `must` in the same cluster and `master-data-management` already owns merging. This
  change only requires that a merge carries fields and indicators across.
- It does not declare which party kinds a case type accepts. That is
  `party-kinds-accepted-per-case-type` beside this one.
- It does not replace OpenRegister's schema model. A field set is a declaration of
  which properties a party kind carries, expressed in the register the fleet already
  uses, not a second field engine.
