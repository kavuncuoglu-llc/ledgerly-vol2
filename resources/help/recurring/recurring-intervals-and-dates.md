---
title: Recurring intervals and dates
category: recurring
summary: Lists the intervals a recurring schedule supports and how Ledgerly handles month ends, start dates and end dates.
updated: 2025-01-09
---

When you choose **Make recurring** on an invoice, the interval and the dates decide exactly when each new invoice is created. The overall process is described in [Recurring invoices](/help/recurring/recurring).

## Intervals

| Interval | Creates a new invoice |
| --- | --- |
| Weekly | Every 7 days |
| Every 2 weeks | Every 14 days |
| Monthly | On the same day each month |
| Quarterly | Every 3 months |
| Yearly | On the same date each year |
| Custom | Every N days, weeks or months, where N is 1 to 24 |

## Start date

The first run happens on the start date at 06:00 in the workspace time zone. If the start date is today and 06:00 has passed, the first invoice is created straight away. If you start the schedule from an existing invoice, that invoice is the template and is not copied again on the first run.

## Month-end dates

If a monthly schedule starts on the 31st, months without a 31st use the last day of the month: January 31, February 28 (or 29), March 31, April 30. Ledgerly remembers the original day, so the schedule returns to the 31st when the month allows. A schedule starting on the 29th, 30th or 31st behaves the same way.

## End date and occurrences

Choose one:

- **Never**: the schedule runs until you pause or end it. The maximum is 120 runs, after which it ends on its own.
- **On date**: the last run is on or before that date.
- **After N invoices**: ends after N runs, up to 120.

## Time zones and holidays

Runs follow the calendar, not business days. A schedule that lands on a weekend still runs. Dates use your workspace time zone (see [Time zone and formats](/help/account/timezone-and-formats)).

## Due dates

Each generated invoice gets its due date from the template's payment terms, counted from the run date (see [Payment terms](/help/invoices/payment-terms)).

## Changing intervals

Edit the template to change the interval. The next run date is recalculated from the previous run. See [Edit a recurring template](/help/recurring/edit-a-recurring-template).
