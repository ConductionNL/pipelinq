# Proposal: screens-kassa-till-board

## Why

The drawn boards are the UI canon. The till (PqKassa) and the refund editor (PqKassaRetour) were long forms under a Back button; the boards draw them in the page frame every record page has: a title with a pill and a breadcrumb, the primary action in the header, the work in cards on the left and the totals and customer in a column on the right.

## What changes

- The till: the page frame with Checkout in the header; cards for the lines (with a barcode field that adds the scanned product as a line, and a line saying what the unit prices include), the transaction settings and the customer's purchase history; a side column with the totals, the payment choice and Checkout, and the customer with the marketing consent.
- The audit log (PqKassaAudit): the chips All, Invalid signature and Not exported with counts, the filter form behind a Filter button, operator and register as columns, a count line, and a side panel with the picked entry's facts and cryptographic data. A row click shows the entry in the panel; Open and verify opens its page as the row click did.
- The customer lookup settings (PqKassaKlantzoeken): a form card (search fields, history depth as a select, Pipelinq integration, Save) beside a card that says where cashiers see it, with a link to the till. The admin settings mount the same component as their "pos-lookup" tab.
- The refund editor: the page frame with Save in the header; cards for the refund and the items to take back; a side column with the refund total and what happens after saving.

Nothing is removed: every field, the customer lookup, the client picker and the consent stay; only their place changes.

- Checkout (PqKassaAfrekenen) opens a payment dialog (`src/dialogs/PaymentCheckoutDialog.vue`, on CnDialog) that splits the amount due over loyalty points, gift cards and the rest on the till's payment method (`src/services/paymentSplit.js`). Confirming it saves and confirms the sale, redeems the gift cards as voucher tenders, records the rest and settles.

Not yet: the till does not look up the customer's loyalty account, so the points part shows only when a caller passes one (the dialog takes `loyalty`); points are not redeemed server-side until the loyalty programme has a points-as-payment step. Selling a new gift card from the dialog and the receipt preview of a new transaction are not built.
