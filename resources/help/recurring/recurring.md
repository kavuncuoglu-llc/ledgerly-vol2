---
title: Recurring invoices
category: recurring
summary: Shows how to make an invoice repeat on a schedule and what Ledgerly does each time it runs.
updated: 2025-09-03
---

Recurring invoices can be set to repeat weekly, monthly, quarterly, yearly or on a custom interval, so you create the invoice once and Ledgerly issues a new copy each time. They suit retainers, rent, memberships and any work you bill on a regular rhythm.

## Setting up a recurring invoice

1. Create the invoice as usual, or open an existing one. See [Create an invoice](/help/invoices/create-an-invoice).
2. Choose **Make recurring**.
3. Set the **Interval** (see [Recurring intervals and dates](/help/recurring/recurring-intervals-and-dates)).
4. Set the **Start date**, then either an **End date** or a number of occurrences (up to 120).
5. Choose whether to **Send automatically**. If it is off, each run creates a Draft for you to review.
6. Select **Start schedule**.

The invoice you started from becomes the template. Customer, line items, tax rates, payment terms, notes and the late fee setting are copied to each new invoice.

## What happens on each run

Schedules run at 06:00 in the workspace time zone. For every run Ledgerly creates a new invoice with the next number in your sequence, sets the issue date to the run date and sets the due date from the payment terms, for example Net 30. A new invoice gets its own status, reminders and late fee clock. Nothing carries over from the previous one, so an unpaid January invoice does not stop February from being issued.

Each generated invoice counts toward your monthly invoice limit once it is sent. Active schedules are limited by plan: 3 on Starter, 50 on Pro and unlimited on Enterprise. See [Recurring limits](/help/recurring/recurring-limits).

## Managing a schedule

- Edit the template: changes apply to future invoices only. See [Edit a recurring template](/help/recurring/edit-a-recurring-template).
- Pause or end it: see [Pause or end a recurring invoice](/help/recurring/pause-or-end-a-recurring-invoice).
- Fees and reminders: see [Late fees on recurring invoices](/help/recurring/late-fees-on-recurring-invoices) and [Reminders on recurring invoices](/help/recurring/reminders-on-recurring-invoices).

If you want to split one amount over several dates instead, use a [payment plan](/help/recurring/recurring-vs-payment-plans).
