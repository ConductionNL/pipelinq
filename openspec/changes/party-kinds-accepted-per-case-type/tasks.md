## 1. The registry

- [ ] 1.1 Add `partyKind` to a `lib/Settings/register.d/` fragment: code, label,
      identity shapes, field set, `maxPerRecord`, `active`.
- [ ] 1.2 Seed the kinds the fleet already uses, so nothing loses its meaning.
- [ ] 1.3 Keep an inactive kind resolving on links already written.
- [ ] 1.4 Manifest page for the registry.

## 2. The acceptance declaration

- [ ] 2.1 Add `partyKindAcceptance`: target as `<app>:<schema>:<type>`, ordered kinds.
- [ ] 2.2 Store and match the target as an opaque string; interpret nothing.
- [ ] 2.3 Confirm pipelinq holds no case type object and no editor for one.

## 3. The picker

- [ ] 3.1 Read the acceptance for the record type the picker was opened on.
- [ ] 3.2 Offer the declared kinds in the declared order, unsorted.
- [ ] 3.3 Offer every active kind where no acceptance is declared.

## 4. The write-path refusal

- [ ] 4.1 Refuse a link whose kind is not accepted, naming the kind and the type.
- [ ] 4.2 Apply it to the picker, imports, the API and flows alike.
- [ ] 4.3 Refuse nothing for a record type with no declaration.

## 5. Cardinality

- [ ] 5.1 Refuse a second link of a `maxPerRecord` 1 kind, naming the holder.
- [ ] 5.2 Accept any number for an unbounded kind.

## 6. Verification

- [ ] 6.1 Unit tests for the refusal on each write path, the permissive default and
      the cardinality rule.
- [ ] 6.2 e2e coverage or a reason-bearing exclusion per scenario, per gate 19.
- [ ] 6.3 Manifest validation exits 0.
