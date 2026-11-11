---
title: Tax-inclusive pricing
category: taxes
summary: Explains how to enter prices that already include tax and how Ledgerly works out the net amount and the tax.
updated: 2024-10-15
---

Some businesses quote prices that already include tax, which is common for consumer sales. Ledgerly supports this with a per-invoice switch.

## Turn it on

In the invoice editor, switch on **Tax inclusive** near the totals. The unit prices you enter are then treated as gross amounts. You can turn it on as the default under **Settings > Taxes > Defaults**; an invoice can still be changed individually while it is a draft.

## How the calculation changes

With tax exclusive pricing (the default), tax is added to the price: 100.00 at 20% gives 100.00 + 20.00 = 120.00.

With tax inclusive pricing, tax is taken out of the price: 120.00 at 20% includes tax of 20.00, because 120.00 divided by 1.20 is 100.00. The total stays 120.00.

| Mode | Unit price | Rate | Net | Tax | Total |
| --- | --- | --- | --- | --- | --- |
| Exclusive | 100.00 | 20% | 100.00 | 20.00 | 120.00 |
| Inclusive | 120.00 | 20% | 100.00 | 20.00 | 120.00 |

Rounding happens per line, as described in [Add tax rates](/help/taxes/add-tax-rates). On inclusive invoices the total is the number you typed, and the tax is derived from it.

## What the client sees

The invoice shows each line at the gross price, with a summary such as "Total includes VAT 20% of 20.00". The status labels and due date work the same.

## Restrictions

- You cannot switch the mode once a payment or credit note exists.
- All lines of one invoice use the same mode.
- Discounts reduce the gross price.

## Late fees

Late fees are never part of tax-inclusive or exclusive calculations because they are not taxed (see [Tax on late fees](/help/taxes/tax-on-late-fees)).

## Related

If your clients are businesses in another country, the reverse charge may apply instead (see [VAT reverse charge](/help/taxes/vat-reverse-charge)).
