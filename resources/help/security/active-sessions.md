---
title: Active sessions
category: security
summary: Shows how to see where you are signed in to Ledgerly and sign out devices you do not recognize.
updated: 2024-09-24
---

An active session is a browser or device that is currently signed in to your Ledgerly profile. Reviewing them is a quick way to spot unauthorized access.

## View your sessions

Open **Profile > Security > Active sessions**. Each row shows:

- The device and browser
- The approximate location, based on IP address
- The time of last activity
- A label **This device** on your current session

Locations are approximate, and VPNs or mobile networks can place you in the wrong city.

## Sign out a session

Choose **Sign out** next to a session to end it. The person using that browser is signed out within a minute and has to enter the password and code again.

To end all other sessions at once, choose **Sign out everywhere else**. Do this after you reset your password, which also ends them automatically (see [Reset your password](/help/security/reset-password)).

## How long sessions last

A session ends after 14 days of inactivity or 30 days in total, whichever comes first. Browsers you marked as trusted (see [Two-factor authentication](/help/security/two-factor-authentication)) skip the code but still follow these limits.

## New sign-in alerts

Ledgerly emails you whenever someone signs in from a new device. If the alert is not you:

1. Sign out all sessions.
2. Change your password.
3. Check your workspaces' recent activity for changes you did not make (**Settings > Activity**).
4. Contact support if anything was changed (see [Contact support](/help/troubleshooting/contact-support)).

## Team members

Owners can see members in **Settings > Team**, but cannot see their sessions. They can remove a member, which ends all their sessions in that workspace (see [Invite team members](/help/account/invite-team-members)).

## API keys are separate

Sessions are for people. Programs use API keys, which are managed separately (see [API keys](/help/integrations/api-keys)).
