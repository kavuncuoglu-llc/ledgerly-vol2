---
title: Single sign-on
category: integrations
summary: Describes how Enterprise workspaces connect SAML 2.0 single sign-on and what changes for team members.
updated: 2025-10-03
---

Single sign-on (SSO) lets your team sign in to Ledgerly with their company identity instead of a separate password. It is available on Enterprise only.

## What you need

- An identity provider that supports SAML 2.0
- A Ledgerly Owner or Admin
- A domain you control, such as `yourcompany.example`

## Set it up

1. Go to **Settings > Security > Single sign-on** and choose **Configure SAML**.
2. Copy the Ledgerly values (entity ID and assertion consumer URL) into your identity provider.
3. Paste the identity provider's sign-in URL and certificate into Ledgerly.
4. Add the domain and verify it with a DNS TXT record.
5. Choose **Test sign-in** in a private window.
6. Switch on **Enable SSO**.

## What changes for people

Members with an email on the verified domain sign in through the identity provider. Their Ledgerly passwords are disabled for this workspace and two-factor settings are managed at the provider. New members are created automatically the first time they sign in, with the role **Viewer**, unless you map a provider group to a role.

## Role mapping

Map groups from your provider to roles in Ledgerly (Admin, Biller, Viewer). The role is updated at every sign-in. The Owner is always set manually (see [Team roles and permissions](/help/account/team-roles-and-permissions)).

## Enforce SSO

With **Require SSO** on, members of the domain cannot use a password. Keep at least one Owner on a non-SSO login in case the identity provider is unavailable. This is a backup, so store its recovery codes safely (see [Recovery codes](/help/security/recovery-codes)).

## Removing a person

Disable them at your provider. Their next sign-in fails, and active sessions end within 15 minutes. You should also remove them in **Settings > Team** (see [Invite team members](/help/account/invite-team-members)).

## Troubleshooting

- **"Assertion invalid".** The certificate or entity ID does not match.
- **"Domain not verified".** Check the TXT record.
- **A user is asked for a password.** Their email is not on the verified domain.

## Other plans

Starter and Pro workspaces use passwords with optional two-factor authentication (see [Two-factor authentication](/help/security/two-factor-authentication)).
