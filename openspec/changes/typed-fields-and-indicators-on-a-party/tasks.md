## 1. Typed fields on a party

- [ ] 1.1 Add the `partyFieldSet` schema in a new `lib/Settings/register.d/`
      fragment: party kind, key, label, kind, required, order.
- [ ] 1.2 Hold values on the relationship record `unify-client-contact` defines,
      beside the identity mirrors.
- [ ] 1.3 Validate values through OpenRegister; add no app-side field engine
      (ADR-011, ADR-022).
- [ ] 1.4 Manifest page for field sets, and the values on the party detail page.

## 2. The indicator vocabulary

- [ ] 2.1 Add `partyIndicator`: code, label, severity, declared effects.
- [ ] 2.2 Add `partyIndicatorValue`: party, indicator, `validFrom`, `validUntil`,
      source.
- [ ] 2.3 Confirm a value past `validUntil` stops applying with no edit.
- [ ] 2.4 Manifest pages for the vocabulary and for values on a party.

## 3. Live resolution

- [ ] 3.1 Resolve indicators at read time on every party surface.
- [ ] 3.2 Confirm no case, contact moment, task or message stores a copy.

## 4. The blocking question

- [ ] 4.1 Answer, per party and act, whether it is blocked and by which indicator.
- [ ] 4.2 Cover `blocksOutbound`, `blocksAddressPublication` and
      `requiresAcknowledgement`.
- [ ] 4.3 Confirm pipelinq places no listener on another app's send path.

## 5. The organisation tree

- [ ] 5.1 Add parent and a materialised path to the organisation party.
- [ ] 5.2 Refuse a cycle on the write.
- [ ] 5.3 Cap depth, with the cap administered.
- [ ] 5.4 Move a subtree's paths in one act when a node moves.
- [ ] 5.5 Carry field values per node.

## 6. Merge behaviour

- [ ] 6.1 Survive field values by trust tier, retaining the loser.
- [ ] 6.2 Union indicators; never pick a winner.
- [ ] 6.3 Keep the merge reversible, indicators included.

## 7. The contact moment panel

- [ ] 7.1 Render the party's indicators above the moments.
- [ ] 7.2 Record an acknowledgement with handler and time.
- [ ] 7.3 Refuse an outbound append against a blocking indicator, naming it.

## 8. The leaf

- [ ] 8.1 Register the party fields and indicators leaf, and ship its bundle.
- [ ] 8.2 Confirm it is not registered when pipelinq is absent.

## 9. Verification

- [ ] 9.1 Unit tests for the cycle refusal, the dated value, the union-on-merge and
      the blocking answer.
- [ ] 9.2 e2e coverage or a reason-bearing exclusion per scenario, per gate 19.
- [ ] 9.3 Manifest validation exits 0.
