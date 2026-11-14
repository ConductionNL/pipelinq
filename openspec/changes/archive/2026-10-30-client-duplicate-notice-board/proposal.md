# Proposal: client-duplicate-notice-board

## Why

pl4 on board PqKlantNieuw: the new-client dialog's duplicate warning drew as a borderless tint 10px in, with no icon,
where the board draws a bordered warning notice 12px in with a warning icon before its title. The notes field was 76px
tall where the board draws it 89.5px.

## What changes

- The duplicate warning gets the board's notice ground and a 1px border, 12px 14px padding, and a 20px warning icon
  before the title; the two links stay under the title.
- The board layout's notes field is at least 89.5px tall.
