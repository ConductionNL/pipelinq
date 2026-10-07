# Tasks: notify-the-followers-of-tickets-and-leads

## 1. Register fragment

- [ ] 1.1 Add `lib/Settings/register.d/99-watchers-on-ticket-and-lead.json` with a `_meta` (spdx-license, spdx-copyright, a description naming this change and warning that the four recipient lists are restated in full) and, under `components.schemas`, only the `recipients` key of `ticket.x-openregister-notifications.ticketUpdated`, `lead.x-openregister-notifications.leadUpdated`, `leadWon` and `leadLost`, each the current list plus `{"watchers": true}` (see design.md, "Which rules get the watchers block") [V1]
  - Verify: `php -r` or a PHPUnit test that runs `ConfigFileLoaderService::loadConfigurationFile()` on the real files and prints the four lists; `newTicket` and `newLead` are unchanged
- [ ] 1.2 Check the filename sorts after `98-update-notifications.json` and `99-unify-ticket-supertype.json` (`ls lib/Settings/register.d | sort`) [V1]

## 2. Tests

- [ ] 2.1 Add `tests/Unit/Settings/WatcherRecipientsTest.php`: merge the real monolith and the real `register.d/` fragments through `ConfigFileLoaderService`, then assert REQ-NFTL-002 (each of the four rules keeps its earlier recipients and has exactly one `{"watchers": true}`; the two create rules have none) [V1]
  - Verify: the test fails on development before 1.1 and passes after it
- [ ] 2.2 In the same test, run each of the four rules through OpenRegister's `NotificationAnnotationValidator` (the real class; read `tests/bootstrap.php` first, which stubs only OpenRegister contracts, and load the class from an OpenRegister checkout) and assert no errors (REQ-NFTL-003); when the class cannot be loaded, mark the test skipped with that reason and let the live check 3.1 carry REQ-NFTL-003 [V1]

## 3. Live check (one pass after the queue, per test mode)

- [ ] 3.1 On an instance with OpenRegister including PR #3707: upgrade pipelinq, grep the log for `PARTIAL IMPORT` (none), read the `ticket` schema through `/api/schemas` and find the watchers block [V1]
- [ ] 3.2 As user B, `PUT /apps/openregister/api/objects/{register}/{schema}/{id}/watch` on a ticket assigned to user A; as user A change the ticket's status; user B gets "Ticket changed: <title>" in the Nextcloud notifications [V1]
  - @e2e exclude {delivery runs through OpenRegister's queue and a background job, the same timing race OpenRegister excludes in its own spec; asserted by 2.1, 2.2 and the live check}

## 4. Matrix

- [ ] 4.1 Leave `work-collaborators` at partial when this lands: users still cannot follow from the screen. Move it to built only once the nextcloud-vue Follow control is on the ticket and lead detail pages [V1]
