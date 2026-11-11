---
title: Time zone and formats
category: account
summary: Explains how the workspace time zone, date format and number format affect due dates, statuses and runs.
updated: 2025-02-12
---

The workspace time zone decides when a day starts and ends in Ledgerly. It matters more than most people expect, because several automatic actions are triggered by a date.

## What the time zone controls

| Item | Time |
| --- | --- |
| An invoice becoming Overdue | 00:00, the day after the due date |
| Recurring invoices | 06:00 on the run date |
| Reminders | 09:00 on the reminder day |
| Late fee posting | Nightly, shortly after 00:00 |
| Reports and exports | Period boundaries at 00:00 |

An invoice due April 2 is Overdue from April 3 at 00:00 in the workspace time zone. If your client is in a different time zone, they may see the change earlier or later than their own midnight.

## Change the time zone

1. Go to **Settings > Business details**.
2. Under **Time zone**, choose from the list.
3. Save.

The change applies to future actions. Invoices that were already Overdue stay Overdue. Invoices due today may become Overdue at a different moment, so avoid changing the time zone at the end of a month if you can.

## Date format

Choose between day/month/year, month/day/year and year-month-day under **Settings > Business details > Date format**. It affects what you see in the app and what is printed on invoices. The CSV export always uses ISO dates (see [Export invoices to CSV](/help/invoices/export-invoices-csv)).

## Number format

Choose the decimal and thousands separator: 1,234.56 or 1.234,56 or 1 234,56. It affects how amounts are displayed on invoices. Amounts in exports and in the API always use a dot as the decimal separator.

## Personal and workspace settings

Your location on your profile only suggests defaults when you create a workspace (see [Your profile](/help/account/profile)). After that, the workspace settings are what Ledgerly uses.

## Related

For the effect on due dates see [Due date and issue date](/help/invoices/due-date-vs-issue-date) and for statuses see [Invoice statuses](/help/invoices/statuses).
