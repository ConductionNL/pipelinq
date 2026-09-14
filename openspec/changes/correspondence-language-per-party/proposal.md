---
kind: code
depends_on: [typed-fields-and-indicators-on-a-party]
---

# Proposal: correspondence-language-per-party

Competitor parity programme, the pending half of the gap register
(`procest/_gaps/gap-register.json` in ConductionNL/market-intelligence, v4,
2026-09-14). One row, owner pipelinq, size M.

## The row this closes

**5.17, "Preferred correspondence language per party, honoured by templates"**,
rated `no` for dossiq. Source, verbatim: `dossiq#2314, published as 5.17`.

The ledger note, verbatim:

> The party model carries no language and the email templates carry no locale.
> `document.language` exists and is an attribute of the file, not a preference of the
> person we are writing to.

The corpus batch file is
`procest/_round4/compare/proposed-rows-dossiq-2026-09-10.md`, and its table row is:

```
| proposed | in dossiq | capability | dossiq | competitors | cross-reference |
| **5.17** | 5.17 | Preferred correspondence language per party, honoured by templates | no | unread | discovery D-freescout-12 |
```

## What the competitor evidence is, and is not

This is one of the 98 rows decision D1 promoted, and no competitor has been read for
it. The corpus says so in as many words: "Every competitor column is `unread`, and
none of them is `no`. … `no` is a reading of a product somebody opened, and filling
these cells with it would fabricate thirty readings per row." So the row rests on
dossiq's own reading of dossiq, not on a vendor.

What the corpus does hold is the neighbour it cross-references, FreeScout's
`ticket-translator` module, `D-freescout-12`. The round 4 discovery file separates
the two on the record (`procest/_round4/discovery/freescout.md:207`):

> **D-freescout-12**, translating a message inside the case, sits beside 5.17 pending
> (dossiq-only) *Preferred correspondence language per party, honoured by templates*.
> 5.17 picks a template; this translates free text somebody wrote. Keep both.

This change is the first half only. Translating free text is not in scope.

## Why pipelinq

The ownership rules (`procest/_gaps/ownership-rules.md`) put the party model here:
pipelinq owns the party, the contact registry and the typed fields on it, and a case
app that carries its own copy collides on the schema slug rather than coexisting with
it. A correspondence language is a standing preference of a person, not a fact about
one case or one file, so it belongs on the party beside the other typed fields
`typed-fields-and-indicators-on-a-party` administers.

The row asks for more than a field. A preference nothing reads is the failure the
register keeps finding, so the second half of this change is the read: every outgoing
message and every generated document resolves its locale from the recipient rather
than from the sender's session.

## ADRs

- **ADR-007 (i18n)** and **ADR-025 (i18n source of truth)**: English is the source
  language and every other locale is a translation of it. A correspondence language
  selects among the locales that already exist; it does not introduce a second source.
- **ADR-057 (i18n locale parity and key hygiene)**: a locale offered to a party has to
  be one the instance can actually render, so the selectable set is derived from the
  locales present rather than typed.
- **ADR-048 (cross-app semantic references)**: the party is reached by semantic type,
  so filinq and integriq resolve the preference without naming pipelinq.
- **ADR-075 (document generation, one channel)**: generation is filinq's, so pipelinq
  publishes the preference and filinq reads it. pipelinq renders no letter.

## What pipelinq builds

- `correspondenceLanguage` on the party, a BCP 47 tag from the set of locales the
  instance ships, unset by default and never guessed from a name or an address.
- The resolution rule as a published contract: given a party, answer the language to
  write in, falling back to the instance default and saying which of the two answered.
- The preference on the party surfaces that already exist, and on the merge, so a
  merge of two parties with different preferences asks rather than picks.
- A leaf-side read so any host object showing a party shows what language we write to
  them in.

## What dossiq consumes

dossiq holds no language of its own. It resolves the recipient's language through the
party leaf when it asks filinq for a letter or integriq for a message, and shows it on
the Parties tab. To be specified in dossiq; the register's `dossiq_half` convention
makes this a placement and a store call, not a model.

## What other apps consume

- **filinq** picks the template variant for the resolved language, under ADR-075.
- **integriq** stamps the locale on an outbound message it delivers.

Neither is changed here. Both read the contract this change publishes.

## Size

M. One property, one resolver with a published contract, one leaf read, one merge
rule.

## The spec it extends

`specs/parties` in this repo, and the party model
`typed-fields-and-indicators-on-a-party` is extending in parallel. This change depends
on that one: the language is administered as one of its typed fields rather than as a
second mechanism beside them.
