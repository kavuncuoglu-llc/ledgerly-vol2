---
title: Edit a recurring template
category: recurring
summary: Shows how to change a recurring schedule's line items, terms and settings and which invoices the change affects.
updated: 2025-07-30
---

The template is the invoice that a recurring schedule copies on each run. Editing the template changes what future invoices look like.

## Open the template

Go to **Recurring**, choose the schedule and select **Edit template**. You see the same editor as for a normal invoice, plus the schedule settings at the top.

## What you can change

- Line items, quantities, prices and discounts
- Tax rates and the **Tax inclusive** setting
- Payment terms
- Notes and footer
- The **Charge late fee** toggle (see [Late fees on recurring invoices](/help/recurring/late-fees-on-recurring-invoices))
- Interval, end date and the **Send automatically** option
- Which customer contacts receive the invoice

You cannot change the customer. To bill someone else, create a new schedule.

## Which invoices change

Edits apply to invoices created from the next run onward. Invoices already created, even if they are still drafts, keep their old values. To fix an existing draft, edit it directly (see [Edit a sent invoice](/help/invoices/edit-a-sent-invoice)).

Ledgerly shows a summary of changes before you confirm, for example "Next invoice on 1 Nov will total $540.00 instead of $500.00".

## Price changes

A common pattern is to raise a retainer price. Edit the line item, save and note the effective date. The new price is used from the first run after you save. If the next run is today and has not happened yet, the change applies to it.

## Placeholders in text

Notes can include placeholders such as `{{month}}`, `{{year}}` and `{{period}}`. They are filled in when each invoice is created, so a note can say "Retainer for {{month}} {{year}}".

## Limits and rules

The edited template must still satisfy the workspace's [minimum invoice amount](/help/invoices/minimum-invoice-amounts). If it does not, runs create drafts.

For a general explanation of the schedule, see [Recurring invoices](/help/recurring/recurring). Retainer pricing is covered in [Retainer billing](/help/recurring/retainer-billing).
