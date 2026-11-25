# Proposal: simple-contact-dashboard

## Why

In the simple structure the app opens on the contact centre dashboard. Today
that page is a workplace: lists, a form to log a contact moment and a knowledge
search. It does not say what needs attention first. The Zuiddrecht design
(`PqDashboard.dc.html`) opens with an attention card and four numbers, then the
work that waits. The tickets list has four views without counts and no column
for who handles a ticket or when it is due.

## What changes

Only the simple structure changes, through two overlays in
`src/menu-layout.simple.json`. The full structure keeps both pages.

### The dashboard

On top, in this order:

| Card | What it shows |
| --- | --- |
| Greeting | The reader's name and the date |
| Vandaag eerst | How many of my tickets in progress are past their deadline or end today. Hidden at zero |
| Nieuw | Tickets with status new |
| Wacht op mij | My tickets in progress |
| Terugbellen | My open callback requests |
| Wacht op klant | My tickets waiting for the customer |
| Wacht op mij (list) | The same tickets as the number, longest wait first |
| Contact vandaag per kanaal | One bar, a segment per channel |
| Terugbellen (list) | My open callback requests, by deadline |
| Laatste contact | The latest contact moments |

Below them, every card the page had, moved down and otherwise untouched.

A number links to the list with the same filter, so the number and the list
agree.

### The tickets list

- Seven views, each with a count. Five show: Alle, Wacht op mij, Nieuw,
  Tickets, Klachten. Contactmomenten and Wacht op klant sit behind the overflow.
- Columns: subject, type, status as a badge, handler as an avatar, channel,
  deadline, date.
- A deadline that is today or past is red. Within two days it is amber.
- Priority and direction leave the simple list. Both stay on the ticket.

## What the design asks for that pipelinq does not have

- **Reactietijd deze week.** No ticket field holds when the first answer went
  out. The fourth number is Wacht op klant instead.
- **Open vragen as one number.** Open is a set of statuses. The count endpoint
  and the list spell a set differently, and a number that does not match its
  list is worse than no number. Each number uses one status.
- **One named ticket in the attention card.** The library's banner counts. It
  cannot name a record. The card says how many tickets are late and opens them.
- **The customer's initials and name in a row.** A ticket holds its client as a
  reference, without a name. The lists show the subject, and the avatar on the
  tickets list is the handler's.
- **How long a ticket waits, as "3 dagen", in red when long.** The lists show
  the date a ticket came in. A date rule counts days until a date, and a wait
  lies in the past, so only the deadline is coloured.
- **"Hele wachtrij" as a link on the list card.** The menu has Wachtrij, and
  the attention card links to it.
- **Laatste contact as sentences** ("Sanne reageerde op uw antwoord"). The card
  lists the latest contact moments by subject, channel and date.
- **Channel names.** A channel is free text on a ticket. The bar names the six
  the contact form offers and shows any other as it is stored.

## Impact

No schema change, no register version bump, no migration.
