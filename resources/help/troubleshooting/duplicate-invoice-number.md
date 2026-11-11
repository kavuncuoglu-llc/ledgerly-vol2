---
title: Duplicate invoice number
category: troubleshooting
summary: Explains why two invoices can appear to have the same number and how to fix numbering problems.
updated: 2024-10-17
---

Invoice numbers in a workspace are unique, so seeing the same number twice always has an explanation.

## Likely causes

**Two workspaces.** Each workspace has its own sequence. If you run two businesses, both can have an INV-0001. Check the workspace name at the top (see [Workspaces](/help/account/workspaces)).

**Different prefixes.** `INV-0042` and `ACME-0042` look similar but are different numbers. Prefixes are set in numbering settings (see [Invoice numbering](/help/invoices/invoice-numbering)).

**A voided invoice.** A Void invoice keeps its number and is never reused. If you see a Void invoice and a new one with a similar number, it is a different number (see [Void an invoice](/help/invoices/void-an-invoice)).

**Imported or manual numbers.** If you typed a number on a draft that already exists, Ledgerly refuses to save it and says "Number already in use".

**Credit notes.** Credit notes use the `CN-` sequence, so `CN-0012` and `INV-0012` can both exist.

**Subscription invoices.** Ledgerly's invoices to you start with `LDG-`.

## Fixing a gap

A gap, such as INV-0042 followed by INV-0044, usually means INV-0043 is a Void invoice or a deleted draft whose number was not the latest. Look for it with the status filter **Void**. Ledgerly does not fill gaps, because many tax authorities expect each number to be accounted for.

## Reset the next number

Go to **Settings > Invoices > Numbering** and set **Next number**. It cannot be lower than the highest number used with the same prefix.

## Recurring schedules

Schedules take the next number at the moment of each run. Two schedules running at 06:00 receive consecutive numbers in the order they ran, so numbers on the same day may not be in the order of customer names.

## API

Creating an invoice through the API with a number that exists returns `422 number_taken` (see [REST API overview](/help/integrations/rest-api-overview)).

## Still stuck

Contact support with both invoice numbers (see [Contact support](/help/troubleshooting/contact-support)).
