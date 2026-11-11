---
title: Reminders and late fees
category: reminders
summary: Shows how payment reminders and late fees fit together on one timeline, and what each one does and does not do.
updated: 2025-07-16
---

Reminders and late fees both apply to overdue invoices, so they are often confused. They are separate features with separate settings. A single timeline is easiest to follow.

## The timeline

Take an invoice due on day 0.

| Day | What happens |
| --- | --- |
| -3 | Optional upcoming-due reminder (off by default) |
| 0 | Due date. The invoice is still Sent |
| 1 | Status changes to Overdue |
| 3 | First reminder |
| 7 | Second reminder |
| 14 | Third and final reminder |
| 30 | Late fee applied (1.5% per month, prorated by day) and a late fee notice is emailed |
| 31 onward | The fee grows by one day's worth each night until payment |

Reminders are covered in [Payment reminders](/help/billing/reminders) and fees in [Late fees](/help/billing/late-fees).

## Key differences

- A reminder is an email. A late fee is a charge on the invoice.
- Reminders stop after day 14. Fees continue until the invoice is paid.
- Reminders can be customized on Pro and Enterprise. The Starter and Pro fee rate is fixed at 1.5%.
- Disputes and payment plans pause both. Paused customer reminders pause only the emails.

## Settings that affect only one of them

| Setting | Reminders | Late fees |
| --- | --- | --- |
| Pause reminders for a customer | Stops | No effect |
| Never charge late fees (customer) | No effect | Stops |
| Charge late fee off (invoice) | No effect | Stops |
| Mark as disputed | Stops | Stops |
| Payment plan active | Replaced by installment reminders | Stops |
| Void or Paid | Stops | Stops |

## A common misunderstanding

Clients sometimes assume that a reminder means a fee has been added. It has not. The first three reminders are all sent before the 30-day mark. If the invoice looks late but has no fee, see [Why an invoice is marked overdue](/help/invoices/why-invoice-marked-overdue).

## Where to change each one

Reminder settings: [Customize the reminder schedule](/help/reminders/customize-reminder-schedule). Fee settings: [Late fee settings](/help/billing/late-fee-settings).
