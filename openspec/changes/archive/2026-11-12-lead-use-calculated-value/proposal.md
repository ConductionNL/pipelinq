---
kind: code
---

## Why

The lead-product-link spec asks that a value typed by hand on a lead with product lines is kept, that the lead page shows the calculated value beside it, and that one click puts the calculated value back ("Use calculated value"). The line roll-up (line-totals-roll-up) overwrote the value on every line change and kept no calculated value.

## What Changes

- `lead` gains `calculatedValue` (the sum of the product lines, written by the line roll-up) and `valueOverridden` (an `x-openregister-calculations` boolean, materialised on every save: the value differs from a calculated value above 0). Register 1.14.0 -> 1.15.0, lead 1.7.0.
- A line change always writes `calculatedValue`, and writes `value` only when it follows the lines: empty or 0, no calculated value yet, or still equal to the last calculated value.
- The lead page shows "Calculated from the lines" under the value, and the header action "Use calculated value", both only while `valueOverridden` is true. The action PATCHes the value to the calculated value.

## Impact

- A lead imported before this change gets `calculatedValue` at its next line change.
