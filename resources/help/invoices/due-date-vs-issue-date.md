---
title: Due date and issue date
category: invoices
summary: Clarifies the difference between an invoice's issue date and its due date and which one drives status, reminders and fees.
updated: 2025-08-12
---

Two dates on an invoice are easy to confuse, and they do different jobs.

## The two dates

**Issue date** is the date the invoice is dated. It defaults to the day you create the draft, and you can change it. It appears on the PDF and is the date used in your tax reports.

**Due date** is the last day the client can pay without the invoice being late. Ledgerly calculates it as the issue date plus the payment terms (see [Payment terms](/help/invoices/payment-terms)). You can also overwrite it manually.

Sending an invoice does not change either date. If you create a draft on March 3 and send it on March 10, the issue date is still March 3 unless you edit it, and the due date is March 3 plus the terms. To avoid giving the client less time than you intend, set the issue date to the day you send.

## Which date drives what

| Behaviour | Counted from |
| --- | --- |
| Overdue status | The day after the due date |
| Reminders at 3, 7 and 14 days | Due date |
| Late fee after 30 days | Due date |
| Tax period | Issue date |
| Monthly invoice limit | The day it is sent |
| Recurring run | The schedule, not the dates on earlier invoices |

Read more in [Invoice statuses](/help/invoices/statuses), [Payment reminders](/help/billing/reminders) and [Late fees](/help/billing/late-fees).

## Date of payment is a third date

The date a payment was received matters for late fees: fees are only charged for days when a balance was outstanding. See [Mark an invoice as paid](/help/invoices/mark-invoice-as-paid).

## Changing dates

On a draft, edit either date freely. On a sent invoice, only the due date can change. See [Edit a sent invoice](/help/invoices/edit-a-sent-invoice). Changing the due date to the future returns an Overdue invoice to Sent.

## Time zones

Dates are in your workspace time zone. See [Time zone and formats](/help/account/timezone-and-formats).
