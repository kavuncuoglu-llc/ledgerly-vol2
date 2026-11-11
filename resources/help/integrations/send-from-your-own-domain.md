---
title: Send from your own domain
category: integrations
summary: Shows how to make invoice and reminder emails come from your own domain by adding DNS records.
updated: 2025-09-15
---

By default, emails go out from `invoices@example.com` with your business name as the sender name and your reply-to address. On Pro and Enterprise, you can send from your own domain instead, such as `billing@yourcompany.example`. Emails then look more trustworthy and are less likely to land in spam.

## Set it up

1. Go to **Settings > Integrations > Email domain** and choose **Add domain**.
2. Enter the domain, for example `yourcompany.example`.
3. Ledgerly shows three DNS records to add at your DNS provider: an SPF record, a DKIM key and a return-path record.
4. Add them, then choose **Verify**.

DNS changes can take up to 24 hours to spread. Verification is checked again every hour until it succeeds, and Ledgerly emails the Owner when it does.

## Choose the sender address

After verification, set the **From address**, for example `billing@yourcompany.example`. It must be on the verified domain. Replies go to your reply-to address, which you can set separately in **Business details**.

## What is covered

The new sender is used for invoices, payment reminders, late fee notices, receipts, statements and portal links (see [Payment reminders](/help/billing/reminders)). Emails about your account, such as password resets, still come from Ledgerly.

## If verification fails

- **SPF record not found.** If you already have an SPF record, add Ledgerly's include to it rather than adding a second one.
- **DKIM key mismatch.** Copy the value without line breaks or quotation marks added by your provider.
- **Record too long.** Some providers need the DKIM value split into pieces.

## Switching it off

Remove the domain from the page and delivery goes back to the default sender immediately.

## Delivery problems

If clients still do not see your emails, check the Activity tab on the invoice for Bounced or Blocked labels (see [Reminder delivery problems](/help/reminders/reminder-delivery-problems)).

## Plans

Starter workspaces cannot send from their own domain (see [Plans and pricing](/help/billing/plans-and-pricing)).
