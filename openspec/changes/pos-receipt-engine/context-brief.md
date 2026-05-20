---
status: draft
---

# POS Receipt Engine (print + email)

## Purpose

Twig/Jinja template; ESC/POS thermal print; email via pipelinq mailer; legal NL invoice format > EUR 100.

## Priority & Demand

- **Priority:** P0-must
- **Demand evidence:** 13/13 competitors
- **Dependencies:** pos-transaction-core

## Cross-app integration

Receipt = source for shillinq invoice when 'factuur' tender chosen.

## Competitor Evidence (from intelligence-db)

- chromis-pos :: Customisable receipt + label printer :: XML-based ticket template; JasperReports
- dvi-salonsoftware :: Bonnenprinter + email bonnetje :: ESC/POS thermal; mailbon optie
- erpnext-pos :: Print Format Builder (HTML/Jinja) + email :: Per-POS-profile custom receipt template
- korona-cloud :: Receipt customisation + email + reprint :: Per-store template; reprint last; gift receipt
- lightspeed-retail :: Customisable receipt template + email :: HTML/Liquid templates; cc to multiple addresses
- mews-pos :: Receipt + invoice with EU compliance :: Tax invoice with BTW lines; reprint
- odoo-pos :: Kitchen / preparation display + printer routing :: F&B printer routing by product category
- odoo-pos :: Receipt template editor + email + reprint :: Customisable header/footer; reprint last; email
- salonized :: Receipt printer (Star/Epson) + email bonnetje :: Thermal printer support; email receipt option
- salonkee-pos :: Receipt printer + email bonnetje :: ESC/POS thermal + email; legal invoice format
- shopify-pos :: Printed + emailed receipts; receipt templates :: Customisable receipt header/footer; reprint
- square-pos :: Email + SMS receipts :: Digital receipt, paper from impact/thermal printer
- toast-pos :: Kitchen display + printer routing :: KDS, ticket printers per station
- unicenta-opos :: ESC/POS receipt printer + receipt designer :: JasperReports-based template editor

## Notes

This spec was triaged from market intelligence research dated 2026-05-20 covering 14 competitor implementations. See `/tmp/pipelinq-research-gap-report.md` for full landscape, feature coverage matrix, and risk analysis.
