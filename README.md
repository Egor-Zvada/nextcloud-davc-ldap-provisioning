# DAVC Provisioning

Companion app for **Nextcloud 34** and **DAV Connector 1.1.x**. It provisions
multiple Basic-auth DAV accounts from LDAP attributes or encrypted manual
credentials without modifying the official `integration_davc` app.

The administration page uses Nextcloud localization: English is the source
language and a complete Russian translation is included.

## What it does

- Stores up to 20 independent DAV profiles.
- Gives every profile its own name, credential source, DAV host, port, path,
  recipients, schedule, and HTTP/HTTPS setting.
- Supports either per-user LDAP login/secret attributes or one manually entered
  login and password shared by that profile's selected Nextcloud users.
- Encrypts manual passwords with Nextcloud's server secret and never returns a
  stored password to the browser or CLI.
- Targets all users, explicit users, explicit groups, or a combination of users
  and groups. An empty target list has no implicit fallback and provisions nobody.
- Supports CalDAV calendars, CardDAV address books, or both when the endpoint
  exposes both capabilities.
- Treats both LDAP attributes as a per-user, per-profile opt-in. If either
  value is empty, that profile is skipped before DAV Connector is initialized.
- Can automatically enable all discovered calendars and/or all discovered
  address books. These switches are independent.
- Runs an immediate bounded harmonization after automatic collection
  selection, so newly selected collections are usable without an extra click.
- Supports single-user, selected-profile, targeted-profile, dry-run, and
  all-target operation through `occ`.
- Gives each profile its own background enable switch and interval; there is no
  global background switch.
- Leaves existing DAV services untouched when LDAP values or a profile are
  removed. Deprovisioning is always explicit.

## Profile examples

One LDAP attribute pair can be reused by separate calendar and contact
endpoints when they use the same external credentials:

| Profile | Login attribute | Secret attribute | Host |
|---|---|---|---|
| Ministry calendar | `msDS-cloudExtensionAttribute1` | `msDS-cloudExtensionAttribute2` | `caldav.yandex.ru` |
| Ministry contacts | `msDS-cloudExtensionAttribute1` | `msDS-cloudExtensionAttribute2` | `carddav.yandex.ru` |

A second external account should use a different attribute pair, for example
`msDS-cloudExtensionAttribute3/4`.

Attribute names are not hard-coded. Forests with Exchange schema extensions can
use `extensionAttribute1/2`; other installations can choose custom attributes.

For a provider that has no suitable LDAP attributes, select **One manually
entered account**. Enter its login and app password, then explicitly select the
Nextcloud user(s) or group(s) that should receive it. Selecting several users
shares the same external DAV account with all of them.

## Automatic collection selection

New profiles default to both automatic switches being off:

- with **Automatically connect all discovered calendars** off, users select
  only the calendars they need in DAV Connector;
- with **Automatically connect all discovered address books** off, users
  select address books manually;
- when either switch is on, all collections of that type are selected and
  harmonized immediately.

The profile migrated from version 0.1.x preserves its previous automatic
calendar setting.

## Safety model

- Every profile's background schedule is disabled by default.
- Unknown DAV Connector versions fail closed.
- No direct writes are made to DAV Connector database tables.
- A replacement connection is validated before the old managed connection is
  removed.
- If initial automatic collection setup fails, a newly created replacement is
  rolled back.
- Secrets are never printed by this app. Manual secrets are encrypted with
  Nextcloud `ICrypto`; DAV Connector still stores the Basic-auth credential in
  its normal service configuration after provisioning.
- Plaintext LDAP attributes are only as private as their Active Directory ACL.
  Use app-specific passwords and restrict attribute read access where possible.

## Installation

Take a VM/LXC snapshot first, then extract the release so the final directory is:

```text
/var/www/nextcloud/apps/davc_ldap_provisioning/
```

Set ownership and enable:

```bash
chown -R www-data:www-data /var/www/nextcloud/apps/davc_ldap_provisioning
cd /var/www/nextcloud
sudo -u www-data php occ app:enable davc_ldap_provisioning
sudo -u www-data php occ davc-ldap:status
```

The UI is under:

```text
Administration settings → Additional settings → DAVC LDAP Provisioning
```

Do not enable a profile's schedule until it has passed a single-user dry run.

## Upgrade from 0.1.x or 0.2.x

The old single-profile app values are read as a virtual profile with ID
`default`. The legacy `managed_service_id` user key is retained, so an existing
DAV service is adopted without reconnection. Version 0.3.0 does not silently
target everyone during migration: every upgraded profile starts with an empty
target list and its per-profile schedule off. Choose users/groups (or explicitly
select all users) in the administration page before provisioning it again.

Before upgrading, back up:

- the `davc_ldap_provisioning` app directory;
- app/user configuration rows for `davc_ldap_provisioning`;
- current DAV Connector service and collection state.

## OCC commands

List configuration and compatibility:

```bash
sudo -u www-data php occ davc-ldap:status
sudo -u www-data php occ davc-ldap:profile:list
```

Create a contact profile:

```bash
sudo -u www-data php occ davc-ldap:profile:set ministry-contacts \
  --name="Ministry contacts" \
  --credential-source=ldap \
  --login-attribute=msDS-cloudExtensionAttribute1 \
  --secret-attribute=msDS-cloudExtensionAttribute2 \
  --user=USER \
  --host=carddav.yandex.ru \
  --port=443 \
  --path=/ \
  --https=1 \
  --auto-calendars=0 \
  --auto-contacts=1
```

Manual credentials are best entered in the HTTPS administration page. For
automation, the CLI also supports `--credential-source=static`,
`--static-login`, and `--static-secret`; be aware that a command-line secret can
remain in shell history.

Update only one setting:

```bash
sudo -u www-data php occ davc-ldap:profile:set ministry-contacts --auto-contacts=0
```

Test and provision one profile:

```bash
sudo -u www-data php occ davc-ldap:provision USER --profile=ministry-contacts --dry-run
sudo -u www-data php occ davc-ldap:provision USER --profile=ministry-contacts
```

Test all active profiles for one user:

```bash
sudo -u www-data php occ davc-ldap:provision USER --dry-run
```

All configured targets must only be processed after successful single-user tests:

```bash
sudo -u www-data php occ davc-ldap:provision --all --dry-run
sudo -u www-data php occ davc-ldap:provision --all
```

Delete profile configuration without touching an existing DAV service:

```bash
sudo -u www-data php occ davc-ldap:profile:delete ministry-contacts
```

## LDAP cache

Nextcloud caches LDAP attribute reads for `ldapCacheTTL` seconds. Immediately
after changing an attribute, a dry run can temporarily report
`ldap_attributes_missing`. Wait for the TTL or clear the LDAP cache by
re-saving the current TTL value with `ldap:set-config`; do not change login
filters or the username attribute.

## Background operation

After a single-user test passes, enable the schedule inside that profile and set
its interval (300-86400 seconds). Nextcloud cron must be configured normally. A
five-minute dispatcher checks which profiles are due; each active profile is
processed independently and only for its selected users/groups.

## Rollback

Disable only the companion app:

```bash
cd /var/www/nextcloud
sudo -u www-data php occ app:disable davc_ldap_provisioning
```

This does not remove any DAV service, calendar, or address book it previously
created. Restore the previous app directory or the VM snapshot for a full
application rollback.

## Current limitations

- Version 0.2.0 accepts only Nextcloud 34 and DAV Connector 1.1.x.
- DAV Connector internal PHP services are used because 1.1.x has no public
  provisioning API.
- Only Basic authentication is supported; OAuth-only providers are not.
- Automatic collection selection means a collection manually removed from a
  profile can be selected again on a later provisioning run while its automatic
  switch remains on.
- Removing LDAP values or deleting a profile does not automatically disconnect
  existing DAV services.

## License

AGPL-3.0-or-later. See [LICENSE](LICENSE).
