---
title: Account lockout
category: security
summary: Explains why a sign-in is temporarily blocked after failed attempts and how long the lock lasts.
updated: 2025-06-05
---

To protect your account from guessing attacks, Ledgerly blocks sign-in for a while after repeated failures.

## When it happens

After 5 failed sign-in attempts in a row, whether the wrong password or the wrong two-factor code, your profile is locked for 15 minutes. The countdown is shown on the sign-in page. The counter resets after a successful sign-in.

## What you can do

Wait for the 15 minutes to pass and try again carefully. Entering the password again while locked does not extend the lock. If you are sure you forgot the password, request a reset link; it arrives by email and can be used while the lock is active only after it ends (see [Reset your password](/help/security/reset-password)).

## What is not locked

Your clients are not affected: pay links, reminders, recurring invoices and late fees continue to work normally. Teammates can still sign in with their own profiles.

## Repeated lockouts

If your account is locked three times within an hour, Ledgerly sends an email asking whether it is you. Follow the link to confirm and the lock is lifted after the 15 minutes. If it was not you, change your password and review [Active sessions](/help/security/active-sessions).

## Two-factor problems

If you keep failing the code, check that your phone's clock is set to automatic. Use a recovery code instead (see [Recovery codes](/help/security/recovery-codes)).

## API keys

Lockout applies to people. API requests with a wrong key return an error but do not lock a user (see [API keys](/help/integrations/api-keys)).

## Still stuck

Contact support (see [Contact support](/help/troubleshooting/contact-support)). If you cannot sign in at all, see [Cannot log in](/help/troubleshooting/cannot-log-in).
