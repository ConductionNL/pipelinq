# Design: segment-groups-its-rules

Lines are at pipelinq `development` d3bba71, openregister `development`
118e2b58 and nextcloud-vue `development`.

## Context

| Part | Where | Today |
|---|---|---|
| Form | `src/dialogs/SegmentFormDialog.vue` | starts a segment as `{type: 'AND', children: []}` (`:138`) and mounts `SegmentBuilder` |
| Editor | `src/components/SegmentBuilder.vue`, `SegmentRuleNode.vue` | recursive groups; combinator is an `NcSelect` (`SegmentRuleNode.vue:17`); Add group in every group (`:79`, `:525`); Remove group is an icon button (`:26`) |
| Preview | `POST /api/segments/preview`, debounced 400 ms (`SegmentBuilder.vue:63`) | validates, then counts |
| Engine | `lib/Service/SegmentService.php` | `validateNode()` (`:1538`) and `evaluateNode()` recurse over AND, OR, NOT and leaves; `nodeType()` (`:1860`) treats anything without a known `type` as a leaf |
| Count | `countMatchingEntities()` (`:994`) over `loadEntitiesForType()` (`:2092`) | `ObjectService::findAll()` with no limit, then the tree per object in PHP |
| Access | `lib/Controller/SegmentController.php` | list, show, preview, update, size, members and compliance call `ObjectOwnerAccessPolicy::isPrivileged()` |
| nc-vue | `CnConditionListEditor` | flat field, operator, value rows, all ANDed |

## D1: the data shape stays

A segment's `rules` stays the tree pipelinq already stores and seeds:

```json
{
  "type": "AND",
  "children": [
    { "field": "place", "operator": "equals", "value": "Zuiddrecht" },
    {
      "type": "OR",
      "children": [
        { "field": "industry", "operator": "equals", "value": "horeca" },
        { "field": "industry", "operator": "equals", "value": "detailhandel" }
      ]
    },
    { "field": "pipelinqLastContactAt", "operator": "after", "value": "2025-11-01" }
  ]
}
```

- The root is always a group.
- The editor builds groups one level deep: the root holds conditions and
  groups, an inner group holds conditions. That is what the board draws, and
  it is enough for "this, and one of these".
- The server keeps accepting deeper trees and NOT groups, because the seeds
  use them ("Advice customers without a product" has a NOT). It caps depth at
  5 groups, the same cap OpenRegister applies, so a request cannot recurse
  without end.
- No new property, no migration.

## D2: the editor draws the board

- Each group opens with a radio group: legend "How the conditions combine",
  choices "Match all conditions" and "Match any condition". The strings exist
  in `l10n/nl.json` already.
- Rows keep their connector: "Where" on the first, then "and" or "or".
- A row that holds an inner group shows the connector and a caption, "all of
  these" or "one of these", then the group card, indented.
- The inner group's header carries a written "Remove group" button. The root
  has none.
- Add group shows on the root only. A tree that arrives deeper than that
  (from the API or a seed) still renders every level, but offers Add group
  nowhere below the root.
- A NOT group renders as a locked row, "These conditions are kept as
  written", with Remove. The editor does not build NOT.
- An empty inner group keeps blocking the preview and save, as an
  unfinished condition does today (`tally()` in `SegmentBuilder.vue`).

## D3: a flat segment reads as one group

A stored `rules` that is not a group is normalised before anything reads it:

| Stored | Read as |
|---|---|
| a group (`type` AND, OR or NOT, any case) | itself, `type` upper-cased |
| a bare leaf `{field, operator, value}` | `{type: 'AND', children: [leaf]}` |
| a list of leaves or groups | `{type: 'AND', children: list}` |
| an object with `children` and no `type` | `{type: 'AND', children}` |
| empty or missing | `{type: 'AND', children: []}` |

The server does this in one `normaliseRules()` called by `validateRules()`,
`evaluateRules()` and the read of a stored segment. The editor does the same
in `cloneTree()`. A save writes the normalised tree. Nothing is rewritten on
read, so a segment nobody opens keeps its stored form, and both forms mean
the same audience.

A root AND of leaves, the shape every Zuiddrecht segment has, is already a
group. It opens as the root group with its rows and "Match all conditions"
chosen.

## D4: evaluation moves into OpenRegister, with a floor

pipelinq has the engine; OpenRegister has the data. Today every preview loads
the whole audience. That is fine at Zuiddrecht's size and wrong at a real
municipality's.

**OpenRegister lacks nested AND/OR.** Its property filters join with AND
(`MagicSearchHandler::applyObjectFilters()`, `lib/Db/MagicMapper/MagicSearchHandler.php:3089`),
and no parameter groups conditions across properties. The OpenRegister part
is its own change, `list-query-condition-groups`
(ConductionNL/openregister): a reserved `_where` parameter carrying the same
group shape, with leaves in OpenRegister's operator names.

pipelinq then compiles a segment for OpenRegister in a new
`SegmentQueryCompiler`:

| pipelinq operator | `_where` leaf |
|---|---|
| `equals` | `eq` |
| `notEquals` | OR(`ne`, `isnull` true), because pipelinq counts a missing value as not equal |
| `gt`, `gte`, `lt`, `lte` and their long names | the same short name |
| `before`, `after` | `lt`, `gt` on the date |
| `between` | AND(`gte`, `lte`) |
| `contains` | `like` |
| `in`, `notIn`, `containsAny` | `in`, `notIn`, `in` |
| `isNull`, `isNotNull` | `isnull` true, false |

A leaf does not compile when its field is a derived signal
(`SegmentSignalService`, for example `pipelinqLastContactAt`), a dotted
array path (`phones.kind`), or under a NOT. Then:

1. Every leaf compiles: one count query, `_limit=0`, read the total.
2. The root is AND and some children compile: send those children as
   `_where`, then evaluate the whole tree in PHP over the narrowed rows. An
   AND can only lose members by adding conditions, so the result is the same.
3. Otherwise, or when OpenRegister does not list `listQuery.where`: the PHP
   path as today.

The board's segment is case 2: Plaats and Branche go to OpenRegister, Laatste
contact is checked in PHP on the rows that are left.

The estimate cache (1 hour) and the member projection for a send stay as
they are; they read from whichever path ran. A parity test runs both paths
over the seed segments and asserts equal counts.

## D5: who may edit a segment

A segment is a saved query over the customer base. Seeing or changing one
shows who is in it.

- Create, preview, update and refresh require `isPrivileged()`: membership
  of `admin`, `sales`, or the group named in the app config `crm_group`.
- `create()` makes the same check, before the service call.
- Any privileged user may edit any segment. Segments are shared team
  assets; `createdBy` records who made it and is never taken from the
  request.
- The audience stays locked after create, as now.
- The editor needs no check of its own: the Segments page and every endpoint
  behind it already refuse non-privileged users.

## D6: the editor belongs in nc-vue

Pages are manifest config over generic nc-vue widgets. nc-vue already ships
`CnConditionListEditor` (flat rows, all ANDed). A grouped editor is the same
thing one level up, and saved views, report filters and flow conditions want
it too.

`CnConditionGroupEditor` in nc-vue:

- `v-model`: the group tree of D1.
- `fields`: `[{value, label, type, format?}]`; `operators`: a map from field
  type to `[{value, label}]`, so the host keeps its own operator names.
- `maxDepth` (default 1): how deep Add group may build.
- `errors`: messages keyed by node path (`$.children[1].children[0]`), shown
  on the row.
- Rows reuse `CnConditionListEditor`'s row (field, operator, value).
- Strings live in nc-vue's own l10n; the board's Dutch words are the target.

pipelinq's `SegmentBuilder` keeps the preview call and the estimate note and
mounts `CnConditionGroupEditor` instead of `SegmentRuleNode`, which goes.

`SegmentFormDialog` stays a registered custom dialog in the Segments page's
`form-dialog` slot, because a save must go through `POST /api/segments`, the
only path that validates rules. When nc-vue's form dialog can post to a named
endpoint and accepts a `conditionGroup` field, the dialog can become manifest
config. That is listed as a follow-up task, not part of this change.

## Risks

- **The triage reads differently after this.** Most of PL5-C's 12 items
  close when the capture builds the board's tree. Task 5.1 changes the
  capture first, so the look items are measured against the real editor.
- **`notEquals` semantics.** SQL `<>` drops NULLs; pipelinq counts them as
  not equal. The compiler's OR with `isnull` keeps the PHP meaning; the
  parity test guards it.
- **Two engines.** The PHP evaluator stays for signals and as the fallback.
  The parity test is what keeps them saying the same thing.
