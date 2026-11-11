---
title: Import customers
category: customers
summary: Explains how to import customers from a CSV file, with the required columns and limits.
updated: 2024-11-04
---

If your customers are in a spreadsheet or another invoicing tool, you can import them into Ledgerly in a single CSV file.

## Prepare the file

The file must be UTF-8 CSV with a header row. Ledgerly accepts up to 5,000 rows and 10 MB per import.

| Column | Required | Example |
| --- | --- | --- |
| name | Yes | Acme Studios |
| email | Yes | billing@example.com |
| address_line1 | No | 12 Harbor Street |
| city | No | Portland |
| postal_code | No | 97201 |
| country | No | US (two-letter code) |
| payment_terms | No | net_30 |
| currency | No | USD |
| tax_id | No | Free text |

Download a template from the import screen to get the exact column names. Unknown columns are ignored.

## Run the import

1. Go to **Customers > Import**.
2. Choose your file.
3. Map columns if your headers differ from the template.
4. Review the preview, which highlights rows with errors.
5. Choose **Import**.

Rows with errors are skipped and listed in a downloadable report, so you can fix and re-import only those rows.

## Duplicates

Rows are matched by email. If a customer with the same email exists, you choose to **Skip** or **Update** existing records. Matching names with different emails create separate customers (see [Merge duplicate customers](/help/customers/merge-duplicate-customers)).

## Limits

The import stops if it would pass your plan's customer limit, which is 25 on Starter and 500 on Pro. Nothing is partially imported. Either raise the limit (see [Plan limits](/help/billing/plan-limits)) or trim the file.

## What is not imported

Invoices, payments and contacts beyond the first. Add extra contacts afterwards (see [Customer contacts and CC](/help/customers/customer-contacts-and-cc)).

## Other formats

Field formats for other kinds of files are listed in [Import and export formats](/help/integrations/import-and-export-formats).
