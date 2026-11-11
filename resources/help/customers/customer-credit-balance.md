---
title: Customer credit balance
category: customers
summary: Explains how a customer gets a credit balance from an overpayment or credit note and how to apply it to invoices.
updated: 2024-06-20
---

A credit balance is money you owe a customer that can be used against their future invoices. It is shown on the customer record and on their statement.

## How a credit balance is created

- A client pays more than the invoice balance. The extra is kept as credit.
- You issue a credit note on a Paid invoice (see [Credit notes](/help/invoices/credit-notes)).
- You void an invoice that had partial payments and choose to keep the money as credit (see [Void an invoice](/help/invoices/void-an-invoice)).
- You record an offline payment for more than the open invoices (see [Record an offline payment](/help/payments/record-an-offline-payment)).

The balance is kept per currency. A customer can have credit in USD and in EUR at the same time.

## Using the credit

When you create a new invoice for the customer, a banner shows the available credit with an **Apply credit** button. Applying it records an internal payment with the method Credit, up to the invoice total. If the credit covers the whole invoice, it is Paid right after sending.

You can also apply credit to an existing open invoice from its **Record payment** screen by choosing the method **Customer credit**.

## Credit and late fees

Credit does not apply itself. An open invoice stays Overdue, with reminders and a fee at 30 days past due, until you apply the credit or the client pays. If a customer has credit and an overdue invoice, apply the credit to avoid the fee. See [Late fees](/help/billing/late-fees).

## Returning credit as money

A credit balance can be returned to the customer. If the credit came from a card payment made within 90 days, refund it through the payment (see [Refunds](/help/payments/refunds)). Otherwise refund it yourself and choose **Remove credit** on the customer record with the reason Refunded.

## Expiry

Credit does not expire.

## Reports

Credit balances are listed under **Reports > Customer balances** as negative balances. They are liabilities in your books, so tell your accountant about them.
