# Tasks: messaging-saved-replies-and-resend

## 1. The Messages section loads its messages

- [ ] 1.1 In `src/views/messaging/MessagingConversationSection.vue:409` fetch `channelMessage` instead of `message`
  - Verify: Vitest `tests/vitest/messagingConversationSlug.spec.js` asserts every slug the section fetches is registered by `objectTypes()`; Playwright `tests/e2e/messaging-resend.spec.ts` seeds one outbound message and sees it on ContactDetail

## 2. Saved replies as records

- [ ] 2.1 Add `lib/Settings/register.d/81-saved-replies.json` with schema `savedReply` (slug, `title`, `body`, `channels`, `language`, `active`) and add it to the `pipelinq` register schema list
  - Verify: `occ openregister:import` log has no `PARTIAL IMPORT`; `npm run check:schema-l10n` exit 0
- [ ] 2.2 Register `savedReply` in `src/config/objectTypes.js`; add index page `SavedReplies` (`/saved-replies`) and a menu entry in `src/manifest.json`
  - Verify: `npm run check:manifest` exit 0; Playwright creates a saved reply on `/saved-replies` and reads it back in the list

## 3. The picker and its three hosts

- [ ] 3.1 Add `src/components/SavedReplyPicker.vue` (NcSelect with `inputLabel`), channel filter, correspondence language first, placeholder filling for `{{client.name}}`, `{{contact.name}}`, `{{ticket.title}}`, `{{agent.name}}`
  - Verify: Vitest `tests/vitest/savedReplyPicker.spec.js`: filters by channel, fills known placeholders, leaves an unknown one as written
- [ ] 3.2 Mount the picker in `SendMessageModal.vue` above the free-text body; picking fills `body`
  - Verify: Vitest mounts the modal on SMS, picks a reply, asserts `body`; the template branch shows no picker
- [ ] 3.3 Add `src/components/CustomerReplySection.vue` (`kind: 'section'`), register it, and mount it in TicketDetail `bodyWidgets` for request and complaint tickets: customer replies, answer text area, Save answer, Save and wait for the customer
  - Verify: Playwright `tests/e2e/ticket-customer-reply.spec.ts` inserts a saved reply, saves and waits, reads `customerMessage` and status `awaiting_customer`
- [ ] 3.4 Add `mail` as an optional manifest dependency; add `src/modals/WriteEmailModal.vue` (NcDialog) opened from a Write email button in `ContactChannelsSection.vue`; open Mail compose, or a `mailto:` link when Mail is not enabled
  - Verify: Vitest asserts the compose URL carries the encoded subject and body, and the `mailto:` fallback when `dependency_statuses.mail.enabled` is false

## 4. portaliq shows the answer

- [x] 4.1 Add `customerMessage` to `clientRequests` and `clientComplaints` `fields` and to the request `detail.fields` in `lib/Portal/PortalContributionProvider.php`
  - Verify: PHPUnit `tests/Unit/Portal/PortalContributionProviderTest.php` asserts the field is in both whitelists and `notes` is still not

## 5. Send again

- [ ] 5.1 Store `templateParameters` on the failed row in `WhatsAppAdapter::persistFailureAndAlert()`
  - Verify: PHPUnit on `WhatsAppAdapter` asserts the failed payload carries the parameters
- [ ] 5.2 Add `MessagingService::resend()` and route `POST /api/messaging/messages/{id}/resend` on `MessagingController` (`#[NoAdminRequired]`, `isPrivileged()`, message and contact loaded through the register RBAC, refuse non-failed, inbound or already resent rows); link the rows through `metadata.resendOf` and `metadata.resentAs`
  - Verify: PHPUnit `tests/Unit/Controller/MessagingControllerResendTest.php`: 403 when not privileged, 404 on an unreadable message, 409 on a sent row and on a resent row, 200 with the new message id on a failed row; hydra gates route-auth and no-admin-idor pass
- [ ] 5.3 Show Send again on failed and expired outbound rows in the Messages section; on `template-required` open `SendMessageModal` on WhatsApp; show the server reason on a second failure
  - Verify: Playwright `tests/e2e/messaging-resend.spec.ts` presses Send again on a seeded failed SMS against the mock provider and sees a new sent row

## 6. Text and docs

- [ ] 6.1 English source strings and Dutch translations in `l10n/`, sentence case, no em-dashes
  - Verify: `npm run test:l10n` exit 0
- [ ] 6.2 User doc `docs/Features/saved-replies.md`: make a saved reply, use it in a message, a ticket answer and an email, send a failed message again
  - Verify: docs build exit 0
