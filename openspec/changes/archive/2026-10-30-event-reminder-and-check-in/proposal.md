# An event's reminder, the check-in at the door and its results

## Why

PqEvenement draws an event page with "Herinnering versturen" and "Inchecken bij de deur" in its header, a Resultaten tab, and the registrations with a list's full toolbar. The campaign-events change left the reminder mail and the door check-in out, so live had neither, and the registrations were a plain table.

## What changes

- **Reminder.** `POST /api/campaign-events/{id}/reminder` mails everyone with a confirmed place: the event's title, when and where. The event gets `reminderSentAt`, written as the caller *before* any mail goes out, so a caller who may not change the event sends nothing (403). An event the caller may not read is 404. The answer says how many mails went out and how many registrations had no usable address.
- **Check-in.** A registration gets `checkedInAt`. "Inchecken bij de deur" opens the event's check-in list (`/campaign-events/{id}/check-in`): the confirmed registrations, "Nog niet binnen" first, a search on name or street, and one press per row ("Inchecken") that stamps the moment; "Inchecken ongedaan maken" clears it. This is configuration over the list page, with no bespoke screen.
- **Results.** The event's Resultaten tab counts who came (confirmed and checked in) and who did not, and lists who was checked in, with the time.
- **Registrations tab.** The Aanmeldingen tab uses nextcloud-vue's `index-list` widget: status lenses, the view switch, search and the pager, narrowed to the event.
- Schemas: campaignEvent 1.1.0 (`reminderSentAt`), eventRegistration 1.1.0 (`checkedInAt`), in `register.d/99-zz-event-reminder-and-check-in.json`.

## Depends on

- nextcloud-vue `index-list` widget (24t2l, feat/index-list-widget) for the Aanmeldingen tab.
