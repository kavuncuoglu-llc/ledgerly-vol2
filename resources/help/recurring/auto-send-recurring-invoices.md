---
title: Auto-send recurring invoices
category: recurring
summary: Explains the Send automatically option, what it sends and when a recurring invoice is created as a draft instead.
updated: 2024-12-02
---

**Send automatically** decides whether Ledgerly emails each new recurring invoice to your client without you looking at it first.

## Send automatically on

When a schedule runs at 06:00, Ledgerly creates the invoice, sets its status to Sent and emails it to the contacts marked Receives invoices. The invoice immediately counts toward your monthly invoice limit and starts its reminder and late fee clock. You receive a short summary email so you know it went out.

This works well for fixed charges like rent, hosting or a flat retainer.

## Send automatically off

Ledgerly creates the invoice as a Draft and emails you a link to review it. Nothing reaches your client until you press **Send**. Draft invoices do not count toward your limit, do not get reminders and do not accrue fees. Use this option when amounts vary, for example hours or usage.

Remember that a draft that you leave unsent has a due date calculated at creation. If you send it days later, adjust the issue date so your client gets the full terms (see [Due date and issue date](/help/invoices/due-date-vs-issue-date)).

## When auto-send creates a draft anyway

Even with the option on, Ledgerly creates a draft if:

- The customer has no email address or no contact marked Receives invoices
- The total is below your [minimum invoice amount](/help/invoices/minimum-invoice-amounts)
- You are at your monthly invoice limit (see [Plan limits](/help/billing/plan-limits))
- The workspace is read-only because of a lapsed subscription

You get an email explaining why.

## Sending time

Auto-sent invoices go out at 06:00 workspace time, so your client sees them at the start of their working day if they share your time zone.

## Reminders

Each auto-sent invoice gets its own reminders. See [Reminders on recurring invoices](/help/recurring/reminders-on-recurring-invoices).

## Changing the option

Edit the template to switch the option. It applies to the next run. See [Edit a recurring template](/help/recurring/edit-a-recurring-template).
