---
kind: code
---

# Proposal: pl2-pos-sections

## Summary

Two POS surfaces become manifest configuration:

- **Cash drawer list.** CashShiftList.vue was an index page wrapped in a component for its Open shift dialog. It is a
  `type: "index"` page; Open shift is a header form that posts to `/api/pos-shifts` (the server sets the operator and the
  float time) and opens the new shift. CashShiftOpenDialog goes with it.
- **Refund totals.** PosRefundTotalsWidget recomputed the totals from the refund lines in the browser, which can drift from
  what was booked. The card is a facts card over the amounts the server stored on the refund (excl. VAT, VAT) and the
  method it went back to.

- **Booking context.** The Service and customer card was one part of BookingDetailSection, which drew a context, an
  assignments table and a timeline part; the timeline part and the combined mode were reached by no page. The context is a
  facts card that resolves the names; the assignments table stays as a small grid widget (BookingAssignments, field-list
  cannot resolve a resource name yet) and BookingDetailSection is deleted.

## Dependency

The Open shift form (an endpoint form with required fields that opens the answer) needs nextcloud-vue
`feature/pl-abstract-pl2-actions`.
