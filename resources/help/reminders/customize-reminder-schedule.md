---
title: Customize the reminder schedule
category: reminders
summary: Explains how Pro and Enterprise workspaces can change when automatic reminders are sent.
updated: 2025-09-08
---

The standard schedule sends reminders at 3, 7 and 14 days past due. Pro and Enterprise workspaces can replace it with their own. Starter workspaces always use the standard schedule.

## Change the schedule

1. Go to **Settings > Billing rules > Reminders**.
2. Choose **Custom schedule**.
3. Add up to 5 reminders. For each, set the offset in days relative to the due date: from -14 (14 days before) to +60 (60 days after).
4. Choose which template each one uses (see [Reminder email templates](/help/reminders/reminder-email-templates)).
5. Save.

The new schedule applies to invoices that are Sent or Overdue at that time and to future invoices. Reminders whose date has already passed are not sent retroactively.

## Example

A consultancy wants an early nudge and a longer chase:

| Offset | Template |
| --- | --- |
| -3 days | Upcoming due |
| +3 days | First reminder |
| +10 days | Second reminder |
| +21 days | Final reminder |

## Limits worth knowing

- Reminders are at least one day apart.
- A custom schedule does not change the late fee, which is still added 30 days after the due date on Pro (see [Late fees](/help/billing/late-fees)). A reminder after day 30 would therefore arrive after the fee has started. The final template should mention this, or it will confuse clients.
- Enterprise workspaces can also change the grace period, in which case the last reminder should come just before it ends.

## Per-customer rules (Enterprise)

Enterprise workspaces can attach a different schedule to a customer. On the customer record, choose **Reminders > Use custom schedule**. The customer rule overrides the workspace schedule. To stop reminders for a customer altogether, see [Pause reminders for a customer](/help/reminders/pause-reminders-for-a-customer).

## Reset to standard

Choose **Standard schedule** at the top of the same page. Starter workspaces see the standard schedule and cannot change it, as described in [Payment reminders](/help/billing/reminders).
