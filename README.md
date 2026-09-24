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
- Displays connected collections as `profile name: remote collection name`
  instead of DAV Connector's default `DavC:` prefix. The remote collection is
  not renamed.
- Can assign one local Nextcloud color to all calendars connected by a profile.
  Existing profiles keep their provider/default colors until this option is enabled.
- Provides an **Apply now** button for each profile. It provisions an enabled
  profile or disconnects every account managed by a disabled profile.
- Supports single-user, selected-profile, targeted-profile, dry-run, and
  all-target operation through `occ`.
- Gives each profile its own background enable switch and interval; there is no
  global background switch.
- Leaves existing DAV services untouched when LDAP values or a profile are
  deleted. Disabling and applying a profile is the explicit, reversible
  deprovisioning operation.

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

## Collection names and immediate apply

The profile name is also the local display-name prefix. For example, a profile
named `Ministry` and a remote calendar named `Personal events` are displayed in
Nextcloud as `Ministry: Personal events`. The same rule applies to connected
address books. This only changes DAV Connector's local collection label; the
external DAV server is not modified and the collection remains an external,
non-shareable DAV Connector collection.

Each profile can optionally apply one selected color to all of its calendars.
The color is stored only in DAV Connector's local collection metadata. It does
not change the remote calendar or its color for users outside this Nextcloud.

Use **Apply now** on a profile card to reconcile it immediately without waiting
for its schedule. Saving an enabled/disabled state change also applies that
existing profile immediately:

- enabled connects or updates the selected users and groups;
- disabled disconnects all DAV Connector services previously managed by that
  profile, including users later removed from its target list.

Disconnecting removes DAV Connector's local cache and correlations. Remote
calendars, address books, events, and contacts remain on the provider and are
restored in Nextcloud when the profile is enabled and applied again.

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

Apply the desired state of one whole profile immediately (connect if enabled,
disconnect if disabled):

```bash
sudo -u www-data php occ davc-ldap:profile:apply ministry-contacts
```

Set or disable the local calendar color from the command line:

```bash
sudo -u www-data php occ davc-ldap:profile:set ministry-calendar \
  --use-calendar-color=1 --calendar-color='#0082c9'
sudo -u www-data php occ davc-ldap:profile:apply ministry-calendar
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

- Version 0.4.1 accepts only Nextcloud 34 and DAV Connector 1.1.x.
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
