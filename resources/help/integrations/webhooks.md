---
title: Webhooks
category: integrations
summary: Explains how to receive event notifications such as invoice.paid at your own URL and how to verify them.
updated: 2025-08-28
---

A webhook is an HTTP request that Ledgerly sends to your server when something happens in a workspace. Webhooks are available on Pro and Enterprise.

## Add an endpoint

1. Go to **Settings > Integrations > Webhooks** and choose **Add endpoint**.
2. Enter an HTTPS URL. Plain HTTP is not accepted.
3. Select the events you want.
4. Save, and copy the **signing secret**.

## Events

| Event | Sent when |
| --- | --- |
| `invoice.sent` | An invoice is sent, manually or by a recurring schedule |
| `invoice.paid` | An invoice balance reaches zero |
| `invoice.overdue` | An invoice becomes Overdue, the day after its due date |
| `payment.failed` | A client's card or bank payment fails |

The `invoice.overdue` event fires when the status changes, not when a late fee is added. The invoice object in the payload includes the current `late_fee`.

## Payload

Each request is a JSON POST containing an `id`, a `type`, a `created_at` timestamp and a `data.object` with the invoice or payment (the same shape as the [REST API](/help/integrations/rest-api-overview)).

## Verify the signature

Each request carries an `X-Ledgerly-Signature` header: an HMAC-SHA256 of the raw request body using your signing secret, hex encoded. Compute the same value on your side and compare in constant time. Reject requests that do not match.

## Reply and retries

Respond with any 2xx status within 10 seconds. Otherwise Ledgerly retries 5 times over 24 hours with increasing delays. After the fifth failure the delivery is marked Failed, and if an endpoint fails for 3 days in a row it is disabled and the Owner is emailed. You can replay any event from the log.

## Handling duplicates

Events can be delivered more than once. Use the event `id` to ignore ones you have already processed. Events are not guaranteed to arrive in order, so check `created_at`.

## Test

Choose **Send test event** next to an endpoint. Test events use the type `test.ping`.

## Related

Authentication for outgoing API calls is explained in [API keys](/help/integrations/api-keys).
