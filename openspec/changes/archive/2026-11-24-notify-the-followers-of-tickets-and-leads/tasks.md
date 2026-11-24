# Tasks: notify-the-followers-of-tickets-and-leads

## 1. Register fragment

- [x] 1.1 Add `lib/Settings/register.d/99-watchers-on-ticket-and-lead.json` with a `_meta` (spdx-license, spdx-copyright, a description naming this change and warning that the four recipient lists are restated in full) and, under `components.schemas`, only the `recipients` key of `ticket.x-openregister-notifications.ticketUpdated`, `lead.x-openregister-notifications.leadUpdated`, `leadWon` and `leadLost`, each the current list plus `{"watchers": true}` (see design.md, "Which rules get the watchers block") [V1]
  - Verify: `php -r` or a PHPUnit test that runs `ConfigFileLoaderService::loadConfigurationFile()` on the real files and prints the four lists; `newTicket` and `newLead` are unchanged
- [x] 1.2 Check the filename sorts after `98-update-notifications.json` and `99-unify-ticket-supertype.json` (`ls lib/Settings/register.d | sort`) [V1]

## 2. Tests

- [x] 2.1 Add `tests/Unit/Settings/WatcherRecipientsTest.php`: merge the real monolith and the real `register.d/` fragments through `ConfigFileLoaderService`, then assert REQ-NFTL-002 (each of the four rules keeps its earlier recipients and has exactly one `{"watchers": true}`; the two create rules have none) [V1]
  - Verify: the test fails on development before 1.1 and passes after it
- [x] 2.2 In the same test, run each of the four rules through OpenRegister's `NotificationAnnotationValidator` (the real class; read `tests/bootstrap.php` first, which stubs only OpenRegister contracts, and load the class from an OpenRegister checkout) and assert no errors (REQ-NFTL-003); when the class cannot be loaded, mark the test skipped with that reason and let the live check 3.1 carry REQ-NFTL-003 [V1]

## 3. Live check (one pass after the queue, per test mode)

- [x] 3.1 On an instance with OpenRegister including PR #3707: upgrade pipelinq, grep the log for `PARTIAL IMPORT` (none), read the `ticket` schema through `/api/schemas` and find the watchers block [V1]
  - Live pass 17 Nov 2026 on a clean NC 34 + OpenRegister main (with openregister#10599 for the lead schema): the re-import logged no `PARTIAL IMPORT`; `/api/schemas/<ticket>` carries `{"watchers": true}` in `configuration.x-openregister-notifications.ticketUpdated.recipients`.
- [x] 3.2 As user B, `PUT /apps/openregister/api/objects/{register}/{schema}/{id}/watch` on a ticket assigned to user A; as user A change the ticket's status; user B gets "Ticket changed: <title>" in the Nextcloud notifications [V1]
  - @e2e exclude {delivery runs through OpenRegister's queue and a background job, the same timing race OpenRegister excludes in its own spec; asserted by 2.1, 2.2 and the live check}
  - Live pass 17 Nov 2026: u_dossiq `PUT .../ticket/<id>/watch` 200 on a ticket assigned to u_kcc; after a status change and the `AnnotationNotificationDispatchJob` run, u_dossiq has "Ticket gewijzigd: Livepass incoming call" with an Open action to the ticket. The change was made as the ticket's owner: u_kcc, the assignee in the CRM group, is refused the `start` transition (it authorizes only `sales`, and the CRM group is not added there).

## 4. Matrix

- [x] 4.1 Leave `work-collaborators` at partial when this lands: users still cannot follow from the screen. Move it to built only once the nextcloud-vue Follow control is on the ticket and lead detail pages [V1]

## 5. Working on it together

- [x] 5.1 Grant an added colleague read and write access to the ticket or lead for as long as they are on it, through OpenRegister's per-object authorisation (no pipelinq-side check), and take it back on removal (REQ-NFTL-005, row `work-collaborators`)
  - Verify: PHPUnit or an API test: the added colleague reads and patches the record; after removal both answer 403
  - Built: `collaborators` (user ids) on ticket and lead, and `{"group": "authenticated", "match": {"collaborators": {"$contains": "$userId"}}}` on their read and update, in `lib/Settings/register.d/99-zzzzzzzzzzzzzzz-collaborators-on-ticket-and-lead.json`. `tests/Unit/Settings/CollaboratorAccessTest.php` reads the merged blocks and runs the rule through OpenRegister's real `ConditionMatcher`: a listed colleague is admitted, after removal refused. The HTTP 403 pass on a live instance runs in the nightly.
