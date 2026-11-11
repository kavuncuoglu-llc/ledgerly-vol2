---
title: Export your data
category: account
summary: Shows how to download a complete copy of your workspace data, including invoices, customers, payments and attachments.
updated: 2025-09-26
---

You can download everything in a workspace at any time. It is useful for backups, switching tools, or before closing the workspace.

## Request a full export

1. Go to **Settings > Data > Export**.
2. Choose what to include: customers, invoices, payments, credit notes, tax rates, recurring schedules and attachments.
3. Select **Request export**.

Ledgerly prepares a ZIP file and emails a download link to the Owner. Small workspaces take a few minutes, and large ones up to an hour. The link works for 24 hours, after which you can request another. Only Owners and Admins can request exports.

## What is inside

| File | Content |
| --- | --- |
| customers.csv | Customers and contacts |
| invoices.csv | One row per invoice (columns as in [Export invoices to CSV](/help/invoices/export-invoices-csv)) |
| line_items.csv | One row per invoice line |
| payments.csv | All payments and refunds |
| credit_notes.csv | Credit notes |
| tax_rates.csv | Your tax rates |
| attachments/ | Files attached to invoices and customers |
| pdf/ | PDF of every invoice, receipt and credit note |

Amounts use a dot as a decimal separator and no thousands separator. Dates use ISO 8601.

## Late fees and reminders in the export

Each invoice row includes the late fee added so far. The history of reminders is not included in the export. If you need it for a dispute, copy it from the invoice's Activity tab.

## Exporting a subset

For a filtered export of just invoices, customers or payments, use the Export button on those pages (see [Export invoices to CSV](/help/invoices/export-invoices-csv)).

## Moving to another tool

The CSV layouts are described in [Import and export formats](/help/integrations/import-and-export-formats).

## Retention

Export a workspace before it is deleted. After closing, data is available for 90 days (see [Close your account](/help/account/close-your-account)).

## Privacy requests

If you need an export of your own personal data as a user, contact support (see [Data retention](/help/security/data-retention)).
