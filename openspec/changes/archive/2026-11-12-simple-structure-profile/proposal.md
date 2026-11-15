# Proposal: simple-structure-profile

## Why

Pipelinq serves a contact centre and a sales team from one app. Today the menu
shows both at once: 47 entries, of which a municipal contact centre uses nine.
The Zuiddrecht design (`Vereenvoudiging.dc.html`, `AppZijbalk.dc.html`) puts
daily work in the menu and everything else one step further.

Ruben's decisions (5 October 2026):

- The simple structure is the new default.
- The full structure returns with the admin setting `menu_structure=full`.
- Nothing is deleted. Every page stays reachable.
- Regrouping never changes who may do what. Every page and action keeps its own check.

## What changes

One manifest, two structures. `src/menu-layout.json` stays the full structure,
untouched. `src/menu-layout.simple.json` is the simple one. The mechanism is the
one the dossiq pilot built (dossiq `simple-structure-profile`), copied as is.

The simple menu:

| Caption | Entries |
| --- | --- |
| Start | Dashboard, Mijn werk, Wachtrij |
| Klantcontact | Vragen en meldingen, Contactmomenten, Afspraken |
| Relaties | Inwoners en bedrijven, Organisaties |
| Meer | Rapportages |

How each entry maps to what pipelinq has:

| Entry | Opens |
| --- | --- |
| Dashboard | `KccWerkplek`, the contact centre dashboard |
| Mijn werk | `MyWork` |
| Wachtrij | `Queue` |
| Vragen en meldingen | `Tickets`, every ticket |
| Contactmomenten | `Tickets` narrowed to `ticketType=interaction` |
| Afspraken | `Bookings` |
| Inwoners en bedrijven | `Clients`, every client |
| Organisaties | `Clients` narrowed to `type=organization` |
| Rapportages | `Reports`, the card page |

Two additions pipelinq needs and dossiq did not:

1. **Modules.** Sales, Marketing, Point of sale, Products, Contracts and Loyalty
   are modules. They are out of the simple menu until an administrator names
   them in the setting `menu_modules`. A new page, Modules (`/modules`), holds a
   card for every page of every module, so a module that is off is one card
   away. The footer entry "Modules and more" opens it.
2. **A start page.** The app opens on `/`, and `/` is the Sales overview. In the
   simple structure `/` redirects to the contact centre dashboard, and the
   Sales overview moves to `/sales-overview`.

3. **The first-visit tour.** The getting-started tour walks a sales journey
   through menu entries the simple menu does not have. It is held back in the
   simple structure and unchanged in the full one. The contact centre has no
   tour of its own yet.

Rapportages is one entry. The Reports page already carries cards for Reporting,
Contact reporting, Channel analytics and Agent performance. The simple
structure adds a card for SLA attainment.

Services and Resources (what can be booked, and with whom) move to settings.

## What does not change

- The full structure. A spec asserts it equals what `buildManifest` made before.
- The page list: 97 pages in both structures, 96 of today plus Modules.
- Permissions. A card or a menu entry is a link. The page it opens decides who
  may see it, as before.

## What the design asks for that pipelinq does not have

- **Organisaties as its own list.** Pipelinq has one client list with a `type`
  of `person` or `organization`. Organisaties is that list narrowed to
  organisations, so it is a subset of Inwoners en bedrijven, not a second
  register.
- **A badge with a count on Mijn werk and Wachtrij.** The library's navigation
  entry has no counter that this change could feed. Left out.
- **A "Nieuw contact" primary button in the sidebar** and the links
  "Instellingen" and "Hulp en uitleg" as the design draws them. The library
  renders its own settings foldout and footer. Left as they are.
- **A menu per role.** The design says "9 menu entries per role". This change
  ships one simple menu, the contact centre's, plus module switches for the
  whole instance. A menu that follows the reader's role needs a rule for what a
  role is in pipelinq, which nobody has decided.

## What the library cannot express

Reported by the dossiq pilot, and the same here:

- order and label overrides per layout (worked around with the `menu` key);
- captions surviving a relocation step (so the simple file has no `relocations`);
- an active state that respects `query`: Vragen en meldingen and Contactmomenten
  light up together, and so do Inwoners en bedrijven and Organisaties;
- a help menu in the navigation.

New from pipelinq:

- a layout cannot name the start page of an app (worked around in `main.js`);
- a menu entry inside a group cannot be given a top-level twin by id, so
  Afspraken is a new entry on the Bookings route rather than the Bookings entry
  moved up.

## Impact

Every instance flips to the simple menu on update. To go back: admin settings,
Menu structure, Full. Or `occ config:app:set pipelinq menu_structure --value=full`.

The library moves from the exact pin `2.56.0-beta.5` to `^2.61.0`. The pin was
set on 1 October (`02ec94910`, then `d547db8af`) to get nextcloud-vue#1297
(table rows as real links, `cnSectionContext.setObject`) before it reached a
stable release. 2.60.0 contains both, and 2.61.0 adds the `links` page type
the Modules page uses.
