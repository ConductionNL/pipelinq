# Proposal: werkplek-in-call-column-weights

## Why

Board PqWerkplekKlant draws the in-call columns 332 / 415 / 332 wide; the page drew three equal shares of 360, so every card in the three columns was off in x and width (PL5-C pl8).

## What changes

The in-call `layoutWhen` entry weighs its three groups of four columns 352 / 435 / 352 (each card plus its two 10px grid margins), using nextcloud-vue's `gridColumnWeights` (dashboard-column-weights). The page's own layout outside a call keeps the twelve shares. Config only, no PHP, no strings. Before the nextcloud-vue change the key is ignored and the columns stay equal.
