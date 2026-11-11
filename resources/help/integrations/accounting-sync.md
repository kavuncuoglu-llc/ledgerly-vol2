---
title: Accounting sync
category: integrations
summary: Describes the BookBridge connection that sends invoices, payments and tax to your accounting software.
updated: 2025-05-12
---

BookBridge is the accounting integration for Ledgerly. It copies your invoices, payments and credit notes into your accounting ledger each night so you do not need to enter them twice. It is available on Pro and Enterprise.

## Connect

1. Go to **Settings > Integrations > BookBridge** and choose **Connect**.
2. Sign in to BookBridge and approve access.
3. Map your Ledgerly tax rates to BookBridge tax codes.
4. Choose the account for revenue, accounts receivable and late fees.
5. Select a **Start date**. Only invoices issued from that date are synced.

Only Owners and Admins can connect the integration.

## What is synced

| Ledgerly | In BookBridge |
| --- | --- |
| Customer | Contact |
| Invoice (Sent, Overdue, Paid) | Sales invoice |
| Payment | Payment applied to the invoice |
| Credit note | Credit note |
| Late fee | Separate line posted to the late fee account |
| Void invoice | Voided invoice |

Draft invoices are not synced. Late fees are posted to the account you chose in step 4 and are not taxed, in line with [Tax on late fees](/help/taxes/tax-on-late-fees).

Syncing runs every night at 01:00 workspace time. You can also choose **Sync now**. The log shows each record with a status: Synced, Skipped or Failed.

## Common problems

- **Failed: tax code not mapped.** Map the Ledgerly rate in step 3.
- **Failed: period closed.** BookBridge rejects entries in a locked period. Open the period or move the start date.
- **Totals differ by a cent.** BookBridge rounds tax per invoice while Ledgerly rounds per line. The sync posts a rounding adjustment line.

## Two-way behavior

Syncing is one-way, from Ledgerly to BookBridge. Changes made in BookBridge are not copied back, with one exception: payments recorded in BookBridge against a synced invoice are imported and mark the invoice as paid.

## Disconnect

Choose **Disconnect** at any time. Records already synced stay in BookBridge.

For direct reconciliation with your bank, see [Bank feeds](/help/integrations/bank-feeds).
