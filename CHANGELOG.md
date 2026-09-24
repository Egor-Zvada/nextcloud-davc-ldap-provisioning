# Changelog

## 0.3.0

- Add per-profile credential sources: LDAP attributes or one manually entered Basic-auth account.
- Encrypt manually entered passwords with Nextcloud `ICrypto`; never return a stored password to the browser or CLI.
- Add explicit per-profile targeting for all users, selected users, selected groups, or any combination of users and groups.
- Remove the implicit current/test-user fallback: an untargeted profile does nothing.
- Move background enablement and interval into each profile; a five-minute dispatcher runs only profiles that are due.
- Resolve selected group membership at run time and deduplicate users selected both directly and through groups.
- Add administrator-only user/group search to the settings page.
- Make configuration cards collapsible and keep existing cards collapsed by default.
- Keep upgraded 0.2.x profiles safe by disabling their new per-profile schedule and leaving their target list empty until an administrator chooses recipients.

## 0.2.0

- Add up to 20 independent DAV profiles with separate LDAP attribute pairs and endpoints.
- Preserve the 0.1.x configuration as the `default` profile and retain its managed service mapping.
- Add independent automatic selection for CalDAV calendars and CardDAV address books.
- Run bounded immediate harmonization after automatic collection selection.
- Add Russian administration UI translations with English as the fallback language.
- Add dynamic profile add/remove/edit controls in Additional settings.
- Add `--profile` support to provisioning and OCC profile list/set/delete commands.
- Keep background processing disabled during upgrade and keep profile removal non-destructive.
- Roll back a newly created or replacement DAV service when initial collection setup fails.

## 0.1.3

- Document and enforce the two LDAP attributes as an explicit per-user opt-in.
- Keep users with either attribute missing completely outside the DAV Connector path.
- Clarify that the provider endpoint is configurable and Yandex is only the default example.
- Accept hostnames and IP addresses for non-Yandex CalDAV providers.
- Add eligibility regression tests for all incomplete credential combinations.
- Document the current one-provider and Basic-auth scope.

## 0.1.2

- Make `--dry-run` report whether provisioning would create, update, reconnect,
  or leave a DAV service unchanged.
- Safely adopt a matching manually-created Basic-auth service by user, endpoint,
  and login without requiring the same display label.
- Treat an empty DAV path and `/` as the same endpoint to avoid duplicate Yandex
  CalDAV connections.

## 0.1.1

- Fix the background provisioning switch to use its own app-config key.
- Never reuse Nextcloud's reserved `enabled` key, which represents whether the
  app itself is enabled.
- Add the real project repository and issue tracker URLs.
- Add a regression test for the reserved-key collision.

## 0.1.0

- Initial MVP for Nextcloud 34 and DAV Connector 1.1.x.
- LDAP login/secret attribute resolution.
- Idempotent DAV Connector service provisioning.
- Automatic discovery and enablement of CalDAV collections.
- Single-user and `--all` OCC provisioning commands with dry-run support.
- Background provisioning job, disabled by default.
- Admin settings under Additional settings.
- Fail-closed DAV Connector version guard.
