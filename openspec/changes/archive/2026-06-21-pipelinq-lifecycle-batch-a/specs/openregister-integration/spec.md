# OpenRegister Integration — Declarative Lifecycle State Machines Delta

**Spec refs**: `openregister-integration`, ADR-031 (declarative-first), ADR-022 (apps consume OR abstractions)
**Standards**: OpenRegister `x-openregister-lifecycle` annotation + `LifecycleValidationListener` enforcement contract

## ADDED Requirements

### Requirement: Declarative Object Lifecycle State Machines

Backend services that gate object `status` transitions MUST declare the transition graph in the
schema's `configuration.x-openregister-lifecycle` annotation (ADR-031), rather than maintaining a
hardcoded PHP adjacency map as the source of truth. OpenRegister's `LifecycleValidationListener`
enforces the declared graph automatically on every `ObjectService::saveObject()`.

A service MAY keep a thin PHP transition guard, but that guard MUST derive its allowed-transition
set from the schema declaration (not from a duplicate hardcoded constant). A guard is retained ONLY
to preserve an existing error contract (specific exception type, message, or HTTP status) or to
validate before the object reaches `saveObject()`.

A predicate MAY remain in a PHP guard **only** when the declarative lifecycle grammar cannot express
it — namely cross-object business invariants such as date-range coherence, "at least one related
rule exists", or "at least one redemption option exists". Such a predicate MUST carry an inline
comment stating why it stays in PHP.

**Feature tier**: MVP

#### Scenario: Callback task transitions are sourced from the schema

- GIVEN the Task schema declares `x-openregister-lifecycle` (open→in_behandeling→afgerond/verlopen, reopen)
- WHEN `CallbackService::validateStatusTransition('open', 'in_behandeling')` is called
- THEN it MUST return valid, having read the allowed set from the schema declaration
- AND `validateStatusTransition('open', 'afgerond')` MUST return invalid with a "not allowed" reason
- AND the controller MUST still surface an illegal transition as HTTP 400

#### Scenario: Walk-in ticket lifecycle is declared and enforced

- GIVEN the walkInTicket schema declares `x-openregister-lifecycle` (waiting→called/abandoned, called→served/abandoned; served/abandoned terminal)
- WHEN `WalkInQueueService::assertTransitionAllowed('waiting', 'called')` is called
- THEN it MUST pass, having derived the graph from the schema declaration
- AND `assertTransitionAllowed('served', 'called')` MUST throw `InvalidArgumentException` with the existing message
- AND a save that flips the ticket status to a value no transition allows MUST be rejected by OpenRegister's listener

#### Scenario: Loyalty activation moves the graph edge to the schema but keeps business guards in PHP

- GIVEN the loyaltyProgramme schema declares `x-openregister-lifecycle` including concept→actief
- WHEN `LoyaltyProgrammeService::activate()` is called on a `concept` programme whose business guards pass
- THEN the concept→actief edge MUST be validated against the schema declaration
- AND the activation MUST succeed and persist `status = actief`
- WHEN activation is attempted on a programme that fails a business guard (no rules, no redemption options, or incoherent dates)
- THEN it MUST still raise the existing `RuntimeException` with the same message, because that guard cannot be expressed declaratively
