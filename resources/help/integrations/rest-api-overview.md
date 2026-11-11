---
title: REST API overview
category: integrations
summary: Introduces the Ledgerly REST API, its authentication, rate limits and main resources.
updated: 2025-07-14
---

The Ledgerly REST API lets your own software read and create customers, invoices and payments. It is available on Pro and Enterprise.

## Basics

- Base URL: `https://api.example.com/v1`
- Format: JSON in and out, UTF-8
- Authentication: a bearer token in the `Authorization` header (see [API keys](/help/integrations/api-keys))
- Scope: each key is tied to one workspace

Example request:

```
GET /v1/invoices?status=overdue
Authorization: Bearer ldg_live_xxxxxxxx
```

## Resources

| Resource | Operations |
| --- | --- |
| customers | List, create, read, update, archive |
| invoices | List, create, read, update, send, void |
| payments | List, record, read, delete |
| recurring | List, create, read, pause, resume, end |
| credit-notes | List, create, read |

Invoice objects use the same five statuses as the app: `draft`, `sent`, `overdue`, `paid` and `void` (see [Invoice statuses](/help/invoices/statuses)). They also include `late_fee`, the amount added so far, and `balance`, which includes it.

## Rate limits

Pro: 100 requests per minute. Enterprise: 600 requests per minute. Exceeding the limit returns HTTP 429 with a `Retry-After` header in seconds. List endpoints return up to 100 items per page, with a `next_cursor` for the next page.

## Errors

Errors return a JSON body with `error.code` and `error.message`. Typical codes: `401` invalid key, `403` key lacks permission, `404` not found, `422` validation error, `429` rate limited. For example, sending an invoice for a customer with no email returns `422 customer_has_no_recipient`.

## Limits that also apply

API calls count against the same plan limits as the app, such as invoices sent per month (see [Plan limits](/help/billing/plan-limits)).

## Idempotency

For POST requests, send an `Idempotency-Key` header. Repeating a request with the same key within 24 hours returns the original result instead of creating a duplicate.

## Events instead of polling

To avoid polling, subscribe to [webhooks](/help/integrations/webhooks).
