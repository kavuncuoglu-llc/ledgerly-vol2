---
title: Import and export formats
category: integrations
summary: Documents the file formats, encodings and column conventions Ledgerly uses for CSV imports and exports.
updated: 2024-11-20
---

This page lists the technical conventions for files that go in and out of Ledgerly. For step-by-step instructions see [Import customers](/help/customers/import-customers) and [Export invoices to CSV](/help/invoices/export-invoices-csv).

## General rules

- Encoding: UTF-8, with or without a byte order mark
- Delimiter: comma. Fields with commas or line breaks are wrapped in double quotes
- Line endings: LF or CRLF
- First row: column names in lowercase with underscores
- Dates: ISO 8601, `2026-04-02` for dates and `2026-04-02T00:00:00Z` for timestamps
- Amounts: dot as decimal separator, no thousands separator and no currency symbol, for example `1284.50`
- Currencies: three-letter codes such as USD, EUR and GBP
- Booleans: `true` or `false`

## Import limits

Only customers can be imported by CSV. A file can hold 5,000 rows and 10 MB. Invoices and payments cannot be imported; use the API if you need to migrate history (see [REST API overview](/help/integrations/rest-api-overview)).

## Status values

Exports use lowercase status values: `draft`, `sent`, `overdue`, `paid` and `void`. Disputed and Partial are not statuses and show up as separate columns, `disputed` and `partial`, containing `true` or `false` (see [Invoice statuses](/help/invoices/statuses)).

## Payment terms values

`due_on_receipt`, `net_7`, `net_14`, `net_30`, `net_45`, `net_60` and `custom_N` where N is the number of days (1 to 120). Imports accept the same values (see [Payment terms](/help/invoices/payment-terms)).

## Late fee columns

Invoice exports contain `late_fee` (total added so far) and `late_fee_waived` (true or false). Imports of customers accept `never_charge_late_fees` (true or false), which matches the customer switch in [Late fee settings](/help/billing/late-fee-settings).

## Opening in a spreadsheet

Open the CSV with an import wizard and choose UTF-8, otherwise accented characters may be damaged. Format ID columns such as postal codes as text to preserve leading zeros.

## Size of exports

Exports with more than 5,000 rows are prepared in the background and emailed. Full workspace exports are described in [Export your data](/help/account/export-your-data).
