---
kind: code
screens: [PqCampagne]
---

# Proposal: campaign-page-board-cards

## Summary

COMPDIFF pl6 PqCampagne. The details card wrote the status as plain text, the dates as "21-10-2026" and the budget as
"2.500"; the board writes a green "Bezig" pill, "28 sep 2026" and "€ 2.500". Without a landing page the landing block
stood bare on the page ground with no title; the board draws it as the "Landingspagina" card. The report card wrapped
its Kosten tile to a second row, put "Toeschrijvingsmodel" above its picker, wrote the period as "2026-10-21 tot
2026-10-30" and framed the channel table at 12px; the board keeps five tiles in one row, names the model beside the
picker, writes "28 sep tot 7 okt 2026" and frames the table at 8px.

## What changes

- Manifest `campaign-data`: status as a pill in the campaigns list tones, start and end dates `date-long`, the budget
  through the new `wholeCurrency` formatter (`objectCurrency` without decimals).
- Registry: `CampaignLandingPageWidget` draws in a titled card in every state.
- CampaignReport, embedded: tiles as wide as their longest word (no 140px floor), the model named beside its picker
  (the picker keeps its accessible name), the period in short dates with the year once, the table frame 8px.
