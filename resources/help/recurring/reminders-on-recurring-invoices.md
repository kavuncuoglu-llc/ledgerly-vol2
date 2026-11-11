---
title: Reminders on recurring invoices
category: recurring
summary: Explains how payment reminders work for invoices created by a recurring schedule, including what happens when several are overdue.
updated: 2025-08-14
---

Recurring invoices use the same reminder system as every other invoice. There is no separate reminder setting on a schedule.

## How reminders attach to generated invoices

Each invoice created by a schedule gets its own reminders as soon as it is Sent:

- Automatic reminders at 3, 7 and 14 days past due (see [Payment reminders](/help/billing/reminders))
- The optional upcoming-due reminder 3 days before the due date, if you have turned it on (see [Upcoming-due reminders](/help/reminders/upcoming-due-reminders))

If a schedule is set to **Send automatically**, reminders begin right away. If it creates drafts, reminders only begin after you send the draft.

## Several overdue invoices at once

If a client lets two monthly invoices go overdue, they receive reminders for both. To avoid a flood, Ledgerly combines reminders that would be sent on the same day into a single email that lists every overdue invoice for that customer with a Pay all button. The customer sees the individual invoice numbers, amounts and due dates.

## Stopping reminders

- **Pause the schedule.** This only stops new invoices; reminders for existing ones continue (see [Pause or end a recurring invoice](/help/recurring/pause-or-end-a-recurring-invoice)).
- **Pause reminders for the customer.** This stops all automatic reminders for that customer, including those for recurring invoices (see [Pause reminders for a customer](/help/reminders/pause-reminders-for-a-customer)).
- **Void or dispute an invoice.** Reminders stop for that invoice only.

## Reminders and fees together

Reminders end after the third one at 14 days past due. Late fees begin at 30 days past due and run until payment. Details of the interplay are in [Reminders and late fees](/help/reminders/reminders-and-late-fees).

## Changing the wording

The email text comes from your workspace reminder templates, which Pro and Enterprise workspaces can edit. See [Reminder email templates](/help/reminders/reminder-email-templates).

## Related

How the schedule runs is explained in [Recurring invoices](/help/recurring/recurring).
