---
title: Add tax rates
category: taxes
summary: Shows how to create tax rates in Settings and apply them to invoice lines.
updated: 2025-03-06
---

A tax rate in Ledgerly is a named percentage that you attach to invoice lines. Ledgerly calculates the tax amount for you and shows it separately on the invoice. It does not decide which rate applies to your sales; that depends on your location and what you sell, so check with a tax adviser.

## Create a tax rate

1. Go to **Settings > Taxes** and choose **Add tax rate**.
2. Enter a **Name**, such as "Sales tax" or "VAT 20%". The name is printed on the invoice.
3. Enter the **Percentage** from 0 to 100, with up to three decimals (for example 7.625).
4. Optionally enter a **Registration number**, printed under the tax total.
5. Choose whether the rate is **Active**. Inactive rates stay on old invoices but cannot be chosen for new lines.
6. Save.

## Apply a rate

In the invoice editor, pick the rate in the **Tax** column of each line. A line can carry up to two taxes (see [Multiple taxes on one line](/help/taxes/multiple-taxes-on-one-line)). To make a rate the default for new lines, tick **Default for new lines** when you create it.

Tax is calculated per line and rounded to the cent per line, then totalled. For example, a $1,200.00 service line at 7.042% gives tax of $84.50 and an invoice total of $1,284.50. Small differences from tax calculators that round at the invoice level are normal (see [Invoice total looks wrong](/help/troubleshooting/invoice-total-looks-wrong)).

## Editing a rate

Changing the percentage of a rate affects drafts and future invoices, but not sent invoices. To avoid mistakes, create a new rate for a rate change and deactivate the old one. Recurring templates keep the rate they reference, so update them as well (see [Edit a recurring template](/help/recurring/edit-a-recurring-template)).

## Where tax shows up

Taxes appear on the invoice, in the [tax summary report](/help/taxes/tax-summary-report) and in the CSV export. Late fees are not taxed (see [Tax on late fees](/help/taxes/tax-on-late-fees)).

## Customers who do not pay tax

See [Tax-exempt customers](/help/taxes/tax-exempt-customers).
