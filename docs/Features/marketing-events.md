# Marketing events

An event a campaign holds, such as a webinar or an open day, takes its
registrations from the campaign's landing page form. Pipelinq keeps the places
honest, mails each person what happened, and records who came.

## Create an event on a campaign

Open the campaign and add an event in its Events card. Give it a title, a start
and end, a venue (or tick Online and give the join link), the number of places
and, if you want, the moment registration closes. Set Registration to
Registration open when people may sign up. Zero places means no limit.

## What happens when someone signs up

A sign-up on the campaign's landing page still becomes a contact, a lead and a
touchpoint, as before. When the campaign has an event that takes registrations,
the sign-up also becomes a registration for that event:

- **A place is free:** the person is registered and gets a confirmation mail
  with a calendar file (`event.ics`) and a link to cancel.
- **The event is full:** the person goes on the waiting list and gets a mail
  saying so, with their place in line and a link to cancel. There is no
  calendar file until they have a place.
- **The same address signs up again:** nothing new is written; the person keeps
  the registration they have.
- **Registration has closed** (the closing moment has passed, or the event is
  not open): nobody is registered, and the person gets a mail saying
  registration has closed.

When the last place is taken the event shows Fully booked. When a place comes
free again it shows Registration open.

## The waiting list

When someone cancels, their place goes to the first person on the waiting list
whose party fits. That person gets a mail with the calendar file, and the
others move up one place.

## Cancelling

The cancel link in every mail opens a page with the event and a Cancel my
registration button. Only pressing the button cancels, so a mail scanner that
opens links cannot cancel anyone. The link stops working when the event starts.

## The reminder

A day before the event starts, everyone with a place gets a reminder. For an
online event the reminder carries the join link. Each person gets it once.

## Marking who came

On the event's registrations, mark each person attended or no-show. Marking
someone attended counts as an attend touchpoint of the campaign, so the
campaign report shows, per channel, how many came and not only how many signed
up. A colleague can also add someone by hand; they get the same confirmation
as a sign-up from the landing page.
