---
title: Reminder email templates
category: reminders
summary: Shows how to edit the wording of reminder emails and which placeholders you can use.
updated: 2025-05-27
---

Reminder emails are built from templates, one for each step of the schedule. Pro and Enterprise workspaces can edit them. Starter workspaces use the standard wording, which cannot be changed.

## Edit a template

1. Go to **Settings > Notifications > Reminder emails**.
2. Choose a template: Upcoming due, First reminder, Second reminder, Final reminder or Late fee notice.
3. Edit the **Subject** and **Message**.
4. Select **Send test email** to see the result in your inbox.
5. Save.

Changes apply to emails sent from that moment. Reminders already sent are not changed.

## Placeholders

Insert these in the subject or message and Ledgerly fills them in:

| Placeholder | Becomes |
| --- | --- |
| `{{customer_name}}` | The client's name |
| `{{invoice_number}}` | For example INV-4471 |
| `{{amount_due}}` | Balance with currency |
| `{{due_date}}` | Due date in the client's language |
| `{{days_overdue}}` | Days since the due date |
| `{{pay_link}}` | The Pay now URL |
| `{{business_name}}` | Your business name |

A message must contain `{{pay_link}}` or the Pay now button, otherwise Ledgerly refuses to save it, so clients can always pay.

## Default wording

The first reminder is friendly, the second is firm and the final one says that a late fee may be added after 30 days (see [Late fees](/help/billing/late-fees)). If you change the late fee policy for your workspace, update the final template to match. Enterprise customers with a custom grace period should check the text carefully.

## Tone and length

Keep subjects under 70 characters so they do not get cut off in inboxes. Messages are plain text with basic formatting; images and attachments are not supported, except the invoice PDF that Ledgerly attaches automatically.

## Restore the original

Choose **Restore default** on any template. This cannot be undone.

## Related

The timing of each reminder is in [Payment reminders](/help/billing/reminders) and [Customize the reminder schedule](/help/reminders/customize-reminder-schedule).
