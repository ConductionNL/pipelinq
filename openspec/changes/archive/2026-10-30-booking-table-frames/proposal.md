# Proposal: booking-table-frames

## Summary

Boards PqResource (Werktijden, Vakanties) and PqService (Opbouw in stappen) draw a table inside a card as a flat frame: a hairline border, 8px corners and no shadow. On :8080 (30 Oct) both resource tables are raised cards inside the card, 12px corners and the library table.css shadow, because the scoped rule left the shadow standing and took the board look's 12px large radius; ServiceDetail set the shadow itself. Both views now draw the frame flat with 8px corners.

## Scope

- `src/views/bookings/ResourceDetail.vue`, `src/views/bookings/ServiceDetail.vue`: the scoped `.viewTableContainer` rule.
