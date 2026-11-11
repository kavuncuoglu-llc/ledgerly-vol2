---
title: Invoice total looks wrong
category: troubleshooting
summary: Lists the usual reasons an invoice total differs from what you expected, including rounding, tax mode, discounts and late fees.
updated: 2025-03-18
---

If the total on an invoice does not match your calculation, one of these is almost always the reason.

## 1. Rounding

Ledgerly calculates tax for each line and rounds it to the cent, then adds the lines. A calculator that applies the tax rate to the invoice subtotal can differ by a cent or two. Example: three lines of 10.10 at 7% each give 0.707 tax, which rounds to 0.71 per line, for 2.13 total, while 7% of 30.30 is 2.12. The per-line total is the correct one for Ledgerly (see [Add tax rates](/help/taxes/add-tax-rates)).

## 2. Tax-inclusive or exclusive

If Tax inclusive is on, the prices you typed already contain tax, so the total equals the sum of the lines. If it is off, tax is added on top. Check the switch near the totals (see [Tax-inclusive pricing](/help/taxes/tax-inclusive-pricing)).

## 3. Discounts

A line discount reduces the line before tax. An invoice discount is spread across taxable lines. Open the totals breakdown to see each (see [Line items and discounts](/help/invoices/line-items-and-discounts)).

## 4. A second tax or compound tax

Lines with two taxes show both. A compound second tax is calculated on the net plus the first tax, so it is larger than a simple percentage (see [Multiple taxes on one line](/help/taxes/multiple-taxes-on-one-line)).

## 5. A late fee

If the invoice is 30 or more days past due, the total includes a Late fee line. The invoice then shows a higher total than the original. This is expected (see [Late fees](/help/billing/late-fees)). Late fees are not taxed, so the tax amount does not change.

## 6. Payments and credit notes

The total stays the same after a payment; the balance goes down. Check the line **Balance due** rather than **Total** if you are comparing with what is still owed (see [Credit notes](/help/invoices/credit-notes)).

## 7. Currency

An invoice in another currency is not converted. A total of 1,284.50 in EUR is not 1,284.50 USD (see [Default currency](/help/account/default-currency)).

## 8. Number format

A total shown as 1.284,50 uses a comma as the decimal separator. The format is a workspace setting (see [Time zone and formats](/help/account/timezone-and-formats)).

## Still wrong?

If the sent invoice is incorrect, correct it (see [Edit a sent invoice](/help/invoices/edit-a-sent-invoice)) or void it (see [Void an invoice](/help/invoices/void-an-invoice)).
