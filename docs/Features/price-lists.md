# Price lists

**Status:** Implemented (live checks pending)

## Overview

A price list holds the prices of products for a customer segment (such as public sector), for one or more countries, or for one client. When a salesperson adds a product to a lead, the server picks the price; nobody has to look it up.

Each list has a name, a currency, a validity period (valid from, valid until), the segments and countries it applies to, a priority, whether it is active, and its prices. A price names a product, optionally a variant SKU, a minimum quantity (default 1) and a unit price.

## How a line is priced

When a product line is added to a lead without a unit price, the server takes, in order:

1. the client's own price list (set on the client as **Price list**);
2. the active list valid today whose segments contain the client's segment, highest priority first;
3. the active list valid today whose countries contain the client's country, highest priority first;
4. otherwise the product's own price, with its variant and quantity tiers.

Only lists in the lead's currency count. Within a list, the price with the highest minimum quantity that is at most the ordered quantity applies. Lists of equal priority are ordered by the most recent valid-from date, then by id, so the answer is always the same.

The line shows where its price came from in the **Price from** column, with the name of the list that priced it.

## A price typed by hand

A salesperson may type a different price on a line. That price is kept and the line says **Set by hand**. Changing the quantity afterwards does not replace it. A line priced by the server is priced again when its quantity, product or variant changes.

Existing lines are not repriced when a list changes.

## Where to find it

- **Price lists** in the menu, next to Products: the list of price lists and a page per list with its prices. Prices are changed through the list's Edit form.
- **Clients**: an organisation's card shows its country and own price list; both are set through the client's Edit form.

## Out of scope

- Converting a list's prices to another currency. A lead in a currency without a list falls back to the product's own price.
- Discounts on a whole list. A list holds prices.

## Specification

- Spec: `openspec/changes/products-price-lists/specs/product-price-lists/spec.md`
- Design: `openspec/changes/products-price-lists/design.md`
- Tasks: `openspec/changes/products-price-lists/tasks.md`
