---
title: Invite team members
category: account
summary: Shows how to invite colleagues to a workspace, how long invitations last and why one might fail.
updated: 2024-10-30
---

Pro and Enterprise workspaces can have more than one user. Starter has a single seat, so the Invite button is disabled until you upgrade.

## Send an invitation

1. Go to **Settings > Team** and choose **Invite**.
2. Enter the person's email address.
3. Choose a role: Admin, Biller or Viewer (see [Team roles and permissions](/help/account/team-roles-and-permissions)).
4. Select **Send invitation**.

The invitee gets an email with a link valid for 7 days. If they already have a Ledgerly account with that email, they join immediately after confirming. Otherwise they create a password first (see [Create your account](/help/getting-started/create-your-account)).

## Manage pending invitations

Pending invitations are listed on the Team page with the date sent. You can **Resend** (which extends the link to a new 7 days) or **Revoke**. Pending invitations count toward your member limit: 5 on Pro. Revoke ones you no longer need to free the seat.

## If the invitation does not work

- **"No seats left."** You have reached the member limit. Remove a member, revoke a pending invite or upgrade (see [Change your plan](/help/billing/change-your-plan)).
- **Email never arrives.** Ask the person to check spam. The sender is `team@example.com`.
- **Wrong email address.** Revoke and send a new one.
- **Link expired.** Resend it.

The invitee's email must match the one the invitation was sent to. If they want to use another address, send a new invitation.

## Security settings

Owners can require two-factor authentication for all members under **Settings > Security**. People without it set up are asked to do so on their next sign-in (see [Two-factor authentication](/help/security/two-factor-authentication)).

## Removing someone

Choose **Remove** next to the name on the Team page. They lose access immediately and active sessions end. Invoices they created stay in the workspace, and their name stays in the activity history.

## Enterprise

Enterprise workspaces can provision users automatically through single sign-on (see [Single sign-on](/help/integrations/single-sign-on)).
