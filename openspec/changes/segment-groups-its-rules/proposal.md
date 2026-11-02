# Proposal: segment-groups-its-rules

## Why

The board PqSegmentBouwer draws a customer segment whose rules are grouped:
"Plaats is gelijk aan Zuiddrecht", and one of two Branche values, and
"Laatste contact na" a date. The root and the inner group each carry a
"Hoe de voorwaarden samengaan" choice, the inner group has "Groep
verwijderen", and every condition has a remove button.

Lane PL5-C counted 12 items on this board as missing code (R20 triage pl8,
items 2 to 7 and 12 to 17). Reading `development` (d3bba71) shows most of it
exists:

- `SegmentRuleNode.vue` already renders nested AND/OR groups, with Add
  condition, Add group, Remove group and Remove condition.
- `SegmentService` already validates and evaluates nested AND, OR and NOT
  trees, and the preview endpoint counts them.
- The capture that produced the triage added one condition and stopped, so
  live never showed a group.

What is really missing is smaller, and worth fixing before anyone builds on
the triage:

1. The combinator is a dropdown. The board draws two radio choices under a
   legend, and a caption before an inner group ("een van deze").
2. Every group offers Add group, so nesting has no floor. The board offers it
   on the root only.
3. Remove group is an icon. The board writes it out.
4. A segment stored as a bare condition, or as a list of conditions, opens
   without a group, so the editor shows no Add buttons at all.
5. `POST /api/segments` has no privilege check, while every other segment
   endpoint has one.
6. Every preview and every send loads the whole audience and walks the tree
   in PHP. OpenRegister cannot answer an OR across properties, so it cannot
   take that work over yet.
7. The rule-group editor is pipelinq-only code, while nc-vue already has a
   flat `CnConditionListEditor`. The group editor belongs next to it.

## What changes

- The editor draws the board: radio combinator, connector words, a caption
  before an inner group, a written Remove group, Add group on the root only.
- A stored `rules` that is not a group reads as one AND group, on the server
  and in the editor.
- Creating a segment takes the same privilege check as editing one.
- Counting moves into OpenRegister once it takes a condition group
  (ConductionNL/openregister change `list-query-condition-groups`, openregister#5338). Trees it
  cannot answer keep the PHP path.
- The group editor moves into nc-vue as `CnConditionGroupEditor`; pipelinq
  passes its fields and operators.

## Not changed

- The stored shape of `rules`. No migration.
- NOT groups stay valid on the server. The editor shows them but does not
  build them.
- The audience stays locked once a segment exists.

## Rollback

Revert the commits. Stored segments are untouched, since a segment is only
rewritten when someone saves it, and the rewrite is the shape the old code
already reads.
