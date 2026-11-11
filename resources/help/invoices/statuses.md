---
title: Invoice statuses
category: invoices
summary: Lists the five invoice statuses, what each one means and what moves an invoice from one to the next.
updated: 2025-10-02
---

Every invoice in Ledgerly has exactly one status, shown as a coloured label in the Invoices list and at the top of the invoice.

## Overview

| Status | Meaning |
| --- | --- |
| Draft | Created but not sent. You can edit or delete it freely. |
| Sent | Delivered to the client with a balance that is not yet due. |
| Overdue | The due date has passed and a balance remains. |
| Paid | The balance is zero. |
| Void | Cancelled. It stays on record but no longer counts as owed. |

Some extra information appears as small badges next to the status rather than as a status of its own: **Partial** (some money received), **Disputed**, **Payment plan** and **Viewed**. A Partial invoice is still Sent or Overdue until the balance is zero.

Statuses change in these ways:

- Draft becomes Sent when you send it, or when a recurring schedule with auto-send creates it.
- Sent becomes Overdue automatically the day after the due date.
- Sent or Overdue becomes Paid when payments or credit notes bring the balance to zero.
- Draft, Sent or Overdue can be voided. A Paid invoice cannot; refund it or issue a credit note instead.

## Overdue

An invoice turns Overdue at 00:00 in your workspace time zone on the day after its due date, as long as some balance remains. An invoice due on April 2 is Overdue from April 3. This happens even if you never sent a reminder.

Overdue does not mean a late fee has been charged. Reminders begin 3 days past due, and the late fee is applied 30 days past due, unless the invoice is Disputed or on a payment plan. For the full rules read [Late fees](/help/billing/late-fees) and [Payment reminders](/help/billing/reminders).

If you move the due date into the future, the invoice goes back to Sent. If the client pays, it moves to Paid and the late fee stops growing.

To work out why a particular invoice shows as late, see [Why an invoice is marked overdue](/help/invoices/why-invoice-marked-overdue).

## Statuses in reports and exports

The CSV export includes the status as lowercase text (`draft`, `sent`, `overdue`, `paid`, `void`). See [Export invoices to CSV](/help/invoices/export-invoices-csv).
