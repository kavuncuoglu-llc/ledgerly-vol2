---
title: Invoice not sending
category: troubleshooting
summary: Lists the reasons the Send button is disabled or fails and how to fix each.
updated: 2025-06-16
---

When an invoice will not send, Ledgerly nearly always shows a message that names the reason. This page lists them with fixes.

## The Send button is disabled

| Message | Cause | Fix |
| --- | --- | --- |
| "Monthly invoice limit reached" | You sent 50 (Starter) or 500 (Pro) invoices this billing month | Wait for the next cycle or upgrade (see [Plan limits](/help/billing/plan-limits)) |
| "Workspace is read-only" | Subscription lapsed or trial ended | Update the payment method (see [Failed subscription payment](/help/billing/failed-subscription-payment)) |
| "Your role cannot send invoices" | You are a Viewer | Ask an Owner or Admin to change your role |
| "Total is below the minimum" | Minimum invoice amount rule | See [Minimum invoice amounts](/help/invoices/minimum-invoice-amounts) |

## Sending fails after you press Send

- **"Customer has no recipient."** No contact is marked Receives invoices. Open the customer and fix it (see [Customer contacts and CC](/help/customers/customer-contacts-and-cc)).
- **"Invalid email address."** Correct the typo on the contact.
- **"Invoice has no line items."** Add at least one line with an amount.
- **"Currency not enabled."** On Starter you can use only the default currency (see [Default currency](/help/account/default-currency)).
- **"Tax rate inactive."** Choose an active rate on the lines.

## It says Sent but the client has nothing

The invoice was sent but did not arrive. See [Client did not receive the email](/help/troubleshooting/client-did-not-receive-email).

## Recurring invoices created as drafts

If a recurring invoice was supposed to send automatically but is a Draft, one of the reasons above applies. Ledgerly emails you the cause (see [Auto-send recurring invoices](/help/recurring/auto-send-recurring-invoices)).

## The PDF will not download

Large logos or lines with unusual characters can slow PDF creation. Try again in a minute, reduce the logo to under 2 MB (see [Invoice templates and branding](/help/invoices/invoice-templates-and-branding)) and, if it still fails, copy the pay link instead.

## Browser problems

Hard refresh the page, try a private window and disable extensions that block scripts. Ledgerly supports the latest versions of Chrome, Firefox, Safari and Edge.

## Still failing

Contact support with a screenshot of the message and the invoice number (see [Contact support](/help/troubleshooting/contact-support)).
