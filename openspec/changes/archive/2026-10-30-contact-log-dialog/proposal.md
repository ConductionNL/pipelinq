# A contact moment is logged from the ticket in its own dialog

## Why
Board PqTicketContactLoggen draws "Contact vastleggen" as its own dialog: which ticket the contact belongs to, the channel, inbound or outbound, the date and time (filled with now), the duration, the subject, the notes and the customer. Live opened the generic create form ("Aanmaken") without the channel, the notes or the customer.

## What changes
The ticket page's Log contact action opens `ContactLogDialog`. It saves a ticket of type `interaction` with `parentTicket` set to the ticket and `client` set to the ticket's customer, which is the set the page's contact-moment list already filters on.
