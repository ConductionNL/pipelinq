# Design: correspondence-language-per-party

Kind: code. One property, one resolver, one leaf read, one merge rule.

## D1. `correspondenceLanguage` on the party

A property on the party record, in the same fragment shape the register uses for the
other typed fields: string, BCP 47 language tag, `facetable: true`, optional, no
default. It is administered as one of the typed fields
`typed-fields-and-indicators-on-a-party` declares, not as a second mechanism beside
them, which is why that change is a dependency rather than a neighbour.

The selectable set is not typed into the schema. It is the set of locales the instance
ships, read once and offered as the picker's options, so a preference can never name a
locale nothing can render. ADR-057 is the reason: a locale that is present in one app
and absent in another is the failure mode, and a free-text field walks straight into
it.

Unset is a real state and the default one. Guessing a language from a name, an address
or a nationality is wrong often enough to be a defect, and the row asks for a
preference the party expressed, not an inference about them.

## D2. The resolver, as a published contract

`resolveCorrespondenceLanguage(party)` answers a pair: the language tag, and which rule
answered it. Three rules in order:

1. the party's `correspondenceLanguage`, when set,
2. the instance default,
3. English, which ADR-007 makes the source language and therefore the one locale that
   always exists.

The second element of the pair is what makes this checkable and what makes a support
question answerable: "we wrote to them in Dutch because the instance default said so,
not because they asked for it" is a different fact from "they asked for Dutch".

The contract is published rather than kept private, because filinq and integriq are its
callers and neither may reach into pipelinq's storage to answer the question itself.

## D3. Who reads it

Under ADR-075 document generation is filinq's one channel, so pipelinq renders nothing.
filinq asks the resolver which template variant to render. integriq asks it which locale
to stamp on an outbound message. dossiq asks it when it requests either, and shows the
answer on the Parties tab.

Reaching the party is ADR-048's semantic reference, so no caller names pipelinq.

## D4. The merge

Two parties merging with two different preferences is a real conflict and picking one
silently is how a resident ends up receiving Dutch after asking for English. The merge
surfaces both values and requires a choice, in the same shape the survivorship engine
already uses for a conflicting attribute. Where only one side carries a preference, it
survives.

## D5. What is not here

Translating free text somebody wrote is `D-freescout-12` and stays a separate
candidate, on the record in `procest/_round4/discovery/freescout.md:207`. This change
picks among translations that already exist. It does not create one.
