---
kind: code
depends_on: [typed-fields-and-indicators-on-a-party]
---

# Proposal: party-kinds-accepted-per-case-type

Round 4 discovery sweep, cluster 14 "the party model beyond the requester"
(`procest/_round4/discovery/build-plan.md` in ConductionNL/market-intelligence,
2026-09-14). Candidate `C-configuration-4`, relevance **`must`**, **marked a matrix
hole**, number 25 of the sweep's loudest twenty-five. Cluster owner openregister; the
cluster's mechanism assigns the registry half to pipelinq and the declaration half to
dossiq. Umbrella: `competitor-parity-2026-09`.

## Summary

pipelinq holds the vocabulary of party kinds: what a `melder`, a `gemachtigde`, a
`belanghebbende` or a `vergunninghouder` is, what identity each may have, and which
fields it carries. A consuming app declares per record type which kinds it accepts,
and the picker follows that declaration instead of offering everything.

## Why

The lane's clause is the whole case: "a subsidy for an organisation and a Woo request
from a citizen need different pickers and one case-type field decides it". Today a
handler opening a subsidy case is offered every party kind the fleet knows, including
the ones that make no sense for a subsidy, and nothing refuses the wrong one.

The candidate is a `must`, and the sweep marks it a **matrix hole**: a `must` for a
municipality with two or more driven passers and no row in the 225 to hold it. Two
driven passers, both Dutch case systems. xxllnc Zaken puts it in the case type editor
under Relaties (`case-type-editor-anatomy.md`), and OpenCase does the same. The
sweep's note: "Both let the case type decide which party kinds can appear on a case."

xxllnc matters here beyond the row. The depth study rates 54 case-type
configurability capabilities and scores xxllnc 36 `yes` against dossiq's 10. This is
one of the thirty-six, and it is one a demo walks straight into.

pipelinq is the registry because pipelinq owns the party. A case app holding its own
party kind list is a second vocabulary, and a schema slug is global per organisation,
so the second list collides with the first rather than sitting beside it.

## The candidate, with its lane citation

| id | capability | relevance | driven passers | lane |
|---|---|---|---|---|
| C-configuration-4 | A case type declares which kinds of party it accepts. | must, matrix hole | opencase, xxllnc-zaken | `configuration.tsv:56` |

Both passers are driven, so **decision D21**'s documented label is not needed. The
sweep read dossiq `no` on it.

## What pipelinq builds

- **A party kind registry.** `partyKind`: a code, a label, which identity shapes it
  may take (a natural person, an organisation, or neither), whether it may exist
  without an account, which field set it carries, and whether more than one may hold
  the kind on one record.
- **An acceptance declaration any app can write.** `partyKindAcceptance` binds a set
  of party kinds to a record type of a consuming app, named in the
  `<app>:<schema>:<type>` shape. The declaration is the consuming app's policy;
  the object is pipelinq's.
- **A picker that reads the declaration.** The party picker pipelinq ships offers
  only the kinds declared for the record type it was opened on, in the declared
  order, and offers everything only where no declaration exists.
- **A refusal on the write.** Linking a party to a record under a kind that record
  type does not accept is refused, naming the kind and the record type. A picker that
  hides a choice is a convenience; a refusal is the rule.
- **Cardinality.** A kind may be declared as at most one per record. A second
  `aanvrager` on one case is refused; a second `belanghebbende` is not.

## How dossiq consumes it

1. A case type declares which party kinds it accepts, in dossiq's own case type
   editor. dossiq stores its own reference to the declaration; it does not store the
   kinds.
2. Opening the party picker on a case of that type offers exactly those kinds.
3. A write attempting a kind the case type does not accept is refused by pipelinq,
   so an API caller cannot go around the picker.
4. dossiq declares no party kind of its own and ships no party kind list.

The same contract serves any consuming app. A vergunning, a subsidie and a Woo
verzoek are three record types with three declarations, and the registry does not
learn about any of them.

## The existing specs this extends

- `typed-fields-and-indicators-on-a-party`, a declared dependency. A party kind names
  a field set, and the field set is that change's.
- `unify-client-contact`. A kind declares which identity shape it takes, and the
  Nextcloud Contact stays authoritative for identity. A kind that may exist without
  an account is the shape most melders actually have.
- `client-management` and `contact-relationship-mapping` render the picker.
  Unchanged in substance.

## Size and dependencies

**Size: M.** Two schemas, one picker behaviour, one write-path refusal and one
cardinality rule.

**Depends on:** `typed-fields-and-indicators-on-a-party`, for the field set a kind
names.

## What this change does not do

- It does not build the party without an account (`C-parties-and-contacts-3`, a
  `must` and another matrix hole) or the placeholder party
  (`C-parties-and-contacts-2`). A kind declares whether it may exist without an
  account; making that party notifiable is openregister's half of cluster 14.
- It does not merge two parties (`C-parties-and-contacts-20`).
  `master-data-management` owns merging.
- It does not edit a case type. The declaration is written by the consuming app from
  its own editor, and pipelinq holds no case type.
