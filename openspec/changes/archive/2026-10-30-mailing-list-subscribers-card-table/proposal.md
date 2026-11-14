# Proposal: mailing-list-subscribers-card-table

## Why

pl4 on board PqMailinglijst: the Subscribers table drew every head as two 34px boxed buttons on a grey band (Nextcloud's
`button` rule and the theme's table-header tokens), so the table grew to 908px in a 800px card and the Bevestigd column
sat past the card's edge. The section also printed a second "Subscribers" heading above its own card, and the list
details ran in three columns where the board draws two.

## What changes

- The Subscribers table runs to the card's edges, its head on the card's ground: a sort head is its bare 17px label, the
  filter funnel a 22px button with a 4px radius, the first column 18px in, and a long cell wraps.
- The body section has no title of its own; the card carries "Subscribers".
- The list details keep the two columns the manifest declares (`fixedColumns`).
