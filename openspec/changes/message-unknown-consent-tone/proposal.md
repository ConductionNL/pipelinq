---
kind: code
screens: [PqBerichtSturen]
---

# Proposal: message-unknown-consent-tone

## Summary

Board PqBerichtSturen draws "Toestemming voor sms: onbekend" in the warning tone and only an opt-in in green. Live drew
any consent that still allows sending (an unknown SMS consent, a WhatsApp reply inside the session window) green with a
check, so "onbekend" read as fine.

## What changes

- SendMessageModal: the consent line under the channel switch takes the ok tone and check only for `opted-in`. An
  unknown consent that still allows sending reads in the warning tone with the alert icon. Sending stays allowed, as
  before; the server still decides.
