---
title: Customer contacts and CC
category: customers
summary: Explains how to add several contacts to a customer and control who receives invoices, reminders and receipts.
updated: 2025-08-05
---

A customer can have up to five contacts. Each contact is a person with a name and an email address, and each one can be set to receive different emails.

## Add a contact

1. Open the customer and go to the **Contacts** card.
2. Choose **Add contact**.
3. Enter a name and an email address.
4. Tick the roles that apply, then save.

## Roles

| Role | Receives |
| --- | --- |
| Receives invoices | New invoices, reminders, late fee notices and receipts |
| CC | A copy of invoices only |
| Portal user | Access to the customer portal (see [Customer portal](/help/customers/customer-portal)) |

Exactly one contact is the **Primary**. The primary is addressed by name in emails and gets the pay link. If you delete the primary, Ledgerly promotes the next contact.

## Which emails go where

Invoices, reminders, late fee notices and receipts go to every contact with the Receives invoices role. CC contacts only get invoices, not reminders. If nobody has the role, Ledgerly cannot send, and an auto-sent recurring invoice is created as a draft (see [Auto-send recurring invoices](/help/recurring/auto-send-recurring-invoices)).

## Staying in the loop yourself

To receive a copy of each invoice yourself, switch on **Send me a copy** under **Settings > Notifications**. Your address is added as a hidden copy, so the customer does not see it.

## Bounces

If an address bounces, Ledgerly marks the contact with a warning icon and tells you on the next send. Fix the address, or the next reminders will go nowhere. Activity on the invoice shows the details (see [Reminder delivery problems](/help/reminders/reminder-delivery-problems)).

## Import

CSV imports create one contact per customer. Add additional contacts afterwards (see [Import customers](/help/customers/import-customers)).

## Privacy

Contacts are stored per workspace and deleted when you delete the customer or close the workspace (see [Data retention](/help/security/data-retention)).
