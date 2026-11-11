---
title: Late fee settings
category: billing
summary: Describes where to switch late fees on or off and what each plan can customize.
updated: 2025-03-19
---

Late fees are controlled in two places: for the whole workspace, and for each invoice. This page covers the controls. The rules for how the fee is worked out are in [Late fees](/help/billing/late-fees).

## Workspace default

Go to **Settings > Billing rules > Late fees**. You will see:

- **Charge late fees**: on by default.
- **Rate**: 1.5% per month, prorated by day. Starter and Pro workspaces cannot change the rate.
- **Grace period**: 30 days after the due date. Starter and Pro cannot change it.

Enterprise workspaces can enter a custom monthly rate (0.1% to 5.0%) and a custom grace period (0 to 90 days). Changes apply to invoices created after you save. Invoices that already exist keep the values they were created with.

## Per invoice

Each invoice has a **Charge late fee** toggle in the invoice settings panel. Turn it off to exempt a single invoice, for example for a client with a special agreement. You can change it on Draft, Sent and Overdue invoices. Turning it off on an Overdue invoice that already has a fee stops new charges but leaves the existing fee; use [Waive or reduce a late fee](/help/billing/waive-or-reduce-a-late-fee) to remove it.

## Customer-level exemption

On a customer record, switch on **Never charge late fees** to exempt every invoice for that customer. This overrides the workspace default and applies to new and existing invoices.

## Recurring templates

Recurring templates have their own **Charge late fee** toggle and every invoice generated from the template copies it. Details are in [Late fees on recurring invoices](/help/recurring/late-fees-on-recurring-invoices).

## Order of precedence

If settings disagree, the most specific one wins: the invoice toggle, then the customer exemption, then the workspace default.

## Plans

Custom rates are an Enterprise feature. See [Plans and pricing](/help/billing/plans-and-pricing) for the full comparison.
