---
title: Invoice numbering
category: invoices
summary: Explains the invoice number format, how to change the prefix or next number, and why numbers are never reused.
updated: 2024-09-02
---

Every invoice gets a unique number. The default format is `INV-` followed by a four-digit counter, such as `INV-4471`. When the counter passes 9999 it simply gets longer: `INV-10000`.

## How numbers are assigned

A draft receives its number when you first save it. Numbers are assigned in order within a workspace, and each workspace has its own sequence. A duplicated or recurring-generated invoice takes the next number in the same sequence, so a retainer invoice created by a schedule sits between your manual invoices (see [Recurring invoices](/help/recurring/recurring)).

## Changing the prefix and next number

1. Go to **Settings > Invoices > Numbering**.
2. Edit **Prefix**. You can use letters, digits and hyphens, up to 10 characters, for example `ACME-` or `2026-`.
3. Edit **Next number**.
4. Save.

The new prefix applies to invoices created after the change. Existing invoices keep their numbers. You cannot set the next number lower than the highest number already used with the same prefix; Ledgerly shows that minimum for you.

## Numbers are not reused

If you void a sent invoice, its number stays on record as Void and is never given to another invoice. This keeps your sequence auditable, and many tax authorities expect it. The one exception is a draft that you delete: if it holds the most recent number, the next new invoice can take it. See [Delete a draft invoice](/help/invoices/delete-a-draft-invoice).

## Credit notes

Credit notes have their own sequence starting with `CN-` (see [Credit notes](/help/invoices/credit-notes)). Ledgerly's subscription invoices use `LDG-` and are separate.

## Duplicate numbers

If two invoices appear to share a number, you probably have two workspaces or have imported records that used a different prefix. See [Duplicate invoice number](/help/troubleshooting/duplicate-invoice-number).

## Finding an invoice by number

Type the number into the search box at the top of the Invoices list. Searching `4471` finds `INV-4471`.
