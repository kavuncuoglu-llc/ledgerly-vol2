---
title: API keys
category: integrations
summary: Shows how to create, restrict and revoke API keys and how to keep them safe.
updated: 2025-04-02
---

An API key identifies your program to the Ledgerly API (see [REST API overview](/help/integrations/rest-api-overview)). Keys are available on Pro and Enterprise.

## Create a key

1. Go to **Settings > Integrations > API keys** and choose **Create key**.
2. Name it after the program that will use it, for example "Accounting script".
3. Choose permissions: **Read only**, **Read and write** or custom per resource.
4. Select **Create**.

Ledgerly shows the key once, starting with `ldg_live_`. Copy it into your secrets manager now. It cannot be shown again. If you lose it, create another one.

Only Owners and Admins can create keys. A workspace can have up to 10 active keys.

## Test keys

Keys starting with `ldg_test_` work against a sandbox copy of your workspace where no emails are sent and no money moves. Create a test key from the same page.

## Restrict a key

- **IP allow list**: limit the key to your server addresses.
- **Expiry**: set a date, up to 1 year ahead.
- **Resource permissions**: for instance, allow `invoices` but not `payments`.

## Revoke a key

Choose **Revoke** next to the key. It stops working immediately and cannot be restored. Revoke any key that has been exposed in a repository, a chat message or a log.

## Rotate a key without downtime

1. Create a new key with the same permissions.
2. Deploy it in your program.
3. Check the **Last used** column for the old key. When it stops changing, revoke the old key.

## Keeping keys safe

- Never put a key in client-side code, a mobile app or a public repository.
- Use the smallest set of permissions that works.
- Remove team members' keys when they leave: keys are listed with their creator, and removing a member does not revoke their keys automatically.

Report a leaked key to `security@example.com` if you also suspect abuse (see [Fake emails and phishing](/help/security/fake-emails-and-phishing)).

## Webhook secrets

Webhooks use their own signing secret, not an API key (see [Webhooks](/help/integrations/webhooks)).
