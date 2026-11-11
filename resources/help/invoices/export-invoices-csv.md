---
title: Export invoices to CSV
category: invoices
summary: Shows how to download your invoices as a CSV file and what columns it contains.
updated: 2025-07-21
---

Use the Export button on the Invoices page to download your invoices as a CSV file that opens in a spreadsheet or can be imported into other tools.

## Export from the Invoices page

1. Open **Invoices**.
2. Apply any filters you need: status, customer, date range or currency. The export contains exactly what the list shows.
3. Choose **Export > CSV**.

Files with up to 5,000 invoices download at once. Larger exports are prepared in the background, and Ledgerly emails you a link that stays valid for 24 hours.

## Columns

| Column | Content |
| --- | --- |
| number | Invoice number such as INV-4471 |
| customer | Customer name |
| status | draft, sent, overdue, paid or void |
| currency | Three-letter code |
| subtotal, tax, total | Amounts with two decimals |
| late_fee | Late fee added so far, 0.00 if none |
| balance | Outstanding balance including late fees |
| issued_at, due_at, paid_at | ISO 8601 dates in workspace time zone |

Amounts use a dot as decimal separator and have no thousands separator or currency symbol, which keeps them safe for spreadsheets.

## Who can export

Owners, Admins and Billers can export. Viewers can export only if the Owner enables **Allow Viewers to export** under Settings.

## Other exports

Customers can be exported from the Customers page, and payments from the Payments page, in the same way. To take a complete copy of a workspace, including attachments, use [Export your data](/help/account/export-your-data). Tax totals are in the [tax summary report](/help/taxes/tax-summary-report).

## Importing

CSV files that Ledgerly creates can be read back in for customers, but invoices cannot be imported from CSV. Field formats for other tools are in [Import and export formats](/help/integrations/import-and-export-formats).
