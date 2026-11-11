---
title: Default currency
category: account
summary: Explains how the workspace default currency works, when it can be changed and how invoices in other currencies behave.
updated: 2025-04-25
---

Each workspace has a default currency. New invoices, reports and payouts use it unless you pick another currency for an invoice or customer.

## Supported currencies

Ledgerly supports 25 currencies, including USD, EUR, GBP, CAD, AUD, CHF, SEK, NOK, DKK, JPY, NZD and SGD. Starter workspaces use a single currency, the default. Pro and Enterprise workspaces can invoice in any of the 25.

## Set or change the default

1. Go to **Settings > Business details > Currency**.
2. Choose a new default.
3. Save.

You can change the default currency only while the workspace has no sent invoices. After the first invoice has been sent, the default is locked, because balances, fees and reports in the old and new currency would mix. If you must change it, create a new workspace (see [Workspaces](/help/account/workspaces)) or contact support.

## Per invoice and per customer

On Pro and Enterprise, choose a different currency on a customer (see [Edit customer details](/help/customers/edit-customer-details)) or directly on a draft invoice. You cannot change an invoice's currency once a payment or credit note exists.

## Rates

Ledgerly does not convert invoice amounts. An invoice in euros is owed in euros. For reporting, each currency is totalled separately. Payouts are made in your bank account's currency, and conversion happens at payout with a 1% fee (see [Payout schedule](/help/payments/payout-schedule)).

## Late fees and currencies

A late fee is charged in the invoice currency. The minimum fee of 1.00 also applies in the invoice currency (see [Late fees](/help/billing/late-fees)).

## Subscription currency

Your Ledgerly plan is billed in USD only. See [Plans and pricing](/help/billing/plans-and-pricing).

## Taxes

Tax rates are not tied to currency, but a report covers one currency at a time (see [Tax summary report](/help/taxes/tax-summary-report)).
