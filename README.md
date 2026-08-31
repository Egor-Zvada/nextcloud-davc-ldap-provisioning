# DAVC LDAP Provisioning

Companion app for **Nextcloud 34** and **DAV Connector 1.1.x**. It provisions
multiple Basic-auth DAV accounts from LDAP attributes without modifying the
official `integration_davc` app.

The administration page uses Nextcloud localization: English is the source
language and a complete Russian translation is included.

## What it does

- Stores up to 20 independent DAV profiles.
- Gives every profile its own name, LDAP login/secret attributes, DAV host,
  port, path and HTTP/HTTPS setting.
- Supports CalDAV calendars, CardDAV address books, or both when the endpoint
  exposes both capabilities.
- Treats both LDAP attributes as a per-user, per-profile opt-in. If either
  value is empty, that profile is skipped before DAV Connector is initialized.
- Can automatically enable all discovered calendars and/or all discovered
  address books. These switches are independent.
- Runs an immediate bounded harmonization after automatic collection
  selection, so newly selected collections are usable without an extra click.
- Supports single-user, selected-profile, all-profile, dry-run, and all-user
  operation through `occ`.
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

- Background provisioning is disabled by default.
- Unknown DAV Connector versions fail closed.
- No direct writes are made to DAV Connector database tables.
- A replacement connection is validated before the old managed connection is
  removed.
- If initial automatic collection setup fails, a newly created replacement is
  rolled back.
- Secrets are never printed by this app. DAV Connector still stores the
  Basic-auth credential in its normal service configuration.
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

Do not enable background provisioning until each profile has passed a
single-user dry run.

## Upgrade from 0.1.x

Version 0.2.0 reads the old single-profile app values as a virtual profile with
ID `default`. It deliberately keeps the legacy `managed_service_id` user key,
so the existing DAV service is adopted without reconnection. The profile list
is persisted in the new JSON format the first time settings are saved.

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
  --login-attribute=msDS-cloudExtensionAttribute1 \
  --secret-attribute=msDS-cloudExtensionAttribute2 \
  --host=carddav.yandex.ru \
  --port=443 \
  --path=/ \
  --https=1 \
  --auto-calendars=0 \
  --auto-contacts=1
```

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

All users must only be processed after successful single-user tests:

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

After single-user tests pass, enable background provisioning in the admin UI.
Nextcloud cron must be configured normally. Every active profile is processed
independently; one missing attribute pair does not affect other profiles.

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
