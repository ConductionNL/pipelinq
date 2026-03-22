# Proposal: omnichannel-registratie

## Problem

Pipelinq has no contactmoment entity or registration form. KCC agents cannot register interactions from any channel (phone, email, counter, chat, social media). No channel-specific metadata, no timer for phone calls, and no unified inbox. 54% of tenders explicitly require this.

## Solution

Implement omnichannel contact registration with:
1. **Contactmoment schema** in OpenRegister aligned to VNG Klantinteracties
2. **Unified registration form** adapting fields based on channel selection
3. **Call timer component** for phone channel duration tracking
4. **Contact moment list** with search, filter, and CSV export
5. **Activity integration** for entity timelines

## Scope

- Contactmoment schema with channel-specific metadata
- Registration form with channel adaptation (phone, email, counter, chat, social, letter)
- Call timer for phone contacts
- Auto-linking to client by context
- Contact moment list with filters
- CSV export
- Activity timeline integration

## Out of scope

- Unified inbox (V1)
- Bulk registration (V1)
- CTI integration (Enterprise)
- Nextcloud Talk integration (Enterprise)
