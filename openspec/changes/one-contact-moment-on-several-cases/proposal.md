---
kind: code
depends_on: [contact-moments-on-pipelinq-schema]
---

# Proposal: one-contact-moment-on-several-cases

Competitor parity programme, the pending half of the gap register
(`procest/_gaps/gap-register.json` in ConductionNL/market-intelligence, v4,
2026-09-14). One row, owner pipelinq, size M.

## The row this closes

**6.25, "One contact moment or document filed onto several cases without copying"**,
rated `no` for dossiq. Source, verbatim: `dossiq#2314, published as 6.21`.

The ledger note, verbatim:

> ContactMomentService files a contact onto one case. One phone call about three cases
> becomes three records that then drift apart.

The corpus batch file is
`procest/_round4/compare/proposed-rows-dossiq-2026-09-10.md`, and its table row is:

```
| proposed | in dossiq | capability | dossiq | competitors | cross-reference |
| **6.25** | 6.21 | One contact moment or document filed onto several cases without copying | no | unread | corpus 2.26 |
```

## What the competitor evidence is, and is not

This is one of the 98 rows decision D1 promoted. No competitor has been read for it,
and the corpus says so plainly: "Every competitor column is `unread`, and none of them
is `no`. … `no` is a reading of a product somebody opened, and filling these cells with
it would fabricate thirty readings per row." The row rests on dossiq's reading of
dossiq. Nothing here claims a vendor does this better, because nobody has looked.

The cross-reference the corpus carries is corpus row 2.26, typed links between records.
2.26 asks whether two records can be related. This row asks whether one record can be
filed in several places at once, which is the same shape from the other side, and the
corpus keeps both rather than folding one into the other.

## Why pipelinq

The ownership rules (`procest/_gaps/ownership-rules.md`) settle the contact moment on
pipelinq: "Contacts is a dossiq domain, but the `contactmoment` schema is pipelinq's,
and dossiq's `customerContact` and `kcc-werkplek` copies are the TimeEntry collision
waiting to happen. dossiq's Communication tab should read pipelinq's schema."

`contact-moments-on-pipelinq-schema` (pipelinq#1932) is the change that moves the model
here, and REQ-CMD-002 makes `ticket.caseReference` an ADR-048 semantic reference to one
case. One is the number this row is about. Widening it is a change to the model that
change just established, so it depends on it rather than competing with it.

## Why this is not a copy

Filing the same call three times is not a smaller version of the right answer, it is a
different and worse one. Three records drift: one gets a correction, one gets a
follow-up, one gets deleted, and afterwards nobody can say what was said on the phone.
The row's own words are "without copying", and that is the requirement, not a
preference about storage.

## ADRs

- **ADR-048 (cross-app semantic references)**: the references stay semantic, so
  pipelinq still names no case app.
- **ADR-051 (semantic object handoff)**: a contact moment reached from a case is handed
  over by semantic type, and a handover that resolves one reference has to resolve many
  without the caller changing shape.
- **ADR-066 (cross-app leaf registration)**: the panel already renders on a host object
  through a leaf, and the leaf's filter is where "the contact moments of this case" is
  answered.
- **ADR-058 (bounded object queries)**: a many-valued reference read back from the case
  side is a query, and it has to stay bounded rather than scanning every ticket.

## What pipelinq builds

- `caseReference` on the contact moment facet becomes many-valued: an ordered set of
  ADR-048 semantic references, at least one, with existing single values staying valid
  and reading as a set of one.
- One primary reference inside that set, so a surface that can show only one case still
  has a defined answer rather than picking the first.
- The leaf filter answers "the contact moments of this case" by membership of the set,
  not by equality, and stays bounded per ADR-058.
- Filing an existing contact moment onto a further case is an append to the set, an act
  in its own right, recorded with who did it and when. Removing one is the inverse and
  refuses to empty the set.
- The panel says, on a contact moment that is on more than one case, which other cases
  it is on, so the reader knows they are looking at a shared record before they edit it.

## What dossiq consumes

dossiq holds no contact moment. Its Communication tab reads the leaf with the new
membership filter, offers "also file this on another case", and renders the shared
marker. To be specified in dossiq. dossiq retires `customerContact` and the
`kcc-werkplek` copy under `contact-moments-on-pipelinq-schema`, which is the change
this one extends, so that retirement is not repeated here.

## The document half of the row

The row names a contact moment **or a document**. The document half is not pipelinq's:
a document on a case is a Files node in the case folder with a dossiq projection, and
the ownership rules place it on filinq and the platform rather than here. This change
closes the contact moment half and says so on the record; the document half stays open
against filinq's `case-documents-and-the-flat-list`.

## Size

M. One property widened, one migration, one filter, two acts, one marker.

## The spec it extends

`specs/contactmomenten` in this repo, the delta
`contact-moments-on-pipelinq-schema` writes. REQ-CMD-002 is the requirement this change
widens.
