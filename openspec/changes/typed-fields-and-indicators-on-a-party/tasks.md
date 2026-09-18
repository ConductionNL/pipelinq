## 1. Typed fields on a party

- [x] 1.1 Add the `partyFieldSet` schema in a new `lib/Settings/register.d/`
      fragment: party kind, key, label, kind, required, order.
- [x] 1.2 Hold values on the relationship record `unify-client-contact` defines,
      beside the identity mirrors.
- [x] 1.3 Validate values through OpenRegister; add no app-side field engine
      (ADR-011, ADR-022).
- [x] 1.4 Manifest page for field sets, and the values on the party detail page.

## 2. The indicator vocabulary

- [x] 2.1 Add `partyIndicator`: code, label, severity, declared effects.
- [x] 2.2 Add `partyIndicatorValue`: party, indicator, `validFrom`, `validUntil`,
      source.
- [x] 2.3 Confirm a value past `validUntil` stops applying with no edit.
- [x] 2.4 Manifest pages for the vocabulary and for values on a party.

## 3. Live resolution

- [x] 3.1 Resolve indicators at read time on every party surface.
- [x] 3.2 Confirm no case, contact moment, task or message stores a copy.

## 4. The blocking question

- [x] 4.1 Answer, per party and act, whether it is blocked and by which indicator.
- [x] 4.2 Cover `blocksOutbound`, `blocksAddressPublication` and
      `requiresAcknowledgement`.
- [x] 4.3 Confirm pipelinq places no listener on another app's send path.

## 5. The organisation tree

- [x] 5.1 Add parent and a materialised path to the organisation party.
- [x] 5.2 Refuse a cycle on the write.
- [x] 5.3 Cap depth, with the cap administered.
- [x] 5.4 Move a subtree's paths in one act when a node moves.
- [x] 5.5 Carry field values per node.

## 6. Merge behaviour

- [x] 6.1 Survive field values by trust tier, retaining the loser.
- [x] 6.2 Union indicators; never pick a winner.
- [x] 6.3 Keep the merge reversible, indicators included.

## 7. The contact moment panel

- [x] 7.1 Render the party's indicators above the moments.
- [x] 7.2 Record an acknowledgement with handler and time.
- [x] 7.3 Refuse an outbound append against a blocking indicator, naming it.

## 8. The leaf

- [x] 8.1 Register the party fields and indicators leaf, and ship its bundle.
- [x] 8.2 Confirm it is not registered when pipelinq is absent.

## 9. Verification

- [x] 9.1 Unit tests for the cycle refusal, the dated value, the union-on-merge and
      the blocking answer.
- [x] 9.2 e2e coverage or a reason-bearing exclusion per scenario, per gate 19.
- [x] 9.3 Manifest validation exits 0.
