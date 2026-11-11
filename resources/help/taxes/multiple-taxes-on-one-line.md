---
title: Multiple taxes on one line
category: taxes
summary: Explains how to apply two taxes to a single invoice line and whether the second is calculated on top of the first.
updated: 2024-07-24
---

Some regions charge two separate taxes on the same sale, for example a state tax and a city tax, or a federal tax and a provincial tax. Ledgerly lets you put up to two taxes on each line.

## Add a second tax

1. In the invoice editor, open the **Tax** cell of a line.
2. Choose the first tax rate.
3. Choose **Add second tax** and pick another rate.

Both taxes appear separately in the totals, each with its own name and amount. The printed invoice lists them in the tax summary.

## Simple or compound

By default, both taxes are calculated on the same net amount. For a $100.00 line with a 5% tax and a 7% tax, you get $5.00 and $7.00, so the total is $112.00.

If the second tax applies on top of the first, edit the second rate under **Settings > Taxes** and switch on **Compound**. The second tax is then calculated on the net amount plus the first tax: 5% gives $5.00, then 7% of $105.00 gives $7.35, for a total of $112.35.

Compound is a property of the rate, so every line that uses that rate as the second tax is compounded.

## Rounding

Each tax on each line is rounded to the cent separately. The invoice total is the sum of the rounded amounts. See [Add tax rates](/help/taxes/add-tax-rates).

## Limits

- At most two taxes on a line. If you need more, combine them into one rate with a combined percentage and a descriptive name.
- Both taxes must be in the same mode, either inclusive or exclusive (see [Tax-inclusive pricing](/help/taxes/tax-inclusive-pricing)).

## Reports

The [tax summary report](/help/taxes/tax-summary-report) lists each tax separately, so you can file each one.

## Exemptions

A tax-exempt customer's lines get no taxes by default (see [Tax-exempt customers](/help/taxes/tax-exempt-customers)).
