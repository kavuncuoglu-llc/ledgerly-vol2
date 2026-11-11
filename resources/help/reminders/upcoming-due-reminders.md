---
title: Upcoming-due reminders
category: reminders
summary: Explains the optional reminder sent before an invoice is due and how to switch it on.
updated: 2024-07-03
---

The upcoming-due reminder is a courtesy email sent before the due date. It is off by default, because the standard schedule only starts after the due date.

## What it does

When switched on, Ledgerly emails the client 3 days before the due date. The message states the amount, the due date and the Pay now button. It is sent once per invoice. If the invoice is paid, voided or marked Disputed before the reminder, nothing is sent.

## Turn it on

1. Go to **Settings > Billing rules > Reminders**.
2. Switch on **Remind before due date**.
3. Save.

It applies to Sent invoices that have not yet reached their reminder date, and to future invoices. Invoices that are already within 3 days of their due date do not receive it.

On Pro and Enterprise, you can set a different number of days (up to 14) by using a custom schedule (see [Customize the reminder schedule](/help/reminders/customize-reminder-schedule)).

## Which invoices are skipped

- Invoices due on receipt, because the due date is the issue date.
- Invoices created with less than 3 days between issue date and due date.
- Draft, Paid and Void invoices.

## Does it count as one of the three reminders?

No. The three standard reminders are the ones sent at 3, 7 and 14 days past due. The upcoming-due reminder is extra, so an invoice on the standard schedule with this option on can receive four emails in total before late fees begin. See [Payment reminders](/help/billing/reminders).

## Effect on late fees

None. The reminder is purely informational and has no effect on the due date, the Overdue status or the late fee clock (see [Late fees](/help/billing/late-fees)).

## For recurring invoices

Each generated invoice gets its own upcoming-due reminder. See [Reminders on recurring invoices](/help/recurring/reminders-on-recurring-invoices).
