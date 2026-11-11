---
title: Plan limits
category: billing
summary: Lists the numeric limits for each plan and explains what happens when you reach one.
updated: 2025-10-01
---

Limits apply per workspace. If you own several workspaces, each has its own plan and its own limits (see [Workspace billing](/help/billing/workspace-billing)).

## The limits

| | Starter | Pro | Enterprise |
| --- | --- | --- | --- |
| Invoices sent per month | 50 | 500 | Unlimited (fair use 10,000) |
| Customers | 25 | 500 | Unlimited |
| Team members | 1 | 5 | Unlimited |
| Workspaces | 1 | 3 | Unlimited |
| Active recurring schedules | 3 | 50 | Unlimited |
| Currencies | 1 | 25 | 25 |
| API requests per minute | Not included | 100 | 600 |

Customer import files can hold up to 5,000 rows and 10 MB regardless of plan.

## How limits are counted

- **Invoices sent** counts each invoice the first time it leaves Draft in the billing month. Editing, resending or voiding it does not count again. Recurring invoices count when they are sent, not when the schedule is created.
- **Customers** counts active customers. Archived customers do not count (see [Archive a customer](/help/customers/archive-a-customer)).
- **Team members** counts accepted members and pending invitations.

## When you reach a limit

When you reach the monthly invoice limit, the **Send** button is disabled until the next billing cycle or until you upgrade. You can still create drafts, record payments and send reminders for invoices already sent. Recurring schedules keep creating invoices as drafts and email you that they are waiting.

When you reach a customer or team limit, the add button explains the limit and offers an upgrade. Existing records are never deleted.

## Upgrading and downgrading

Upgrades take effect immediately and are prorated. Downgrades happen at the next renewal and are only allowed if your usage is within the lower plan's limits. For example, a Pro workspace with 40 customers can move to Starter only after it has been reduced to 25 or fewer. See [Change your plan](/help/billing/change-your-plan).

The side-by-side feature list is in [Plans and pricing](/help/billing/plans-and-pricing), and the recurring limit is explained further in [Recurring limits](/help/recurring/recurring-limits).
