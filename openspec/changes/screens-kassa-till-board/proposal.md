# Proposal: screens-kassa-till-board

## Why

The drawn boards are the UI canon. The till (PqKassa) and the refund editor (PqKassaRetour) were long forms under a Back button; the boards draw them in the page frame every record page has: a title with a pill and a breadcrumb, the primary action in the header, the work in cards on the left and the totals and customer in a column on the right.

## What changes

- The till: the page frame with Checkout in the header; cards for the lines (with a barcode field that adds the scanned product as a line, and a line saying what the unit prices include), the transaction settings and the customer's purchase history; a side column with the totals, the payment choice and Checkout, and the customer with the marketing consent.
- The refund editor: the page frame with Save in the header; cards for the refund and the items to take back; a side column with the refund total and what happens after saving.

Nothing is removed: every field, the customer lookup, the client picker and the consent stay; only their place changes.

Not yet: the checkout dialog of PqKassaAfrekenen (split payment over loyalty points, a gift card and the card terminal) and the receipt preview of a new transaction, which the server renders only for a saved one.
