---
title: Line items and discounts
category: invoices
summary: Explains how to add line items, apply percentage or fixed discounts and how totals are calculated.
updated: 2025-03-27
---

Each invoice is built from line items. Every line has a description, a quantity, a unit price and optionally a tax rate and discount.

## Adding line items

1. In the invoice editor, choose **Add line**.
2. Fill in **Description** (up to 500 characters), **Quantity** and **Unit price**.
3. Pick a **Tax rate** if the line is taxable (see [Add tax rates](/help/taxes/add-tax-rates)).
4. Repeat for each line. Drag lines by the handle to reorder them.

Quantities can have up to three decimals, which is useful for hours (1.25 hours) and weights. Unit prices accept up to four decimals before the line total is rounded to two. An invoice can have up to 200 lines.

Save frequently used lines as **Saved items** from the line menu, then insert them in other invoices.

## Discounts

You can discount a single line or the whole invoice.

- **Line discount**: type a percentage or a fixed amount in the Discount column. The discount is applied before tax on that line.
- **Invoice discount**: choose **Add discount** under the totals and enter a percentage or fixed amount. It is spread across all taxable lines proportionally so tax is still correct.

A discount can never push a line or an invoice below zero. Use a [credit note](/help/invoices/credit-notes) if you need a negative amount.

## How totals work

Subtotal is the sum of line amounts after line discounts. The invoice discount is subtracted next. Tax is calculated per line and rounded to the cent per line, then summed. Late fees, if any, are added at the end and are not taxed. For example, an invoice with a $1,200.00 subtotal and tax of $84.50 has a total of $1,284.50.

If the totals look off by a cent, read [Invoice total looks wrong](/help/troubleshooting/invoice-total-looks-wrong).

## Tax-inclusive prices

If your prices already contain tax, switch on **Tax inclusive** and Ledgerly works backwards. See [Tax-inclusive pricing](/help/taxes/tax-inclusive-pricing).
