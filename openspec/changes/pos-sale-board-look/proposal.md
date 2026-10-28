# The sale at the cash register reads as the board

## Why
The new-sale page (PqKassa) and its check-out (PqKassaAfrekenen) were a bare form: a Back to list button, a row of fields, a lines table and a Checkout button. The board draws a record page with the lines, the transaction and the customer's purchase history beside the totals, the customer and a receipt preview, and checks out through a Payment received dialog that splits the payment over loyalty points, gift cards and the rest.

## What changes
- PosTransactionForm becomes a board-look record page; every existing control stays (lines, register, price display, payment method, client for on-account sales, notes, customer with consent, payment provider method).
- A barcode adds the product it finds (the existing barcode lookup), or one more of it.
- Check out opens PosPaymentDialog: points, gift cards (add, check balance) and the rest; the parts must add up. A gift card can be sold onto the sale.
- New endpoint POST /api/loyalty/accounts/{accountId}/pay spends points as a payment part (LoyaltyPaymentService).
- Every part is booked as a tender under the tender type set up for it; a LOYALTY tender type is seeded.
- Park stores the sale unconfirmed and parks it.
