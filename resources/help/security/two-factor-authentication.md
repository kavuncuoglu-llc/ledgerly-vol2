---
title: Two-factor authentication
category: security
summary: Explains how to turn on two-factor authentication with an authenticator app and how sign-in works afterwards.
updated: 2024-12-12
---

Two-factor authentication (2FA) asks for a six-digit code from your phone as well as your password. It protects your workspace even if your password is stolen.

## Turn it on

1. Open **Profile > Security > Two-factor authentication** and choose **Set up**.
2. Install an authenticator app on your phone if you do not have one. Any app that supports time-based one-time codes works.
3. Scan the QR code shown by Ledgerly, or type the setup key by hand.
4. Enter the six-digit code the app shows to confirm.
5. Save the **recovery codes** that Ledgerly then displays (see [Recovery codes](/help/security/recovery-codes)).

Text message codes are not offered, because phone numbers can be taken over.

## Signing in

Enter your email and password, then the current code. Codes change every 30 seconds. On your own device you can choose **Trust this device for 30 days**, and Ledgerly will skip the code on that browser. Sensitive actions such as changing the payout bank account or transferring ownership always ask for a code.

## Requiring it for the team

Owners can require 2FA for all members under **Settings > Security**. People who have not enabled it are prompted at their next sign-in and cannot continue until they do.

## Change your phone

1. Sign in with your old device or a recovery code.
2. Go to **Profile > Security > Two-factor authentication** and choose **Reset**.
3. Scan the new QR code.

## Turn it off

You can turn it off from the same page, unless the Owner requires it. Doing so signs out other devices.

## Problems

- **Codes are rejected.** The time on your phone is probably out of sync. Enable automatic time in your phone settings.
- **Lost phone and no recovery codes.** Contact support, who will verify your identity before resetting it (see [Contact support](/help/troubleshooting/contact-support)).

Enterprise customers who use single sign-on apply 2FA at their identity provider (see [Single sign-on](/help/integrations/single-sign-on)).
