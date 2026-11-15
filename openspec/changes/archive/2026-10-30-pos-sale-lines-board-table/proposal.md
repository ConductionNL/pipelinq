# The sale's lines read as the board's table and keep their editor

## Why
The board PqKassa draws a sale's lines as a read table: the product in bold with its description under it, then quantity, unit price, discount, VAT and line total. The live page drew every line as a row of inputs, a Description column beside the product, unlabelled fields in the Transaction card and a More menu the board does not draw. Cashiers still need to change a line, so the editor stays.

## What changes
- A line reads as a table row and opens its inputs with a pencil; the check closes them. A new empty line opens for editing; a scanned product reads.
- The lines table drops the Description column (the description sits under the product) and takes the board's table look.
- Barcode, register and notes carry a label above the field; the selects show their own label.
- The barcode field takes the focus when the page opens, so a scan lands in it.
- The header holds only Check out: no More menu (refresh and help links).
- Check out in the Totals card is 48px at 16px, Add line 40px, the payment-closes note a bordered 8px card, the remove-customer button round.
