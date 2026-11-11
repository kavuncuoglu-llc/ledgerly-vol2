---
title: Payment plans
category: payments
summary: Explains how to offer a client a payment plan on an overdue or large invoice and how it affects fees and reminders.
updated: 2025-06-24
---

A payment plan splits one invoice into scheduled installments. It is the tool to use when a client cannot pay in full but has agreed to pay over time.

## Offering a payment plan

1. Open the invoice. It must be Sent or Overdue with a balance.
2. Choose **More > Offer payment plan**.
3. Pick the number of installments, from 2 to 6.
4. Pick the frequency: weekly, every 2 weeks or monthly.
5. Set the date of the first installment. Ledgerly divides the balance evenly and puts any rounding difference in the last installment. You can edit individual amounts if the total still matches the balance.
6. Choose **Send plan to client**.

The client receives an email with the schedule and must press **Accept plan**. Until they accept, the plan is not active and nothing changes on the invoice. After acceptance the invoice shows a Payment plan badge.

## What changes while a plan is active

- Late fees are not applied, and the days covered by the plan are not charged (see [Late fees](/help/billing/late-fees)).
- Automatic overdue reminders pause. Ledgerly sends an installment reminder 2 days before each installment instead (see [Payment reminders](/help/billing/reminders)).
- The invoice keeps its status. An Overdue invoice stays Overdue until it is paid in full.

Clients pay each installment from their pay link by card or ACH, or you record offline payments by hand (see [Record an offline payment](/help/payments/record-an-offline-payment)). Each installment appears as a separate payment and the invoice shows Partial until the last one arrives.

## When a plan breaks

If an installment is more than 7 days late, the plan is marked Broken. The badge is removed, automatic reminders restart and the late fee resumes. If the invoice is already 30 or more days past its original due date, the fee is added at the next nightly run. The original due date never moves.

You can cancel a plan yourself with **More > Cancel payment plan**, with the same result.

## Payment plans compared with recurring invoices

A payment plan spreads one invoice over several dates. A recurring invoice creates separate new invoices. See [Recurring invoices compared with payment plans](/help/recurring/recurring-vs-payment-plans).
