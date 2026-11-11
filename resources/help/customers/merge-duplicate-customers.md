---
title: Merge duplicate customers
category: customers
summary: Shows how to combine two customer records into one and what happens to their invoices and settings.
updated: 2025-04-03
---

Duplicates happen when a client is added twice, perhaps once from an import and once while creating an invoice. Merging combines the records so history and balances are in one place.

## Before you merge

Decide which record is the **primary**. The primary's name, address and settings survive. The other record, the **duplicate**, disappears after the merge. You cannot undo a merge, so check the choice twice.

## Merge steps

1. Open the customer you want to keep.
2. Choose **More > Merge with another customer**.
3. Search for and select the duplicate.
4. Review the summary, which shows the number of invoices, payments and contacts that will move.
5. Confirm.

## What moves to the primary

- All invoices, in every status, with their numbers unchanged
- Payments, credit notes, customer credit and receipts
- Recurring schedules, which keep running on their original dates
- Contacts, with duplicates by email address removed
- Activity history, marked with the merge date

## What does not change

Invoice amounts, due dates, statuses and late fees stay exactly as they were. Merging two customers does not combine overdue balances into one invoice. A Disputed badge or payment plan on an invoice also remains.

## Settings conflicts

If the two records have different payment terms, currency or tax settings, the primary's values win for new invoices. Existing invoices keep what they had. The customer-level switches **Never charge late fees** and paused reminders are taken from the primary.

## Limits

You can only merge customers in the same workspace. Merging reduces your customer count, which may help you stay under your plan limit (see [Plan limits](/help/billing/plan-limits)).

## Avoiding duplicates

Ledgerly warns when you enter an existing name or email (see [Add a customer](/help/customers/add-a-customer)). Archived records are not duplicates; see [Archive a customer](/help/customers/archive-a-customer).
