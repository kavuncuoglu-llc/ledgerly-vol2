---
title: Setting minimum invoice amounts
category: invoices
summary: Shows how to set a minimum invoice total so small invoices cannot be sent by mistake.
updated: 2024-12-18
---

A minimum invoice amount stops you, or your team, from sending an invoice whose total is below a number you choose. It is useful if processing a very small invoice costs you more than it earns.

## Turn it on

1. Go to **Settings > Invoices > Rules**.
2. Switch on **Minimum invoice amount**.
3. Enter the amount in your workspace currency, for example 25.00.
4. Save.

The rule applies when you press **Send** on a draft, and when a recurring schedule with auto-send tries to send. Drafts below the minimum can still be saved.

## What happens below the minimum

- A manual send is blocked with a message that shows the total and the minimum.
- A recurring invoice that falls below the minimum is created as a Draft and you are emailed, even if auto-send is on.
- Existing invoices that are already sent are not affected.

The minimum is checked against the invoice total including tax and after discounts, but before any late fee. Credit notes are not subject to the minimum.

## Multiple currencies

On Pro and Enterprise, the minimum is compared in the invoice currency using the workspace's stored exchange rate. You can set a different minimum per currency under **Rules > Currency overrides**.

## A different limit with a similar name

Do not confuse this with the 1.00 threshold in [Late fees](/help/billing/late-fees). That threshold is a minimum late fee, not a minimum invoice amount, and it is fixed. It also differs from the 10.00 minimum payout described in [Payout schedule](/help/payments/payout-schedule).

## Limits that are not about size

If you cannot send because you reached your monthly number of invoices, that is a plan limit, not this rule. See [Plan limits](/help/billing/plan-limits).

To exempt one invoice, ask an Admin to lower or switch off the rule temporarily. There is no per-invoice override.
