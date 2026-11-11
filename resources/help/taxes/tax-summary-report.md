---
title: Tax summary report
category: taxes
summary: Describes the tax summary report, its columns and how to export it for your tax filing.
updated: 2025-10-05
---

The tax summary report adds up the tax on your invoices for a period, so you can complete a sales tax or VAT return without going through invoices one by one.

## Run the report

1. Go to **Reports > Tax summary**.
2. Choose a period: month, quarter, year or custom dates.
3. Choose the **Basis**: **Invoiced** counts invoices by issue date; **Paid** counts tax when the payment was received.
4. Select **Run report**.

Void invoices and drafts are never included. Credit notes appear as negative amounts in the period they were issued.

## Columns

| Column | Content |
| --- | --- |
| Tax rate | Name and percentage |
| Taxable sales | Net amount the tax was calculated on |
| Tax collected | Tax amount |
| Exempt | Sales to tax-exempt customers |
| Reverse charge | Net sales marked reverse charge |
| Fees, not taxed | Late fees charged in the period |

Late fees are shown for completeness only, since they are not taxed (see [Tax on late fees](/help/taxes/tax-on-late-fees)).

## Currencies

The report works in one currency at a time. On Pro and Enterprise, choose a currency from the selector; amounts in other currencies are not converted. If you file in your home currency, export each currency separately.

## Export

Choose **Export** for CSV or PDF. The CSV has one row per invoice and tax rate, which is handy for audits. The PDF is a summary with the period at the top.

## Who can run it

Owners, Admins and Billers. Viewers can see the report when the Owner allows exports (see [Team roles and permissions](/help/account/team-roles-and-permissions)).

## Accounting sync

If you use an accounting integration, the same figures are pushed there each night (see [Accounting sync](/help/integrations/accounting-sync)). Differences usually come from using a different basis.

## Related

Setting up rates: [Add tax rates](/help/taxes/add-tax-rates).
