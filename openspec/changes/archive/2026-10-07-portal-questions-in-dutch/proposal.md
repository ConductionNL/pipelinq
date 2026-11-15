# Portal questions in Dutch, an answer notice that says so, and a ticket list that opens on the newest

## Why

Woo round 3 (hydra woo-citizen-journey, Ruben 2026-10-02) found on the resident site and in pipelinq:

- English on the resident's screens: "My questions", "Ask a question about this dossier", "My requests",
  "Submit a request" and more. The menu heading was the app name "Pipelinq".
- Question status codes on screen: `awaiting_customer`, `in_progress`, `converted`.
- The answer notice read "<question> is bijgewerkt", portaliq's generic change notice from a change rule on
  `customerMessage`. It did not say the question was answered.
- In pipelinq, "All tickets" opened in creation order, oldest first, so a new ticket sat on the last page.
- The ticket detail printed "Answer to the customer" twice: the widget title and the section's own heading.

## What changes

- Every portal page declares a `group` (portaliq's group contract): "Vragen en contact" for a resident's own
  questions, requests and complaints, "Namens uw organisatie" for a contact person's organisation pages,
  "Afspraken en klantenkaart" for a customer. Every label a resident reads is Dutch. A collection carries the name
  of its page and the intro text drops its own heading, so the page shows one heading.
- Every status column names every ticket status in words (`columns[].valueLabels`). A converted question reads
  "Omgezet in een Woo-verzoek".
- pipelinq writes the answer notice itself (`QuestionAnsweredNotice`, from `QuestionAnsweredListener` on the ticket
  update): Dutch subject "Uw vraag is beantwoord", a link that opens the question on the site, rule key
  `pipelinq.question.answered` and a link back to the question. The change rule goes, so portaliq writes no
  generic notice for the same answer.
- The Tickets index opens on `occurredAt` descending. CustomerReplySection drops its own heading.

## Impact

- `lib/Portal/PortalContributionProvider.php`, `lib/Service/Portal/QuestionAnsweredNotice.php` (new),
  `lib/Listener/QuestionAnsweredListener.php` (new), `lib/AppInfo/Application.php`, `src/manifest.json`,
  `src/components/CustomerReplySection.vue`, l10n.
- No register change.
